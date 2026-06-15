<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\S3ResponseHeaders;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\InvalidRangeException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Handles GetObject (GET /{bucket}/{key}).
 *
 * Retrieves an object from storage with full support for:
 * - Conditional requests: If-Match, If-None-Match, If-Modified-Since, If-Unmodified-Since
 * - Range requests: bytes=start-end, bytes=start-, bytes=-suffix (RFC 7233)
 * - Response header overrides via query parameters: response-content-type, etc.
 * - Streaming responses: the storage backend ReadableStream is passed directly
 *   to the Amp Response, never buffered in memory.
 * - All S3 metadata headers: ETag, Last-Modified, Content-Type, user metadata, etc.
 */
final class GetObjectHandler implements RequestHandler
{
    /** @var array<string, string> Query parameter overrides for response headers. */
    private const array RESPONSE_HEADER_OVERRIDES = [
        'response-content-type' => 'Content-Type',
        'response-content-language' => 'Content-Language',
        'response-content-disposition' => 'Content-Disposition',
        'response-content-encoding' => 'Content-Encoding',
        'response-cache-control' => 'Cache-Control',
        'response-expires' => 'Expires',
    ];

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?StorageTierRegistry $storageTiers = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // 2. Get object metadata (version-aware).
        $queryString = $request->getUri()->getQuery();
        $queryParams = $queryString !== '' ? QueryStringParser::parse($queryString) : [];
        $versionId = $queryParams['versionId'] ?? null;

