<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\ConfigWorkerKeyringCompatible;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class CreateMultipartUploadHandler implements RequestHandler
{
    private readonly ?StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?QuotaManager $quotas = null,
        private readonly ?StorageBackend $storage = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly bool $requireWorkerCompatibleSseS3 = false,
        private readonly ?AwsS3ForegroundObjectWriter $awsObjectWriter = null,
    ) {
        $this->storageTiers = $storageTiers ?? ($storage === null ? null : StorageTierRegistry::single($storage));
        if ($awsObjectWriter !== null) {
            $default = $this->storageTiers?->defaultTier()
                ?? throw new \InvalidArgumentException('AWS multipart creation requires a storage registry.');
            $backend = $default->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $metadata instanceof ObjectWriteIntentStore
                || ! $backend instanceof AwsS3StorageBackend
                || $storage !== $default->backend
                || $awsObjectWriter->storageTierName() !== $default->name
                || ($profile = $backend->admittedProfile()) === null
                || ! $awsObjectWriter->isBoundTo($backend, $profile, $metadata)) {
                throw new \InvalidArgumentException('AWS multipart creation must match the exact default AWS tier.');
            }
        }
    }

    public function handleRequest(Request $request): Response
    {
        $ownerId = (string) $request->getAttribute('ownerId');
        if ($ownerId === '') {
            throw new AccessDeniedException('Anonymous multipart uploads are not supported.');
        }
        if ($request->getHeader('x-amz-checksum-algorithm') !== null
            || $request->getHeader('x-amz-checksum-type') !== null) {
            throw new NotImplementedException(
                'Multipart additional checksums are not supported.',
            );
        }
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');

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
        if ($this->requireWorkerCompatibleSseS3 && $encryptionMode === EncryptionRequestResolver::SSE_S3
            && (! $this->encryption instanceof ConfigWorkerKeyringCompatible
                || $this->encryption->configWorkerKeyringFingerprint() === null)) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException(
                'Guarded SSE-S3 multipart creation requires the injected worker-compatible Config keyring.',
            );
        }
        $writeStorage = $this->storageTiers?->defaultBackend() ?? $this->storage;
        if ($encryptionMode !== null && $writeStorage !== null && $this->awsObjectWriter === null
            && (MultipartPartWriteCapabilities::storage($writeStorage) === null
                || MultipartPartWriteCapabilities::metadata($this->metadata) === null)) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'Encrypted multipart uploads require durable planned-part storage and metadata backends.',
            );
        }
        if (! $this->metadata instanceof MultipartUploadTierStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'Multipart upload creation requires tier-aware metadata persistence.',
            );
        }
        $tierStore = $this->metadata;
        // Direct handler construction without a storage registry retains the
        // historical STANDARD tier. Registered runtimes pass their actual tier.
        $storageTier = $this->storageTiers?->defaultTier()->name ?? 'STANDARD';
        $objectLockConfig = $this->metadata->getObjectLockConfig($bucket);
        ObjectLockConfigurationSnapshot::capture($request, $objectLockConfig);
        (new ObjectLockRequestApplier($this->metadata))->validate($request, $bucket);
        $ownershipDecision = BucketOwnership::resolveObjectWrite(
            $request,
            BucketOwnership::effectiveMode($this->metadata, $bucket),
            $ownerId,
            $bucketInfo->ownerId,
        );
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
        $userMetadata['__mpu-part-format'] = EncryptedMultipartPartCodec::PLAINTEXT_FORMAT;
        $userMetadata['__mpu-acl-grants'] = json_encode($aclGrants, JSON_THROW_ON_ERROR);
        $userMetadata[MultipartDestinationOwnershipSnapshot::METADATA_KEY]
            = MultipartDestinationOwnershipSnapshot::fromDecision(
                $ownershipDecision,
                $request->getHeader('x-amz-acl'),
            )->encode();
        foreach (['x-amz-acl', 'x-amz-grant-read', 'x-amz-grant-write', 'x-amz-grant-full-control', 'x-amz-grant-read-acp', 'x-amz-grant-write-acp'] as $aclHeader) {
            if ($request->getHeader($aclHeader) !== null) {
                $userMetadata['__mpu-acl-explicit'] = '1';
                break;
            }
        }
        if (($cannedAcl = $request->getHeader('x-amz-acl')) !== null) {
            $userMetadata['__mpu-acl-header'] = $cannedAcl;
        }
        $policyConditionHeaders = [];
        foreach (['x-amz-acl', 'x-amz-server-side-encryption', 'x-amz-storage-class'] as $header) {
            $value = RequestHeaderValues::singleton($request, $header);
            if ($value !== null) {
                $policyConditionHeaders[$header] = $value;
            }
        }
        $userMetadata[PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY]
            = PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION;
        $userMetadata[PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY]
            = json_encode($policyConditionHeaders, JSON_THROW_ON_ERROR);
        $userMetadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY]
            = ObjectLockConfigurationSnapshot::fingerprint($objectLockConfig);

        // Preserve standard HTTP metadata for CompleteMultipartUpload.
        $contentEncoding = RequestHeaderValues::combined($request, 'content-encoding');
        $contentLanguage = RequestHeaderValues::combined($request, 'content-language');
        $contentDisposition = $request->getHeader('content-disposition');
        $cacheControl = RequestHeaderValues::combined($request, 'cache-control');
        $expires = $request->getHeader('expires');
        $storageClass = \OpsFour\S3Server\Http\StorageClassValidator::requested($request->getHeader('x-amz-storage-class'));

        if ($contentEncoding !== null) {
            $userMetadata['__mpu-content-encoding'] = $contentEncoding;
        }
        if ($contentLanguage !== null) {
            $userMetadata['__mpu-content-language'] = $contentLanguage;
        }
        if ($contentDisposition !== null) {
            $userMetadata['__mpu-content-disposition'] = $contentDisposition;
        }
        if ($cacheControl !== null) {
            $userMetadata['__mpu-cache-control'] = $cacheControl;
        }
        if ($expires !== null && $expires !== '') {
            $userMetadata['__expires'] = $expires;
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
            $userMetadata['__mpu-part-format'] = EncryptedMultipartPartCodec::FORMAT;
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

        $this->metadata->transaction(function () use ($uploadId, $bucketInfo, $bucket, $key, $ownerId, $contentType, $userMetadata, $aclGrants, $ownershipDecision, $request, $encryptionMode, $tierStore, $storageTier): void {
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            ObjectLockConfigurationSnapshot::assertCurrent(
                $request,
                $this->metadata->getObjectLockConfig($bucket),
            );
            $bucketEncryption = $this->metadata->getBucketEncryption($bucket);
            \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
            if ($encryptionMode === null && $bucketEncryption !== null) {
                throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                    'Bucket default encryption changed while multipart upload was being initiated.',
                );
            }
            (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            BucketOwnership::assertObjectWriteDecisionCurrent(
                $this->metadata,
                $bucket,
                $request,
                $ownerId,
                $bucketInfo->ownerId,
                $ownershipDecision,
            );
            $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
            if (
                $publicAccessBlock !== null
                && $publicAccessBlock['blockPublicAcls']
                && AclGrantResolver::isPublic($aclGrants)
            ) {
                throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
            }
            $this->quotas?->assertCanCreateMultipart($ownerId, $bucket);
            $tierStore->createMultipartUploadWithTier(
                uploadId: $uploadId,
                bucket: $bucket,
                key: $key,
                ownerId: $ownerId,
                storageTier: $storageTier,
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
