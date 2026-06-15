<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class CreateMultipartUploadHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly ?EncryptionServiceInterface $encryption = null,
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

        $uploadId = bin2hex(random_bytes(16));
        $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
        $userMetadata = UserMetadataExtractor::extract($request);

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

        // Store encryption context in upload metadata for CompleteMultipartUpload.
        $sseAlgo = null;
        if ($this->encryption !== null) {
            $sseCAlgorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
            $sseCKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
            $sseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');

            if ($sseCAlgorithm !== null && $sseCKey !== null && $sseCKeyMd5 !== null) {
                // Validate SSE-C headers (throws on invalid).
                EncryptionService::validateSseCHeaders($sseCAlgorithm, $sseCKey, $sseCKeyMd5);
                $sseAlgo = 'SSE-C';
                $userMetadata['__sse-algorithm'] = 'SSE-C';
            } else {
                $sseHeader = $request->getHeader('x-amz-server-side-encryption');
                $applySSE = ($sseHeader === 'AES256');

                if (! $applySSE) {
                    $bucketEnc = $this->metadata->getBucketEncryption($bucket);
                    if ($bucketEnc !== null && ($bucketEnc['sseAlgorithm'] === 'AES256' || $bucketEnc['sseAlgorithm'] === 'aws:kms')) {
                        $applySSE = true;
                    }
                }

                if ($applySSE) {
                    $sseAlgo = 'AES256';
                    $userMetadata['__sse-algorithm'] = 'AES256';
                }
            }
        }

        $this->metadata->createMultipartUpload(
            uploadId: $uploadId,
            bucket: $bucket,
            key: $key,
            ownerId: $ownerId,
            contentType: $contentType,
            userMetadata: $userMetadata,
        );

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
