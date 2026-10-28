<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** A unique, fully scoped AWS physical key for one object write attempt. */
final class AwsS3WriteAttemptPath
{
    private const string PREFIX = 'opsfour-write-v1';
    private const int MAX_S3_KEY_BYTES = 1024;

    public static function forObject(
        string $bucket,
        string $key,
        string $bucketGeneration,
        string $storageTier,
        string $attemptId,
        string $reservedPrefix = self::PREFIX,
    ): string {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)
            || ! preg_match('/^[a-f0-9]{32}$/D', $bucketGeneration)
            || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || $storageTier === '' || strlen($storageTier) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $storageTier)
            || $key === '' || strlen($key) > self::MAX_S3_KEY_BYTES
        ) {
            throw new \InvalidArgumentException('Invalid AWS object write attempt scope.');
        }

        StoragePath::assertBucketPathSegment($bucket);
        AwsS3ProviderProfile::assertCanonicalPrefix(
            $reservedPrefix,
            'reserved write-attempt',
            AwsS3ProviderProfile::MAX_RESERVED_PREFIX_BYTES,
        );

        // Hex preserves the complete tier name without permitting path syntax
        // from a configured tier to change the physical key's segment layout.
        $path = $reservedPrefix . '/' . $bucket . '/' . $bucketGeneration . '/'
            . bin2hex($storageTier) . '/' . hash('sha256', $key) . '/' . $attemptId;

        if (strlen($path) > self::MAX_S3_KEY_BYTES) {
            throw new \InvalidArgumentException('AWS physical key exceeds the S3 key size limit.');
        }

        return $path;
    }

    public static function assertExactPath(
        string $path,
        string $bucket,
        string $key,
        string $bucketGeneration,
        string $storageTier,
        string $attemptId,
        string $reservedPrefix = self::PREFIX,
    ): void {
        if (strlen($path) > self::MAX_S3_KEY_BYTES
            || ! hash_equals(self::forObject($bucket, $key, $bucketGeneration, $storageTier, $attemptId, $reservedPrefix), $path)
        ) {
            throw new \InvalidArgumentException('AWS physical key does not match the exact write attempt.');
        }
    }

    /** Verify the durable row's entire identity before using its stored path. */
    public static function assertIntentPath(
        ObjectWriteIntent $intent,
        string $key,
        string $reservedPrefix = self::PREFIX,
    ): void {
        self::assertExactPath(
            $intent->storagePath,
            $intent->bucket,
            $key,
            $intent->bucketGeneration,
            $intent->storageTier,
            $intent->attemptId,
            $reservedPrefix,
        );
    }

    public static function forProfileObject(
        AwsS3ProviderProfile $profile,
        string $logicalBucket,
        string $key,
        string $bucketGeneration,
        string $storageTier,
        string $attemptId,
    ): string {
        return self::forObject(
            $logicalBucket,
            $key,
            $bucketGeneration,
            $storageTier,
            $attemptId,
            $profile->reservedPrefix,
        );
    }

    public static function assertProfileIntentPath(
        AwsS3ProviderProfile $profile,
        ObjectWriteIntent $intent,
        string $key,
    ): void {
        self::assertIntentPath($intent, $key, $profile->reservedPrefix);
    }

    /** @return array{generation: string, attemptId: string} */
    public static function parseGarbagePath(string $path, string $bucket, string $tier, string $reservedPrefix): array
    {
        self::forObject($bucket, 'scope', str_repeat('0', 32), $tier, str_repeat('0', 32), $reservedPrefix);
        $prefix = preg_quote($reservedPrefix . '/' . $bucket . '/', '~');
        $tierHex = preg_quote(bin2hex($tier), '~');
        if (strlen($path) > self::MAX_S3_KEY_BYTES
            || preg_match('~^' . $prefix . '([a-f0-9]{32})/' . $tierHex . '/[a-f0-9]{64}/([a-f0-9]{32})$~D', $path, $matches) !== 1) {
            throw new \InvalidArgumentException('AWS garbage path is not an exact immutable attempt key.');
        }

        return ['generation' => $matches[1], 'attemptId' => $matches[2]];
    }

    private function __construct() {}
}
