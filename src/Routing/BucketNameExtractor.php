<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\Request;

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
        // Try virtual-hosted style first when baseDomain is configured.
        if ($baseDomain !== null && $baseDomain !== '') {
            $host = $request->getHeader('host');

            if ($host !== null) {
                // Strip port number if present (e.g., "mybucket.s3.example.com:8080").
                $hostWithoutPort = \strtok($host, ':');
                if ($hostWithoutPort === false) {
                    $hostWithoutPort = $host;
                }

                $baseDomainLower = \strtolower($baseDomain);
                $hostLower = \strtolower($hostWithoutPort);

                // If the host is a subdomain of baseDomain, extract bucket from subdomain.
                // e.g., host = "mybucket.s3.example.com", baseDomain = "s3.example.com"
                // -> suffix = ".s3.example.com", bucket = "mybucket"
                $suffix = '.'.$baseDomainLower;

                if ($hostLower !== $baseDomainLower && \str_ends_with($hostLower, $suffix)) {
                    $bucket = \substr($hostWithoutPort, 0, \strlen($hostWithoutPort) - \strlen($suffix));

                    if ($bucket !== '') {
                        return $bucket;
                    }
                }

                // If host matches baseDomain exactly, fall through to path-style.
            }
        }

        // Path-style: extract bucket from the first path segment.
        $path = $request->getUri()->getPath();
        $path = \ltrim($path, '/');

        if ($path === '') {
            return null;
        }

        // The first segment before '/' is the bucket name.
        $slashPos = \strpos($path, '/');

        if ($slashPos === false) {
            return self::sanitize(\rawurldecode($path));
        }

        $bucket = \substr($path, 0, $slashPos);

        return $bucket !== '' ? self::sanitize(\rawurldecode($bucket)) : null;
    }

    /**
     * Strip control characters (null bytes, newlines, etc.) from a decoded bucket name.
     */
    private static function sanitize(string $name): ?string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]/', '', $name);

        return $clean !== '' ? $clean : null;
    }
}
