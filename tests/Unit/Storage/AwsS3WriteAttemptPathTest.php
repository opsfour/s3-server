<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use PHPUnit\Framework\TestCase;

final class AwsS3WriteAttemptPathTest extends TestCase
{
    private const string GENERATION = '0123456789abcdef0123456789abcdef';
    private const string ATTEMPT = 'abcdef0123456789abcdef0123456789';

    public function test_path_contains_the_complete_key_digest_and_exact_attempt_scope(): void
    {
        $path = self::path('bucket-one', 'folder/object', self::GENERATION, 'STANDARD', self::ATTEMPT);

        self::assertSame(
            'opsfour-write-v1/bucket-one/' . self::GENERATION . '/5354414e44415244/'
                . hash('sha256', 'folder/object') . '/' . self::ATTEMPT,
            $path,
        );
        self::assertLessThanOrEqual(1024, strlen($path));
        AwsS3WriteAttemptPath::assertExactPath($path, 'bucket-one', 'folder/object', self::GENERATION, 'STANDARD', self::ATTEMPT);

        AwsS3WriteAttemptPath::assertIntentPath(new ObjectWriteIntent(
            attemptId: self::ATTEMPT,
            bucket: 'bucket-one',
            bucketGeneration: self::GENERATION,
            ownerId: 'owner-one',
            storageTier: 'STANDARD',
            storagePath: $path,
            purpose: 'put',
        ), 'folder/object');
    }

    public function test_keys_with_the_same_existing_four_hex_prefix_have_distinct_paths(): void
    {
        $first = 'key-212';
        $second = 'key-279';
        self::assertSame(substr(hash('sha256', $first), 0, 4), substr(hash('sha256', $second), 0, 4));
        self::assertNotSame(hash('sha256', $first), hash('sha256', $second));

        $firstPath = self::path('bucket-one', $first, self::GENERATION, 'STANDARD', self::ATTEMPT);
        $secondPath = self::path('bucket-one', $second, self::GENERATION, 'STANDARD', self::ATTEMPT);
        self::assertNotSame($firstPath, $secondPath);
        self::assertRejected(static fn() => AwsS3WriteAttemptPath::assertExactPath(
            $firstPath,
            'bucket-one',
            $second,
            self::GENERATION,
            'STANDARD',
            self::ATTEMPT,
        ));
    }

