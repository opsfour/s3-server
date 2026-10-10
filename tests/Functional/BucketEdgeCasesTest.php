<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
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

    public function test_list_buckets_paginates_and_filters_without_losing_or_repeating_buckets(): void
    {
        $prefix = 'bucket-page-' . bin2hex(random_bytes(4));
        $buckets = array_map(static fn(int $number): string => sprintf('%s-%02d', $prefix, $number), range(0, 3));
        $created = [];
        try {
            foreach ($buckets as $index => $bucket) {
                $request = ['Bucket' => $bucket];
                if ($index === 3) {
                    $request['CreateBucketConfiguration'] = ['LocationConstraint' => 'us-west-2'];
                }
                self::$s3->createBucket($request);
                $created[] = $bucket;
            }

            $first = self::$s3->listBuckets(['MaxBuckets' => 2, 'Prefix' => $prefix]);
            self::assertSame(array_slice($buckets, 0, 2), array_column($first['Buckets'] ?? [], 'Name'));
            self::assertSame($prefix, $first['Prefix'] ?? null);
            self::assertSame('us-east-1', $first['Buckets'][0]['BucketRegion'] ?? null);
            $token = $first['ContinuationToken'] ?? null;
            self::assertIsString($token);

            $second = self::$s3->listBuckets(['MaxBuckets' => 2, 'Prefix' => $prefix, 'ContinuationToken' => $token]);
            self::assertSame(array_slice($buckets, 2), array_column($second['Buckets'] ?? [], 'Name'));
            self::assertNull($second['ContinuationToken'] ?? null);
            self::assertSame('us-west-2', $second['Buckets'][1]['BucketRegion'] ?? null);

            $west = self::$s3->listBuckets(['BucketRegion' => 'us-west-2', 'Prefix' => $prefix]);
            self::assertSame([$buckets[3]], array_column($west['Buckets'] ?? [], 'Name'));
            self::assertSame([], self::$s3->listBuckets(['Prefix' => $prefix . '%'])['Buckets'] ?? []);

            try {
                self::$s3->listBuckets(['MaxBuckets' => 2, 'Prefix' => $prefix . '-0', 'ContinuationToken' => $token]);
                self::fail('A continuation token must be bound to its prefix.');
            } catch (S3Exception $exception) {
                self::assertSame('InvalidArgument', $exception->getAwsErrorCode());
            }
        } finally {
            foreach ($created as $bucket) {
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            }
        }
    }

    #[DataProvider('invalidListBucketsParametersProvider')]
    public function test_list_buckets_rejects_invalid_pagination_parameters(string $query): void
    {
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $request = new Request('GET', sprintf('http://%s:%d/?%s', self::$host, self::$port, $query));
        $response = (new Client(['http_errors' => false]))->send($signer->signRequest($request, $credentials));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody());
    }

    /** @return array<string, array{0: string}> */
    public static function invalidListBucketsParametersProvider(): array
    {
        return [
            'zero maximum' => ['max-buckets=0'],
            'excessive maximum' => ['max-buckets=10001'],
            'malformed maximum' => ['max-buckets=1junk'],
            'malformed token' => ['continuation-token=opaque-token'],
        ];
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
        // The AWS PHP SDK normalizes S3's empty us-east-1 XML value.
        $this->assertSame('us-east-1', $result['LocationConstraint']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $bucket]);
    }

    public function test_get_bucket_location_returns_non_default_region_and_missing_bucket_error(): void
    {
        $bucket = 'location-west-' . bin2hex(random_bytes(4));
        self::$s3->createBucket([
            'Bucket' => $bucket,
            'CreateBucketConfiguration' => ['LocationConstraint' => 'us-west-2'],
        ]);

        try {
            $result = self::$s3->getBucketLocation(['Bucket' => $bucket]);
            self::assertSame('us-west-2', $result['LocationConstraint']);

            try {
                self::$s3->getBucketLocation(['Bucket' => 'missing-' . bin2hex(random_bytes(4))]);
                self::fail('Expected NoSuchBucket.');
            } catch (S3Exception $error) {
                self::assertSame(404, $error->getStatusCode());
                self::assertSame('NoSuchBucket', $error->getAwsErrorCode());
            }
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_new_bucket_configuration_reads_have_distinct_empty_and_missing_contracts(): void
    {
        $bucket = 'empty-config-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $versioning = self::$s3->getBucketVersioning(['Bucket' => $bucket]);
            $this->assertSame(200, $versioning['@metadata']['statusCode']);
            $this->assertNull($versioning['Status']);

            $notification = self::$s3->getBucketNotificationConfiguration(['Bucket' => $bucket]);
            $this->assertSame(200, $notification['@metadata']['statusCode']);
            $this->assertEmpty($notification['TopicConfigurations'] ?? []);
            $this->assertEmpty($notification['QueueConfigurations'] ?? []);
            $this->assertEmpty($notification['LambdaFunctionConfigurations'] ?? []);

            $logging = self::$s3->getBucketLogging(['Bucket' => $bucket]);
            $this->assertSame(200, $logging['@metadata']['statusCode']);
            $this->assertNull($logging['LoggingEnabled']);

            foreach ([
                'GetBucketPolicy' => 'NoSuchBucketPolicy',
                'GetBucketTagging' => 'NoSuchTagSet',
                'GetBucketLifecycleConfiguration' => 'NoSuchLifecycleConfiguration',
                'GetBucketCors' => 'NoSuchCORSConfiguration',
                'GetBucketWebsite' => 'NoSuchWebsiteConfiguration',
                'GetObjectLockConfiguration' => 'ObjectLockConfigurationNotFoundError',
                'GetPublicAccessBlock' => 'NoSuchPublicAccessBlockConfiguration',
            ] as $operation => $errorCode) {
                try {
                    self::$s3->execute(self::$s3->getCommand($operation, ['Bucket' => $bucket]));
                    $this->fail($operation . ' accepted a missing configuration.');
                } catch (S3Exception $exception) {
                    $this->assertSame(404, $exception->getStatusCode(), $operation);
                    $this->assertSame($errorCode, $exception->getAwsErrorCode(), $operation);
                }
            }
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
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
