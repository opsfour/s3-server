<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class CreateMultipartUploadHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?QuotaManager $quotas = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }
        $encryptionMode = EncryptionRequestResolver::resolveDestination(
            $request,
            $this->metadata,
            $bucket,
            $this->encryption,
        );
        (new ObjectLockRequestApplier($this->metadata))->validate($request, $bucket);
        $aclGrants = AclGrantResolver::fromHeaders($request, $ownerId, 'object', $bucketInfo->ownerId)
            ?? AclGrantResolver::privateAcl($ownerId);
        $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
        if (
            $publicAccessBlock !== null
            && $publicAccessBlock['blockPublicAcls']
            && AclGrantResolver::isPublic($aclGrants)
        ) {
            throw new \OpsFour\S3Server\Exception\AccessDeniedException(
                'Public ACLs are blocked by the bucket Public Access Block configuration.',
            );
        }

        $uploadId = bin2hex(random_bytes(16));
        $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
        $userMetadata = UserMetadataExtractor::extract($request);
        $userMetadata['__mpu-acl-grants'] = json_encode($aclGrants, JSON_THROW_ON_ERROR);

        // Preserve standard HTTP metadata for CompleteMultipartUpload.
        $contentEncoding = $request->getHeader('content-encoding');
        $contentDisposition = $request->getHeader('content-disposition');
        $cacheControl = $request->getHeader('cache-control');
        $storageClass = $request->getHeader('x-amz-storage-class') ?? 'STANDARD';

        if ($contentEncoding !== null) {
            $userMetadata['__mpu-content-encoding'] = $contentEncoding;
        }
        if ($contentDisposition !== null) {
            $userMetadata['__mpu-content-disposition'] = $contentDisposition;
        }
        if ($cacheControl !== null) {
            $userMetadata['__mpu-cache-control'] = $cacheControl;
        }
        if ($storageClass !== 'STANDARD') {
            $userMetadata['__mpu-storage-class'] = $storageClass;
        }
        foreach ([
            '__object-lock-mode' => 'x-amz-object-lock-mode',
            '__object-lock-retain-until-date' => 'x-amz-object-lock-retain-until-date',
            '__object-lock-legal-hold' => 'x-amz-object-lock-legal-hold',
        ] as $metadataKey => $header) {
            $value = $request->getHeader($header);
            if ($value !== null) {
                $userMetadata[$metadataKey] = $value;
            }
        }

        $taggingHeader = $request->getHeader('x-amz-tagging');
        if ($taggingHeader !== null) {
            $userMetadata['__mpu-tags'] = json_encode(
                ObjectTagValidator::parseHeader($taggingHeader),
                JSON_THROW_ON_ERROR,
            );
        }

        // Store encryption context in upload metadata for CompleteMultipartUpload.
        $sseAlgo = $encryptionMode;
        if ($encryptionMode !== null) {
            $sseCAlgorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
            $sseCKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
            $sseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');

            if ($encryptionMode === EncryptionRequestResolver::SSE_C) {
                \assert($sseCAlgorithm !== null && $sseCKey !== null && $sseCKeyMd5 !== null);
                // Validate SSE-C headers (throws on invalid).
                EncryptionService::validateSseCHeaders($sseCAlgorithm, $sseCKey, $sseCKeyMd5);
                $userMetadata['__sse-algorithm'] = 'SSE-C';
                $userMetadata['__sse-customer-key-md5'] = $sseCKeyMd5;
            } else {
                $userMetadata['__sse-algorithm'] = 'AES256';
            }
        }

        $this->metadata->transaction(function () use ($uploadId, $bucketInfo, $bucket, $key, $ownerId, $contentType, $userMetadata, $aclGrants): void {
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
            if (
                $publicAccessBlock !== null
                && $publicAccessBlock['blockPublicAcls']
                && AclGrantResolver::isPublic($aclGrants)
            ) {
                throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
            }
            $this->quotas?->assertCanCreateMultipart($ownerId, $bucket);
            $this->metadata->createMultipartUpload(
                uploadId: $uploadId,
                bucket: $bucket,
                key: $key,
                ownerId: $ownerId,
                contentType: $contentType,
                userMetadata: $userMetadata,
            );
        });

        $xml = XmlResponseBuilder::initiateMultipartUploadResult($bucket, $key, $uploadId);

        $responseHeaders = ['Content-Type' => 'application/xml'];

        // Encryption response headers.
        if ($sseAlgo === 'SSE-C') {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $sseCKeyMd5Val = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
            if ($sseCKeyMd5Val !== null) {
                $responseHeaders['x-amz-server-side-encryption-customer-key-MD5'] = $sseCKeyMd5Val;
            }
        } elseif ($sseAlgo === 'AES256') {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
            body: $xml,
        );
    }

}