    public function test_identity_mismatch_and_noncanonical_path_are_rejected(): void
    {
        $path = self::path('bucket-one', 'object', self::GENERATION, 'STANDARD', self::ATTEMPT);
        $otherGeneration = str_repeat('f', 32);
        $otherAttempt = str_repeat('e', 32);

        foreach ([
            ['bucket-two', 'object', self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['bucket-one', 'other-object', self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['bucket-one', 'object', $otherGeneration, 'STANDARD', self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, 'ARCHIVE', self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, 'STANDARD', $otherAttempt],
        ] as [$bucket, $key, $generation, $tier, $attempt]) {
            self::assertRejected(static fn() => AwsS3WriteAttemptPath::assertExactPath(
                $path,
                $bucket,
                $key,
                $generation,
                $tier,
                $attempt,
            ));
        }

        foreach ([$path . '/extra', '/' . $path, str_replace('/', '//', $path), strtoupper($path), $path . "\0"] as $badPath) {
            self::assertRejected(static fn() => AwsS3WriteAttemptPath::assertExactPath(
                $badPath,
                'bucket-one',
                'object',
                self::GENERATION,
                'STANDARD',
                self::ATTEMPT,
            ));
        }
    }

    public function test_invalid_scope_fields_and_oversized_object_key_are_rejected(): void
    {
        foreach ([
            ['../bucket', 'object', self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['Bucket', 'object', self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['bucket-one', '', self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['bucket-one', str_repeat('x', 1025), self::GENERATION, 'STANDARD', self::ATTEMPT],
            ['bucket-one', 'object', 'not-a-generation', 'STANDARD', self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, '', self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, "BAD\nTIER", self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, str_repeat('x', 65), self::ATTEMPT],
            ['bucket-one', 'object', self::GENERATION, 'STANDARD', 'not-an-attempt'],
        ] as [$bucket, $key, $generation, $tier, $attempt]) {
            self::assertRejected(static fn() => self::path($bucket, $key, $generation, $tier, $attempt));
        }

        $maxKeyPath = self::path('bucket-one', str_repeat('x', 1024), self::GENERATION, 'STANDARD', self::ATTEMPT);
        self::assertLessThanOrEqual(1024, strlen($maxKeyPath));
    }

    public function test_full_tier_bytes_are_bound_without_path_normalization(): void
    {
        $tier = 'cold/archive.v1';
        $path = self::path('bucket-one', 'object', self::GENERATION, $tier, self::ATTEMPT);
        self::assertStringContainsString('/' . bin2hex($tier) . '/', $path);
        self::assertCount(6, explode('/', $path));
        self::assertRejected(static fn() => AwsS3WriteAttemptPath::assertExactPath(
            $path,
            'bucket-one',
            'object',
            self::GENERATION,
            'cold-archive.v1',
            self::ATTEMPT,
        ));
    }

    public function test_profile_attempt_path_is_beneath_the_validated_reserved_prefix(): void
    {
        $profile = new AwsS3ProviderProfile(
            bucket: 'provider-bucket',
            region: 'us-east-1',
            expectedOwnerId: '123456789012',
            dataPrefix: 'objects',
            reservedPrefix: '.opsfour/private-attempts',
        );

        $path = AwsS3WriteAttemptPath::forProfileObject(
            $profile,
            'bucket-one',
            'object',
            self::GENERATION,
            'STANDARD',
            self::ATTEMPT,
        );

        self::assertStringStartsWith('.opsfour/private-attempts/', $path);
        self::assertFalse(str_starts_with($path, $profile->dataPrefix . '/'));
        AwsS3WriteAttemptPath::assertProfileIntentPath($profile, new ObjectWriteIntent(
            attemptId: self::ATTEMPT,
            bucket: 'bucket-one',
            bucketGeneration: self::GENERATION,
            ownerId: 'owner-one',
            storageTier: 'STANDARD',
            storagePath: $path,
            purpose: 'put',
        ), 'object');
    }

    public function test_maximum_valid_profile_prefix_always_fits_the_maximum_derived_attempt_path(): void
    {
        $profile = new AwsS3ProviderProfile(
            bucket: 'provider-bucket',
            region: 'us-east-1',
            expectedOwnerId: '123456789012',
            dataPrefix: 'objects',
            reservedPrefix: str_repeat('r', AwsS3ProviderProfile::MAX_RESERVED_PREFIX_BYTES),
        );

        $path = AwsS3WriteAttemptPath::forProfileObject(
            $profile,
            str_repeat('b', 63),
            str_repeat('k', 1024),
            self::GENERATION,
            str_repeat('T', 64),
            self::ATTEMPT,
        );

        self::assertSame(1024, strlen($path));
    }

    public function test_reserved_prefix_validation_rejects_malformed_utf8_on_every_entry_path(): void
    {
        $malformed = ".opsfour/\xC3\x28";

        self::assertRejected(static fn() => new AwsS3ProviderProfile(
            bucket: 'provider-bucket',
            region: 'us-east-1',
            expectedOwnerId: '123456789012',
            dataPrefix: 'objects',
            reservedPrefix: $malformed,
        ));
        self::assertRejected(static fn() => AwsS3WriteAttemptPath::forObject(
            'bucket-one',
            'object',
            self::GENERATION,
            'STANDARD',
            self::ATTEMPT,
            $malformed,
        ));
    }

    private static function path(string $bucket, string $key, string $generation, string $tier, string $attempt): string
    {
        return AwsS3WriteAttemptPath::forObject($bucket, $key, $generation, $tier, $attempt);
    }

    /** @param \Closure(): mixed $callback */
    private static function assertRejected(\Closure $callback): void
    {
        try {
            $callback();
            self::fail('A malformed or mismatched AWS write attempt path must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        }
    }
}
