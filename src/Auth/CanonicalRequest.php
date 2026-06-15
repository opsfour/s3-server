<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/**
 * Builds the AWS SigV4 canonical request string.
 *
 * The canonical request is the deterministic representation of an HTTP request
 * used as input to the signing process. It normalizes URI encoding, query
 * parameter ordering, header formatting, and payload hashing per the AWS
 * Signature Version 4 specification.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sig-v4-header-based-auth.html
 */
final class CanonicalRequest
{
    /**
     * Build a canonical request string per AWS SigV4 specification.
     *
     * @param  string  $method  HTTP method (GET, PUT, POST, DELETE, HEAD, etc.).
     * @param  string  $uri  The request URI path (e.g., "/bucket/key").
     * @param  string  $queryString  The raw query string (without leading "?").
     * @param  array<string, list<string>>  $headers  All request headers as lowercase-name => values.
     * @param  array<string>  $signedHeaders  Lowercase header names included in the signature.
     * @param  string  $hashedPayload  Hex-encoded SHA-256 of the payload, or a literal
     *                                 like "UNSIGNED-PAYLOAD" or "STREAMING-AWS4-HMAC-SHA256-PAYLOAD".
     * @return string The canonical request string.
     */
    public static function build(
        string $method,
        string $uri,
        string $queryString,
        array $headers,
        array $signedHeaders,
        string $hashedPayload,
    ): string {
        $canonicalUri = self::canonicalizeUri($uri);
        $canonicalQueryString = self::canonicalizeQueryString($queryString);

        sort($signedHeaders);

        $canonicalHeaders = self::canonicalizeHeaders($headers, $signedHeaders);
        $signedHeadersString = implode(';', $signedHeaders);

        // The canonical request format per AWS spec:
        //   Method \n URI \n QueryString \n CanonicalHeaders \n SignedHeaders \n HashedPayload
        // Note: $canonicalHeaders already ends with \n (each header line has a trailing \n),
        // so the implode \n between it and $signedHeadersString produces exactly the required
        // blank line separator.
        return implode("\n", [
            $method,
            $canonicalUri,
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeadersString,
            $hashedPayload,
        ]);
    }

    /**
     * URI-encode each path component while preserving slashes.
     *
     * Per the AWS SigV4 spec for S3, the URI path is NOT normalized —
     * multiple consecutive slashes are preserved as-is. Each path segment
     * is individually decoded then re-encoded per AWS unreserved charset.
     */
    private static function canonicalizeUri(string $uri): string
    {
        // Empty URI becomes root.
        if ($uri === '' || $uri === '/') {
            return '/';
        }

        // Split into segments, decode then re-encode each per AWS spec, rejoin.
        // Decoding first ensures consistent encoding regardless of whether the
        // URI arrives already percent-encoded (e.g., %E6%97%A5 for CJK characters
        // or %20 for spaces) or as raw bytes.
        // For S3, do NOT collapse empty segments — double slashes in keys are valid.
        $segments = explode('/', $uri);
        $encoded = [];

        foreach ($segments as $segment) {
            $encoded[] = self::uriEncode(rawurldecode($segment));
        }

        $result = implode('/', $encoded);

        // Ensure leading slash.
        if (! str_starts_with($result, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    /**
     * Sort query parameters by key name (code point order), then by value.
     * URI-encode both keys and values.
     */
    private static function canonicalizeQueryString(string $queryString): string
    {
        if ($queryString === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $queryString) as $param) {
            if ($param === '') {
                continue;
            }

            // Split on first '=' only — value may contain '='.
            $eqPos = strpos($param, '=');
            if ($eqPos === false) {
                $key = $param;
                $value = '';
            } else {
                $key = substr($param, 0, $eqPos);
                $value = substr($param, $eqPos + 1);
            }

            // Decode first (they may already be encoded), then re-encode
            // per AWS specification to ensure consistent encoding.
            $pairs[] = [
                self::uriEncode(rawurldecode($key)),
                self::uriEncode(rawurldecode($value)),
            ];
        }

        // Sort by key first, then by value (both in code point order).
        usort($pairs, static function (array $a, array $b): int {
            $cmp = strcmp($a[0], $b[0]);

            return $cmp !== 0 ? $cmp : strcmp($a[1], $b[1]);
        });

        $parts = [];
        foreach ($pairs as [$key, $value]) {
            $parts[] = $key . '=' . $value;
        }

        return implode('&', $parts);
    }

    /**
     * Build canonical headers string.
     *
     * Headers are lowercased, values are trimmed (collapsing sequential spaces
     * in non-quoted values), and output is sorted alphabetically by header name.
     * Each header line ends with a newline character.
     *
     * @param  array<string, list<string>>  $headers  All request headers.
     * @param  array<string>  $signedHeaders  Sorted lowercase header names.
     * @return string The canonical headers string (each line ends with \n).
     */
    private static function canonicalizeHeaders(array $headers, array $signedHeaders): string
    {
        $canonical = [];

        foreach ($signedHeaders as $name) {
            $values = $headers[$name] ?? [];
            // Trim each value and collapse sequential whitespace.
            $trimmed = array_map(static function (string $value): string {
                // Trim leading/trailing whitespace.
                $value = trim($value);

                // Collapse sequential spaces (but not within quoted strings per AWS spec).
                return (string) preg_replace('/\s+/', ' ', $value);
            }, $values);

            if (count($trimmed) > 1) {
                sort($trimmed, SORT_STRING);
            }

            $canonical[] = $name . ':' . implode(',', $trimmed);
        }

        // Already sorted since $signedHeaders is sorted.
        return implode("\n", $canonical) . "\n";
    }

    /**
     * URI-encode a string per AWS specification.
     *
     * Encodes all characters except unreserved characters: A-Z a-z 0-9 - _ . ~
     * Uses uppercase hex encoding (e.g., %2F not %2f).
     *
     * rawurlencode() follows RFC 3986 — identical to AWS SigV4 except it
     * encodes ~ as %7E. The str_replace corrects this single difference.
     */
    private static function uriEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
