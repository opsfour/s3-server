<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidRangeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\S3ResponseHeaders;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles HeadObject (HEAD /{bucket}/{key}).
 *
 * Returns the same metadata headers as GetObject but without a response
 * body. Supports all conditional headers (If-Match, If-None-Match,
 * If-Modified-Since, If-Unmodified-Since) and range header validation.
 *
 * This is the standard way for S3 clients to retrieve object metadata
 * and check existence without downloading the object data.
 */
final class HeadObjectHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
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
        $versionId = self::parseVersionId($request);

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

        $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
        $customerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $sseAlgo === 'SSE-C',
            $objectInfo->userMetadata['__sse-customer-key-md5'] ?? null,
        );
        if ($sseAlgo !== 'SSE-C' && $customerKey !== null) {
            throw new InvalidArgumentException('SSE-C headers are not valid for this object.');
        }

        // 3. Evaluate conditional headers.
        ConditionalHeaderEvaluator::evaluate($request, $objectInfo);

        // 4. Handle Range header for Content-Length calculation.
        // HEAD requests report the content length that would be returned
        // by a corresponding GET request with the same Range header.
        $rangeHeader = $request->getHeader('range');
        $contentLength = $objectInfo->size;
        $status = 200;
        $range = null;

        if ($rangeHeader !== null && $objectInfo->size > 0) {
            $range = self::parseRangeHeader($rangeHeader, $objectInfo->size);

            if ($range !== null) {
                $contentLength = $range['end'] - $range['start'] + 1;
                $status = 206;
            }
        }

        // 5. Build response headers (same as GetObject, no body).
        $headers = S3ResponseHeaders::build($objectInfo, $contentLength);

        if ($status === 206 && $range !== null) {
            $headers['Content-Range'] = sprintf(
                'bytes %d-%d/%d',
                $range['start'],
                $range['end'],
                $objectInfo->size,
            );
        }

        return new Response(
            status: $status,
            headers: $headers,
        );
    }

    /**
     * Parse the Range header into start/end byte positions.
     *
     * @return array{start: int, end: int}|null Byte range, or null if invalid/unsupported.
     */
    private static function parseRangeHeader(string $rangeHeader, int $objectSize): ?array
    {
        if (! str_starts_with($rangeHeader, 'bytes=')) {
            return null;
        }

        $rangeSpec = substr($rangeHeader, 6);

        if (str_contains($rangeSpec, ',')) {
            return null;
        }

        $rangeSpec = trim($rangeSpec);

        // Suffix range: bytes=-N.
        if (str_starts_with($rangeSpec, '-')) {
            $suffix = (int) substr($rangeSpec, 1);

            if ($suffix <= 0) {
                return null;
            }

            if ($suffix >= $objectSize) {
                return ['start' => 0, 'end' => $objectSize - 1];
            }

            return [
                'start' => $objectSize - $suffix,
                'end' => $objectSize - 1,
            ];
        }

        $parts = explode('-', $rangeSpec, 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }

        $start = (int) $parts[0];

        if ($start >= $objectSize) {
            throw new InvalidRangeException();
        }

        if ($parts[1] === '') {
            return ['start' => $start, 'end' => $objectSize - 1];
        }

        $end = (int) $parts[1];

        if ($end >= $objectSize) {
            $end = $objectSize - 1;
        }

        if ($start > $end) {
            throw new \OpsFour\S3Server\Exception\InvalidRangeException();
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Parse the versionId query parameter from the request URI.
     */
    private static function parseVersionId(Request $request): ?string
    {
        $queryString = $request->getUri()->getQuery();
        if ($queryString === '') {
            return null;
        }

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            if ($eqPos !== false) {
                $key = rawurldecode(substr($pair, 0, $eqPos));
                if ($key === 'versionId') {
                    $value = rawurldecode(substr($pair, $eqPos + 1));

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }
}
