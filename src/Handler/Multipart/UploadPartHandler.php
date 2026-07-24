<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

final class UploadPartHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';
        $partNumber = isset($queryParams['partNumber']) ? (int) $queryParams['partNumber'] : 0;

        if ($partNumber < 1 || $partNumber > 10000) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Part number must be between 1 and 10000.',
            );
        }

        // Verify upload exists and belongs to this owner.
        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }
        $uploadSseAlgorithm = $upload['user_metadata']['__sse-algorithm'] ?? null;
        $customerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $uploadSseAlgorithm === 'SSE-C',
            $upload['user_metadata']['__sse-customer-key-md5'] ?? null,
        );
        if ($uploadSseAlgorithm !== 'SSE-C' && $customerKey !== null) {
            throw new InvalidArgumentException('SSE-C headers are not valid for this multipart upload.');
        }

        // Write part to storage (computes all checksums during write).
        $writeResult = $this->storage->putPart($bucket, $key, $uploadId, $partNumber, $request->getBody());

        // Verify client-sent checksum against computed value.
        $checksumCrc32 = $request->getHeader('x-amz-checksum-crc32');
        $checksumCrc32c = $request->getHeader('x-amz-checksum-crc32c');
        $checksumSha1 = $request->getHeader('x-amz-checksum-sha1');
        $checksumSha256 = $request->getHeader('x-amz-checksum-sha256');

        $clientChecksums = array_filter([
            'crc32' => $checksumCrc32,
            'crc32c' => $checksumCrc32c,
            'sha1' => $checksumSha1,
            'sha256' => $checksumSha256,
        ]);

        if (count($clientChecksums) > 1) {
            $this->deleteUncommittedPart($bucket, $writeResult->path);
            throw new InvalidArgumentException('Only one x-amz-checksum-* header may be specified.');
        }

        foreach ($clientChecksums as $algo => $clientValue) {
            $computedValue = match ($algo) {
                'crc32' => $writeResult->crc32Base64,
                'crc32c' => $writeResult->crc32cBase64,
                'sha1' => $writeResult->sha1Base64,
                'sha256' => $writeResult->sha256Base64,
            };

            if ($computedValue === null) {
                $this->deleteUncommittedPart($bucket, $writeResult->path);
                throw new \OpsFour\S3Server\Exception\InternalErrorException(
                    "Storage backend did not compute checksum for algorithm: {$algo}",
                );
            }

            if (!hash_equals($computedValue, $clientValue)) {
                $this->deleteUncommittedPart($bucket, $writeResult->path);
                throw new BadDigestException(
                    "Checksum mismatch: client sent {$clientValue}, computed {$computedValue}",
                );
            }
        }

        // Content-Length mismatch detection — truncated parts produce corrupt assembled objects.
        $contentSha = $request->getHeader('x-amz-content-sha256');
        $isAwsChunked = in_array($contentSha, [
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
            'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
        ], true);
        $declaredLength = $isAwsChunked
            ? $request->getHeader('x-amz-decoded-content-length')
            : $request->getHeader('content-length');
        if ($declaredLength !== null && (int) $declaredLength !== $writeResult->size) {
            $this->deleteUncommittedPart($bucket, $writeResult->path);
            throw new \OpsFour\S3Server\Exception\IncompleteBodyException(
                'Content-Length mismatch: declared ' . $declaredLength . ', received ' . $writeResult->size,
            );
        }

        $etag = '"' . $writeResult->md5Hex . '"';

        // Build response headers — include verified checksum if client sent one.
        $responseHeaders = ['ETag' => $etag];
        foreach ($clientChecksums as $algo => $_) {
            $computed = match ($algo) {
                'crc32' => $writeResult->crc32Base64,
                'crc32c' => $writeResult->crc32cBase64,
                'sha1' => $writeResult->sha1Base64,
                'sha256' => $writeResult->sha256Base64,
            };
            if ($computed !== null) {
                $responseHeaders['x-amz-checksum-' . $algo] = $computed;
            }
        }

        // Store part metadata.
        $previousPartPath = null;
        try {
            $this->metadata->transaction(function () use ($ownerId, $bucketInfo, $bucket, $key, $uploadId, $partNumber, $etag, $writeResult, &$previousPartPath): void {
                \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                $activeUpload = $this->metadata->getMultipartUpload($uploadId);
                if (
                    $activeUpload === null
                    || $activeUpload['bucket'] !== $bucket
                    || $activeUpload['key_name'] !== $key
                    || $activeUpload['owner_id'] !== $ownerId
                ) {
                    throw new NoSuchUploadException();
                }
                foreach ($this->metadata->getParts($uploadId) as $part) {
                    if ($part['part_number'] === $partNumber) {
                        $previousPartPath = $part['storage_path'];
                        break;
                    }
                }
                $this->quotas?->assertCanWritePart(
                    $ownerId,
                    $bucket,
                    $uploadId,
                    $partNumber,
                    $writeResult->size,
                );
                $this->metadata->putPart($uploadId, $partNumber, $etag, $writeResult->size, $writeResult->path);
            });
        } catch (\Throwable $e) {
            $this->deleteUncommittedPart($bucket, $writeResult->path);
            throw $e;
        }

        if ($previousPartPath !== null && $previousPartPath !== $writeResult->path) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run(
                $this->metadata,
                $this->storage,
                $bucket,
                $this->storageTiers->defaultTier()->name,
                $previousPartPath,
            );
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
        );
    }

    private function deleteUncommittedPart(string $bucket, string $path): void
    {
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $this->storage,
            $bucket,
            $this->storageTiers->defaultTier()->name,
            $path,
        );
    }
}
