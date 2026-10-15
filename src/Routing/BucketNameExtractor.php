<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidBucketNameException;

/**
 * Extracts the bucket name from an S3 request.
 *
 * Supports two addressing modes:
 *
 * 1. **Path-style** (default): The bucket is the first path segment.
 *    Example: `GET /mybucket/mykey` -> bucket = "mybucket"
 *
 * 2. **Virtual-hosted style** (when $baseDomain is set): The bucket is
 *    the subdomain prefix of the Host header.
 *    Example: `Host: mybucket.s3.example.com` with baseDomain `s3.example.com`
 *    -> bucket = "mybucket"
 *    If the Host matches the baseDomain exactly, falls back to path-style.
 */
final class BucketNameExtractor
{
    private const int MAX_PERCENT_DECODE_DEPTH = 4;

    /**
     * Extract the bucket name from the request.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  string|null  $baseDomain  The base domain for virtual-hosted style addressing
     *                                   (e.g., "s3.example.com"). Null for path-style only.
     * @return string|null The bucket name, or null if the request targets the service root.
     */
    public static function extract(Request $request, ?string $baseDomain): ?string
    {
        $virtualBucket = self::extractVirtualHosted($request, $baseDomain);
        if ($virtualBucket !== null) {
            return $virtualBucket;
        }

        return self::extractPathStyle($request)[0];
    }

    /** @return array{string|null, string|null} */
    public static function extractPathStyle(Request $request): array
    {
        // Split the encoded path before decoding either component. Decoding
        // first would let an encoded slash in the bucket segment redirect the
        // request to a different bucket and move the suffix into the key.
        $path = \ltrim($request->getUri()->getPath(), '/');

        if ($path === '') {
            return [null, null];
        }

        $slashPos = \strpos($path, '/');
        if ($slashPos === false) {
            return [self::decodePathBucket($path), null];
        }

        $bucket = self::decodePathBucket(\substr($path, 0, $slashPos));
        $key = \rawurldecode(\substr($path, $slashPos + 1));

        return [$bucket !== '' ? $bucket : null, $key];
    }

    public static function extractVirtualHosted(Request $request, ?string $baseDomain): ?string
    {
        if ($baseDomain !== null && $baseDomain !== '') {
            $host = $request->getHeader('host');

            if ($host !== null) {
                // Strip port number if present (e.g., "mybucket.s3.example.com:8080").
                $hostWithoutPort = \strtok($host, ':');
                if ($hostWithoutPort === false) {
                    $hostWithoutPort = $host;
                }

                $baseDomainLower = \strtolower(\rtrim($baseDomain, '.'));
                $hostLower = \strtolower(\rtrim($hostWithoutPort, '.'));

                // If the host is a subdomain of baseDomain, extract bucket from subdomain.
                // e.g., host = "mybucket.s3.example.com", baseDomain = "s3.example.com"
                // -> suffix = ".s3.example.com", bucket = "mybucket"
                $suffix = '.' . $baseDomainLower;

                if ($hostLower !== $baseDomainLower && \str_ends_with($hostLower, $suffix)) {
                    $bucket = \substr($hostLower, 0, \strlen($hostLower) - \strlen($suffix));

                    if ($bucket !== '') {
                        return $bucket;
                    }
                }

                // If host matches baseDomain exactly, fall through to path-style.
            }
        }

        return null;
    }

    private static function validatedPathBucket(string $name): string
    {
        // Removing decoded bytes would silently redirect the request to a
        // different bucket (for example, "victim%00" to "victim").
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new InvalidBucketNameException();
        }

        return $name;
    }

    private static function decodePathBucket(string $encoded): string
    {
        // A separator at any encoding depth could be interpreted differently
        // by a proxy and this server. Bucket names cannot contain either one,
        // so reject the request instead of changing its bucket/key boundary.
        $probe = $encoded;
        for ($depth = 0; $depth <= self::MAX_PERCENT_DECODE_DEPTH; ++$depth) {
            if (str_contains($probe, '/') || str_contains($probe, '\\')) {
                throw new InvalidBucketNameException();
            }
            $decoded = rawurldecode($probe);
            if ($decoded === $probe) {
                return self::validatedPathBucket(rawurldecode($encoded));
            }
            if ($depth === self::MAX_PERCENT_DECODE_DEPTH) {
                throw new InvalidBucketNameException();
            }
            $probe = $decoded;
        }

        throw new \LogicException('Unreachable percent-decoding state.');
    }
}
