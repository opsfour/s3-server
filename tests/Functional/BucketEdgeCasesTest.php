<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Functional tests for bucket naming validation, duplicate handling,
 * and various bucket-level edge cases.
 */
final class BucketEdgeCasesTest extends S3FunctionalTestCase
{
    // -----------------------------------------------------------------
    // Valid bucket names
    // -----------------------------------------------------------------

    public function test_create_bucket_with_minimum_name_length(): void
    {
        $bucket = 'abc'; // 3 chars — minimum allowed

        $result = self::$s3->createBucket(['Bucket' => $bucket]);
        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    public function test_create_bucket_with_maximum_name_length(): void
    {
        // 63 characters — maximum allowed.
        $bucket = str_repeat('a', 63);

        $result = self::$s3->createBucket(['Bucket' => $bucket]);
        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    #[DataProvider('validAwsBucketNamesProvider')]
    public function test_create_bucket_accepts_valid_aws_name(string $bucket): void
    {
        $result = self::$s3->createBucket(['Bucket' => $bucket]);

        self::assertSame(200, $result['@metadata']['statusCode']);
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validAwsBucketNamesProvider(): array
    {
        return [
            'periods' => ['valid.bucket.name'],
            'consecutive hyphens' => ['valid--bucket'],
            'period next to hyphen' => ['valid.-bucket'],
        ];
    }

    // -----------------------------------------------------------------
    // Invalid bucket names (parameterized)
    // -----------------------------------------------------------------

    #[DataProvider('invalidBucketNamesProvider')]
    public function test_create_bucket_with_invalid_name(string $name, string $reason): void
    {
        try {
            self::$s3->createBucket(['Bucket' => $name]);
            $this->fail("Expected InvalidBucketName for: {$reason}");
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode(), "Expected 400 for: {$reason}");
            $this->assertSame('InvalidBucketName', $e->getAwsErrorCode(), "Expected InvalidBucketName for: {$reason}");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidBucketNamesProvider(): array
    {
        return [
            'too short (2 chars)' => ['ab', 'name too short (2 chars)'],
            'too long (64 chars)' => [str_repeat('a', 64), 'name too long (64 chars)'],
            'uppercase letters' => ['MyBucket', 'contains uppercase'],
            'starting with hyphen' => ['-bucket', 'starts with hyphen'],
            'ending with hyphen' => ['bucket-', 'ends with hyphen'],
            'xn-- prefix' => ['xn--bucket', 'xn-- prefix (IDN)'],
            'sthree- prefix' => ['sthree-bucket', 'reserved sthree- prefix'],
            'amzn-s3-demo- prefix' => ['amzn-s3-demo-bucket', 'reserved AWS demo prefix'],
            '-s3alias suffix' => ['bucket-s3alias', '-s3alias suffix'],
            '.mrap suffix' => ['bucket.mrap', '.mrap suffix'],
            '--table-s3 suffix' => ['bucket--table-s3', '--table-s3 suffix'],
            '-an suffix' => ['bucket-an', 'account-regional namespace suffix'],
        ];
    }

    // -----------------------------------------------------------------
    // Duplicate bucket
    // -----------------------------------------------------------------

    public function test_create_bucket_duplicate(): void
    {
        $bucket = 'dup-test-' . bin2hex(random_bytes(4));

        self::$s3->createBucket(['Bucket' => $bucket]);

        // Same-owner recreation returns 200 (idempotent, matching AWS S3 behavior).
        $result = self::$s3->createBucket(['Bucket' => $bucket]);
        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    // -----------------------------------------------------------------
    // Delete non-empty bucket
    // -----------------------------------------------------------------

    public function test_delete_non_empty_bucket(): void
    {
        $bucket = 'nonempty-' . bin2hex(random_bytes(4));

        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'file.txt',
            'Body' => 'content',
        ]);

        try {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
            $this->fail('Expected BucketNotEmpty (409)');
        } catch (S3Exception $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('BucketNotEmpty', $e->getAwsErrorCode());
        }

        // Clean up: delete object first, then bucket.
        self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'file.txt']);
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    public function test_delete_bucket_purges_configs_and_incomplete_multipart_uploads(): void
    {
        $bucket = 'purge-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketTagging([
            'Bucket' => $bucket,
            'Tagging' => ['TagSet' => [['Key' => 'old', 'Value' => 'state']]],
        ]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        self::$s3->putBucketWebsite([
            'Bucket' => $bucket,
            'WebsiteConfiguration' => ['IndexDocument' => ['Suffix' => 'index.html']],
        ]);
        self::$s3->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => 'unfinished.bin',
        ]);

        self::$s3->deleteBucket(['Bucket' => $bucket]);
        self::$s3->createBucket(['Bucket' => $bucket]);

        self::assertSame([], self::$s3->listMultipartUploads(['Bucket' => $bucket])['Uploads'] ?? []);
        self::assertSame(
            null,
            self::$s3->getBucketVersioning(['Bucket' => $bucket])['Status'] ?? null,
        );
        foreach (['getBucketTagging', 'getBucketWebsite'] as $operation) {
            try {
                self::$s3->{$operation}(['Bucket' => $bucket]);
                self::fail("Expected {$operation} to find no inherited configuration.");
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
            }
        }

        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    // -----------------------------------------------------------------
    // Non-existent bucket operations
    // -----------------------------------------------------------------

    public function test_delete_non_existent_bucket(): void
    {
        try {
            self::$s3->deleteBucket(['Bucket' => 'nonexistent-' . bin2hex(random_bytes(4))]);
            $this->fail('Expected NoSuchBucket (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucket', $e->getAwsErrorCode());
        }
    }

    public function test_head_bucket_non_existent(): void
    {
        try {
            self::$s3->headBucket(['Bucket' => 'nonexistent-' . bin2hex(random_bytes(4))]);
            $this->fail('Expected 404 for non-existent bucket');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // GetBucketLocation
    // -----------------------------------------------------------------

    public function test_get_bucket_location(): void
    {
        $bucket = 'location-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);

        $result = self::$s3->getBucketLocation(['Bucket' => $bucket]);
        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    // -----------------------------------------------------------------
    // ListBuckets empty
    // -----------------------------------------------------------------

    public function test_list_buckets_returns_result(): void
    {
        $result = self::$s3->listBuckets();
        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertIsArray($result['Buckets'] ?? []);
    }
}
