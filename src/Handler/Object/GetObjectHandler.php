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
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\MissingObjectListVisibility;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Http\S3ResponseHeaders;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\InvalidRangeException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\BucketGenerationGuard;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\LimitedReadableStream;
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
    /** Amazon S3 requires ranged downloads for objects larger than 5 TiB. */
    private const int MAX_SINGLE_GET_BYTES = 5_497_558_138_880;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?StorageTierRegistry $storageTiers = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $includeChecksums = S3ResponseHeaders::checksumModeEnabled(
            RequestHeaderValues::singleton($request, 'x-amz-checksum-mode'),
        );
        $queryString = $request->getUri()->getQuery();
        $queryParams = $queryString !== '' ? QueryStringParser::parse($queryString) : [];
        if (array_key_exists('partNumber', $queryParams)) {
            throw new NotImplementedException('GetObject partNumber is not supported.');
        }
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // 2. Get object metadata (version-aware).
        $versionId = $queryParams['versionId'] ?? null;

        $objectInfo = AuthorizedObjectSnapshot::resolve($this->metadata, $request, $bucket, $key, $versionId);

        if ($objectInfo === null) {
            BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            if ($versionId !== null) {
                throw new NoSuchVersionException();
            }

            $canList = MissingObjectListVisibility::canList($this->metadata, $request, $bucket);
            BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            if (! $canList) {
                throw new \OpsFour\S3Server\Exception\AccessDeniedException();
            }
            throw new NoSuchKeyException();
        }
        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // Delete markers: return error with x-amz-delete-marker header.
        // With versionId → 405 Method Not Allowed; without → 404 Not Found.
        if ($objectInfo->isDeleteMarker) {
            $dmHeaders = [
                'x-amz-delete-marker' => 'true',
                'x-amz-version-id' => $objectInfo->versionId ?? 'null',
            ];

            if ($versionId !== null) {
                $dmHeaders['Last-Modified'] = S3ResponseHeaders::httpDate($objectInfo->lastModified);
                throw (new \OpsFour\S3Server\Exception\MethodNotAllowedException(
                    allowedMethods: ['DELETE'],
                ))->withExtraHeaders(['Allow' => 'DELETE', ...$dmHeaders]);
            }

            throw (new NoSuchKeyException())->withExtraHeaders($dmHeaders);
        }

        // SSE-C headers are required even if a conditional request would
        // otherwise return 304/412 without reading the object body.
        $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
        $customerKey = \OpsFour\S3Server\Encryption\EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $sseAlgo === 'SSE-C',
            $objectInfo->userMetadata['__sse-customer-key-md5'] ?? null,
        );
        if ($sseAlgo !== 'SSE-C' && $customerKey !== null) {
            throw new InvalidArgumentException('SSE-C headers are not valid for this object.');
        }

        // 3. Evaluate conditional headers (must be done before range processing).
        $emitNullVersion = $objectInfo->versionId === null
            && $this->metadata->getBucketVersioning($bucket) !== '';
        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        ConditionalHeaderEvaluator::evaluate($request, $objectInfo, $emitNullVersion);

        // 4. Parse Range header if present.
        $rangeHeader = RequestHeaderValues::combined($request, 'range');
        $offset = null;
        $length = null;
        $isPartialContent = false;
        $contentRangeHeader = null;
        $contentLength = $objectInfo->size;

        if ($rangeHeader !== null) {
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

        if (! $isPartialContent && $objectInfo->size > self::MAX_SINGLE_GET_BYTES) {
            throw new MethodNotAllowedException(
                'Objects larger than 5 TiB must be downloaded with a Range request.',
            );
        }

        // 5. Stream body from storage — handle decryption if encrypted.
        [$readStorage, $storagePath] = $this->resolveReadableStorage($objectInfo);
        if ($storagePath === null || $storagePath === '') {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Object storage path missing.');
        }

        if ($sseAlgo !== null) {
            \OpsFour\S3Server\Encryption\EncryptionRequestResolver::requireEncryptionService($this->encryption);
            \assert($this->encryption !== null);
            $bufferedWorkLock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
            $decryptionCancellation = null;
            try {
                // Size guard: encrypted objects must be fully buffered for decryption.
                if ($objectInfo->size > $this->maxEncryptedObjectSize) {
                    throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                        'Encrypted object exceeds maximum size for decryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                    );
                }

                // Encrypted objects: read full ciphertext, decrypt, then apply range.
                $ciphertext = self::bufferCiphertext(
                    $readStorage->getObjectByPath($storagePath),
                    $objectInfo->size,
                );

                if ($sseAlgo === 'SSE-C') {
                    \assert($customerKey !== null);
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

                if (strlen($plaintext) !== $objectInfo->size) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException(
                        'Decrypted object size does not match metadata.',
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
            } catch (\Amp\CancelledException $error) {
                $decryptionCancellation = $error;
                throw $error;
            } finally {
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                    $decryptionCancellation,
                    $bufferedWorkLock->release(...),
                );
            }
        } else {
            // A corrupt or custom backend must not send more bytes than the
            // Content-Length derived from committed object metadata. A short
            // backend read must fail instead of reporting a clean response.
            $body = new LimitedReadableStream(
                $readStorage->getObjectByPath($storagePath, $offset, $length ?? $objectInfo->size),
                $contentLength,
                requireExactLength: true,
            );
        }

        try {
            // 6. Build response headers. The versioning lookup can yield, so
            // keep the storage stream owned until the final generation check.
            $headers = S3ResponseHeaders::build(
                $objectInfo,
                $contentLength,
                $emitNullVersion,
                $includeChecksums && ! $isPartialContent,
            );

            // A range response never carries the checksum of the full object.
            if ($isPartialContent && $contentRangeHeader !== null) {
                $headers['Content-Range'] = $contentRangeHeader;
            }

            // 7. Apply response-* query parameter overrides only after the auth
            // middleware has resolved a real identity. A bare, unsigned
            // X-Amz-Signature query parameter must not enable overrides.
            $headers = S3ResponseHeaders::withAuthenticatedOverrides(
                $headers,
                $queryParams,
                $ownerId !== null && $ownerId !== '',
            );

            BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        } catch (\Throwable $error) {
            if ($body instanceof \Amp\ByteStream\ReadableStream) {
                try {
                    $body->close();
                } catch (\Throwable) {
                    // Keep the generation failure as the request outcome.
                }
            }
            throw $error;
        }

        // 8. Return the response.
        $request->setAttribute('s3.objectSize', $objectInfo->size);

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
            $suffixText = substr($rangeSpec, 1);
            if ($suffixText === '' || !ctype_digit($suffixText)) {
                return null;
            }
            $suffix = (int) $suffixText;

            if ($suffix <= 0 || $objectSize === 0) {
                throw self::invalidRange($objectSize);
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

        if ($startStr === '' || !ctype_digit($startStr) || ($endStr !== '' && !ctype_digit($endStr))) {
            return null;
        }

        $start = (int) $startStr;

        // Range beyond object size.
        if ($start >= $objectSize) {
            throw self::invalidRange($objectSize);
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
            throw self::invalidRange($objectSize);
        }

        return ['start' => $start, 'end' => $end];
    }

    private static function invalidRange(int $objectSize): InvalidRangeException
    {
        return (new InvalidRangeException())->withExtraHeaders([
            'Content-Range' => 'bytes */' . $objectSize,
        ]);
    }

    private static function bufferCiphertext(\Amp\ByteStream\ReadableStream $source, int $expectedSize): string
    {
        $chunks = [];
        $readBytes = 0;
        $cancellation = null;
        try {
            while (($chunk = $source->read()) !== null) {
                $chunkSize = strlen($chunk);
                if ($chunkSize === 0 || $chunkSize > $expectedSize - $readBytes) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored encrypted object size does not match metadata.');
                }
                $chunks[] = $chunk;
                $readBytes += $chunkSize;
            }
        } catch (\Amp\CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                $cancellation,
                $source->close(...),
            );
        }

        if ($readBytes !== $expectedSize) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored encrypted object size does not match metadata.');
        }

        return implode($chunks);
    }

    /**
     * @return array{0: StorageBackend, 1: string|null}
     */
    private function resolveReadableStorage(ObjectInfo $objectInfo): array
    {
        $registry = $this->storageTiers ?? StorageTierRegistry::single($this->storage);
        if (! $registry->has($objectInfo->storageTier)) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Object storage tier is not configured.');
        }

        $tier = $registry->tier($objectInfo->storageTier);
        if (! $tier->restoreRequired) {
            return [$tier->backend, $objectInfo->systemMetadata['storagePath'] ?? null];
        }

        if ($this->hasReadableRestore($objectInfo)) {
            return [\OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($objectInfo, $registry)->backend, $objectInfo->restoredStoragePath];
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
