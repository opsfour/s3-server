<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

final class UploadPartHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
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
            try {
                $this->storage->deleteObjectByPath($writeResult->path, $bucket);
            } catch (\Throwable) {
            }
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
                try {
                    $this->storage->deleteObjectByPath($writeResult->path, $bucket);
                } catch (\Throwable) {
                }
                throw new \OpsFour\S3Server\Exception\InternalErrorException(
                    "Storage backend did not compute checksum for algorithm: {$algo}",
                );
            }

            if (!hash_equals($computedValue, $clientValue)) {
                try {
                    $this->storage->deleteObjectByPath($writeResult->path, $bucket);
                } catch (\Throwable) {
                }
                throw new BadDigestException(
                    "Checksum mismatch: client sent {$clientValue}, computed {$computedValue}",
                );
            }
        }

        // Content-Length mismatch detection — truncated parts produce corrupt assembled objects.
        $declaredLength = $request->getHeader('content-length');
        if ($declaredLength !== null && (int) $declaredLength !== $writeResult->size) {
            try {
                $this->storage->deleteObjectByPath($writeResult->path, $bucket);
            } catch (\Throwable) {
            }
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
        try {
            $this->metadata->putPart($uploadId, $partNumber, $etag, $writeResult->size, $writeResult->path);
        } catch (\Throwable $e) {
            try {
                $this->storage->deleteObjectByPath($writeResult->path, $bucket);
            } catch (\Throwable) {
            }
            throw $e;
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
        );
    }
}
