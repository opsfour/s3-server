<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/**
 * Derives the AWS SigV4 signing key using the HMAC-SHA256 chain.
 *
 * The signing key is derived from the secret access key through a
 * four-step HMAC chain incorporating the date, region, and service.
 * This key is then used to compute the final request signature.
 *
 * @see https://docs.aws.amazon.com/general/latest/gr/sigv4-calculate-signature.html
 */
final class SigningKey
{
    /** @var array<string, string> Cached signing keys. Key = hash of inputs, value = 32-byte binary signing key. */
    private static array $cache = [];

    private static int $cacheDate = 0;

    /**
     * Derive the signing key for AWS Signature Version 4.
     *
     * Results are cached per (secretKey, date, region, service) tuple with daily eviction.
     * The cache key uses xxh128(secretKey) — the raw secret is NEVER stored as an array key.
     *
     * HMAC chain:
     *   kDate    = HMAC-SHA256("AWS4" + secretKey, date)
     *   kRegion  = HMAC-SHA256(kDate, region)
     *   kService = HMAC-SHA256(kRegion, service)
     *   kSigning = HMAC-SHA256(kService, "aws4_request")
     *
     * @param  string  $secretKey  The secret access key (e.g., "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY").
     * @param  string  $date  The date in YYYYMMDD format (e.g., "20230101").
     * @param  string  $region  The AWS region (e.g., "us-east-1").
     * @param  string  $service  The AWS service (e.g., "s3").
     * @return string The binary signing key (32 bytes raw HMAC-SHA256 output).
     */
    public static function derive(string $secretKey, string $date, string $region, string $service): string
    {
        $dateInt = (int) $date;
        if ($dateInt !== self::$cacheDate) {
            self::$cache = [];
            self::$cacheDate = $dateInt;
        }

        $cacheKey = hash('xxh128', $secretKey, binary: true) . $date . "\0" . $region . "\0" . $service;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, binary: true);
        $kRegion = hash_hmac('sha256', $region, $kDate, binary: true);
        $kService = hash_hmac('sha256', $service, $kRegion, binary: true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, binary: true);

        self::$cache[$cacheKey] = $kSigning;

        return $kSigning;
    }
}
