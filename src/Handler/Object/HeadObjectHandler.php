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
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\MissingObjectListVisibility;
use OpsFour\S3Server\Http\RequestHeaderValues;
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
        $includeChecksums = S3ResponseHeaders::checksumModeEnabled(
            RequestHeaderValues::singleton($request, 'x-amz-checksum-mode'),
        );
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        if (array_key_exists('partNumber', $queryParams)) {
            throw new NotImplementedException('HeadObject partNumber is not supported.');
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
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            if ($versionId !== null) {
                throw new NoSuchVersionException();
            }

            $denyMissingObject = ! MissingObjectListVisibility::canList($this->metadata, $request, $bucket);
            // The list-visibility check may yield while the bucket is reused.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            if ($denyMissingObject) {
                throw new \OpsFour\S3Server\Exception\AccessDeniedException();
            }
            throw new NoSuchKeyException();
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // Delete markers: return error with x-amz-delete-marker header.
        // With versionId → 405 Method Not Allowed; without → 404 Not Found.
        if ($objectInfo->isDeleteMarker) {
            $dmHeaders = [
                'x-amz-delete-marker' => 'true',
                'x-amz-version-id' => $objectInfo->versionId ?? 'null',
            ];

            if ($versionId !== null) {
                $dmHeaders['Last-Modified'] = $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T');
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
        $emitNullVersion = $objectInfo->versionId === null
            && $this->metadata->getBucketVersioning($bucket) !== '';
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        ConditionalHeaderEvaluator::evaluate($request, $objectInfo, $emitNullVersion);

        // 4. Handle Range header for Content-Length calculation.
        // S3 ranged HEAD changes only Content-Length when satisfiable.
        $rangeHeader = RequestHeaderValues::combined($request, 'range');
        $contentLength = $objectInfo->size;
        $isPartialContent = false;

        if ($rangeHeader !== null) {
            $range = self::parseRangeHeader($rangeHeader, $objectInfo->size);

            if ($range !== null) {
                $contentLength = $range['end'] - $range['start'] + 1;
                $isPartialContent = true;
            }
        }

        // 5. Build response headers (same as GetObject, no body).
        $headers = S3ResponseHeaders::build(
            $objectInfo,
            $contentLength,
            $emitNullVersion,
            $includeChecksums && ! $isPartialContent,
        );
        $headers = S3ResponseHeaders::withAuthenticatedOverrides(
            $headers,
            $queryParams,
            $ownerId !== null && $ownerId !== '',
        );
        // Reusing a large pool of concurrent HTTP/1 HEAD sockets caused
        // subsequent AWS SDK requests to fail with cURL send error 55.
        // HTTP/2 forbids the hop-by-hop Connection header.
        if (str_starts_with($request->getProtocolVersion(), '1.')) {
            $headers['Connection'] = 'close';
        }

        $request->setAttribute('s3.objectSize', $objectInfo->size);

        // Versioning metadata can yield after the first generation check.
        // Never return a previous owner's headers after bucket-name reuse.
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        return new Response(
            status: 200,
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
            $suffixText = substr($rangeSpec, 1);
            if ($suffixText === '' || !ctype_digit($suffixText)) {
                return null;
            }
            $suffix = (int) $suffixText;

            if ($suffix <= 0 || $objectSize === 0) {
                throw self::invalidRange($objectSize);
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

        if (count($parts) !== 2 || $parts[0] === ''
            || !ctype_digit($parts[0])
            || ($parts[1] !== '' && !ctype_digit($parts[1]))) {
            return null;
        }

        $start = (int) $parts[0];

        if ($start >= $objectSize) {
            throw self::invalidRange($objectSize);
        }

        if ($parts[1] === '') {
            return ['start' => $start, 'end' => $objectSize - 1];
        }

        $end = (int) $parts[1];

        if ($end >= $objectSize) {
            $end = $objectSize - 1;
        }

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

}