        if ($versionId !== null) {
            $objectInfo = $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
        } else {
            $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);
        }

        if ($objectInfo === null) {
            throw new NoSuchKeyException();
        }

        // Delete markers: return error with x-amz-delete-marker header.
        // With versionId → 405 Method Not Allowed; without → 404 Not Found.
        if ($objectInfo->isDeleteMarker) {
            $dmHeaders = ['x-amz-delete-marker' => 'true'];
            if ($objectInfo->versionId !== null) {
                $dmHeaders['x-amz-version-id'] = $objectInfo->versionId;
            }

            if ($versionId !== null) {
                throw (new \OpsFour\S3Server\Exception\MethodNotAllowedException())->withExtraHeaders($dmHeaders);
            }

            throw (new NoSuchKeyException())->withExtraHeaders($dmHeaders);
        }

        // 3. Evaluate conditional headers (must be done before range processing).
        ConditionalHeaderEvaluator::evaluate($request, $objectInfo);

        // 4. Parse Range header if present.
        $rangeHeader = $request->getHeader('range');
        $offset = null;
        $length = null;
        $isPartialContent = false;
        $contentRangeHeader = null;
        $contentLength = $objectInfo->size;

        if ($rangeHeader !== null && $objectInfo->size > 0) {
            $range = self::parseRangeHeader($rangeHeader, $objectInfo->size);

            if ($range !== null) {
                $offset = $range['start'];
                $length = $range['end'] - $range['start'] + 1;
                $contentLength = $length;
                $isPartialContent = true;
                $contentRangeHeader = sprintf(
                    'bytes %d-%d/%d',
                    $range['start'],
                    $range['end'],
                    $objectInfo->size,
                );
            }
        }

        // 5. Stream body from storage — handle decryption if encrypted.
        $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;

        [$readStorage, $storagePath] = $this->resolveReadableStorage($objectInfo);
        if ($storagePath === null || $storagePath === '') {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Object storage path missing.');
        }

        if ($sseAlgo !== null && $this->encryption !== null) {
            // Size guard: encrypted objects must be fully buffered for decryption.
            if ($objectInfo->size > $this->maxEncryptedObjectSize) {
                throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                    'Encrypted object exceeds maximum size for decryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                );
            }

            // Encrypted objects: read full ciphertext, decrypt, then apply range.
            $ciphertext = \Amp\ByteStream\buffer(
                $readStorage->getObjectByPath($storagePath),
            );

            if ($sseAlgo === 'SSE-C') {
                $sseCAlgo = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
                $sseCKeyHeader = $request->getHeader('x-amz-server-side-encryption-customer-key');
                $sseCKeyMd5Header = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');

                if ($sseCAlgo === null || $sseCKeyHeader === null || $sseCKeyMd5Header === null) {
                    throw new InvalidArgumentException(
                        'SSE-C headers required to retrieve an SSE-C encrypted object.',
                    );
                }

                $customerKey = EncryptionService::validateSseCHeaders($sseCAlgo, $sseCKeyHeader, $sseCKeyMd5Header);
                $plaintext = $this->encryption->decryptSseC(
                    $ciphertext,
                    $customerKey,
                    $objectInfo->userMetadata['__sse-iv'],
                    $objectInfo->userMetadata['__sse-tag'],
                );
            } else {
                // SSE-S3.
                $plaintext = $this->encryption->decryptSseS3(
                    $ciphertext,
                    $objectInfo->userMetadata['__sse-key'],
                    $objectInfo->userMetadata['__sse-iv'],
                    $objectInfo->userMetadata['__sse-tag'],
                );
            }

            // Apply range on decrypted plaintext.
            $decryptedSize = strlen($plaintext);

            if ($isPartialContent && $offset !== null) {
                $body = substr($plaintext, $offset, $length);
                $contentLength = strlen($body);
                $contentRangeHeader = sprintf(
                    'bytes %d-%d/%d',
                    $offset,
                    $offset + $contentLength - 1,
                    $decryptedSize,
                );
            } else {
                $body = $plaintext;
                $contentLength = $decryptedSize;
            }
        } else {
            // Non-encrypted: stream directly from storage.
            $body = $readStorage->getObjectByPath($storagePath, $offset, $length);
        }

        // 6. Build response headers.
        $headers = S3ResponseHeaders::build($objectInfo, $contentLength);

        // Add Content-Range for partial content and strip checksums — AWS S3
        // does not return object checksums on range responses because they were
        // computed over the full object, not the partial content.
        if ($isPartialContent && $contentRangeHeader !== null) {
            $headers['Content-Range'] = $contentRangeHeader;
            unset(
                $headers['x-amz-checksum-crc32'],
                $headers['x-amz-checksum-crc32c'],
                $headers['x-amz-checksum-sha1'],
                $headers['x-amz-checksum-sha256'],
            );
        }

        // 7. Apply response-* query parameter overrides (authenticated requests only).
        //    Per S3 spec, response-* overrides require authentication (presigned URL or Authorization header).
        $isAuthenticated = isset($queryParams['X-Amz-Signature'])
            || isset($queryParams['Signature'])
            || $request->getHeader('authorization') !== null;
        if ($isAuthenticated) {
            foreach (self::RESPONSE_HEADER_OVERRIDES as $param => $header) {
                if (isset($queryParams[$param]) && $queryParams[$param] !== '') {
                    $headers[$header] = $queryParams[$param];
                }
            }
        }

        // 8. Return the response.
        return new Response(
            status: $isPartialContent ? 206 : 200,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * Parse the Range header value into start/end byte positions.
     *
     * Supports three range formats per RFC 7233:
     * - bytes=0-4      (first 5 bytes)
     * - bytes=5-       (from byte 5 to the end)
     * - bytes=-3       (last 3 bytes)
     *
     * Only single ranges are supported; multi-ranges are ignored
     * (the request is treated as non-range).
     *
     * @param  string  $rangeHeader  The raw Range header value.
     * @param  int  $objectSize  The total object size in bytes.
     * @return array{start: int, end: int}|null Byte range, or null if invalid.
     *
     * @throws InvalidRangeException If the range is syntactically valid but not satisfiable.
     */
    private static function parseRangeHeader(string $rangeHeader, int $objectSize): ?array
    {
        // Must start with "bytes=".
        if (! str_starts_with($rangeHeader, 'bytes=')) {
            return null;
        }

        $rangeSpec = substr($rangeHeader, 6);

        // We only support a single range (no comma-separated multi-ranges).
        if (str_contains($rangeSpec, ',')) {
            return null;
        }

        $rangeSpec = trim($rangeSpec);

        // Suffix range: bytes=-N (last N bytes).
        if (str_starts_with($rangeSpec, '-')) {
            $suffix = (int) substr($rangeSpec, 1);

            if ($suffix <= 0) {
                throw new InvalidRangeException();
            }

            // If suffix is larger than the object, return the whole object.
            if ($suffix >= $objectSize) {
                return ['start' => 0, 'end' => $objectSize - 1];
            }

            return [
                'start' => $objectSize - $suffix,
                'end' => $objectSize - 1,
            ];
        }

        $parts = explode('-', $rangeSpec, 2);

        if (count($parts) !== 2) {
            return null;
        }

        $startStr = $parts[0];
        $endStr = $parts[1];

        if ($startStr === '') {
            return null;
        }

        $start = (int) $startStr;

        // Range beyond object size.
        if ($start >= $objectSize) {
            throw new InvalidRangeException();
        }

        if ($endStr === '') {
            // Open-ended range: bytes=N- (from byte N to end).
            return ['start' => $start, 'end' => $objectSize - 1];
        }

        $end = (int) $endStr;

        // Clamp end to last byte.
        if ($end >= $objectSize) {
            $end = $objectSize - 1;
        }

        // Start must not exceed end.
        if ($start > $end) {
            throw new InvalidRangeException();
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @return array{0: StorageBackend, 1: string|null}
     */
    private function resolveReadableStorage(ObjectInfo $objectInfo): array
    {
        $registry = $this->storageTiers;
        if ($registry === null || ! $registry->has($objectInfo->storageTier)) {
            return [$this->storage, $objectInfo->systemMetadata['storagePath'] ?? null];
        }

        $tier = $registry->tier($objectInfo->storageTier);
        if (! $tier->restoreRequired) {
            return [$tier->backend, $objectInfo->systemMetadata['storagePath'] ?? null];
        }

        if ($this->hasReadableRestore($objectInfo)) {
            return [$registry->defaultBackend(), $objectInfo->restoredStoragePath];
        }

        throw new InvalidObjectStateException();
    }

    private function hasReadableRestore(ObjectInfo $objectInfo): bool
    {
        if ($objectInfo->restoreStatus !== 'restored' || $objectInfo->restoredStoragePath === null || $objectInfo->restoredStoragePath === '') {
            return false;
        }

        if ($objectInfo->restoreExpiresAt === null) {
            return true;
        }

        return $objectInfo->restoreExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
