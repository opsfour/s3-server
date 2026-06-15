<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Functional tests for core object CRUD operations using the AWS SDK.
 *
 * Tests: CreateBucket, PutObject, GetObject, HeadObject, DeleteObject,
 * ListBuckets, DeleteBucket, conditional requests, and range requests.
 */
final class ObjectCrudTest extends S3FunctionalTestCase
{
    private static string $testBucket = 'test-crud-bucket';

    public function test_create_bucket(): void
    {
        $result = self::$s3->createBucket([
            'Bucket' => self::$testBucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    #[Depends('test_create_bucket')]
    public function test_list_buckets_contains_created_bucket(): void
    {
        $result = self::$s3->listBuckets();

        $bucketNames = array_map(
            fn ($b) => $b['Name'],
            $result['Buckets'] ?? [],
        );

        $this->assertContains(self::$testBucket, $bucketNames);
    }

    #[Depends('test_create_bucket')]
    public function test_head_bucket_exists(): void
    {
        $result = self::$s3->headBucket([
            'Bucket' => self::$testBucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    #[Depends('test_create_bucket')]
    public function test_put_and_get_object(): void
    {
        $body = 'Hello, S3 Server!';

        // PutObject
        $putResult = self::$s3->putObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
            'Body' => $body,
            'ContentType' => 'text/plain',
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $putResult['ETag']);

        // GetObject
        $getResult = self::$s3->getObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
        ]);

        $this->assertSame($body, (string) $getResult['Body']);
        $this->assertSame('text/plain', $getResult['ContentType']);
        $this->assertSame($putResult['ETag'], $getResult['ETag']);
    }

    #[Depends('test_put_and_get_object')]
    public function test_head_object(): void
    {
        $result = self::$s3->headObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame(17, $result['ContentLength']); // 'Hello, S3 Server!' = 17 bytes
        $this->assertSame('text/plain', $result['ContentType']);
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', $result['ETag']);
    }

    #[Depends('test_put_and_get_object')]
    public function test_get_object_range(): void
    {
        // bytes=0-4 should return 'Hello'
        $result = self::$s3->getObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
            'Range' => 'bytes=0-4',
        ]);

        $this->assertSame('Hello', (string) $result['Body']);
        $this->assertSame(5, $result['ContentLength']);
    }

    #[Depends('test_put_and_get_object')]
    public function test_get_object_if_none_match_returns304(): void
    {
        // First get the ETag.
        $headResult = self::$s3->headObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
        ]);
        $etag = $headResult['ETag'];

        // The AWS SDK returns a result with status 304 (no exception thrown).
        $result = self::$s3->getObject([
            'Bucket' => self::$testBucket,
            'Key' => 'test-key.txt',
            'IfNoneMatch' => $etag,
        ]);

        $this->assertSame(304, $result['@metadata']['statusCode']);
        $this->assertSame('', (string) $result['Body']);
    }

    #[Depends('test_put_and_get_object')]
    public function test_put_object_with_metadata(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$testBucket,
            'Key' => 'meta-object.txt',
            'Body' => 'metadata test',
            'ContentType' => 'text/plain',
            'Metadata' => [
                'custom-key' => 'custom-value',
                'another-key' => 'another-value',
            ],
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$testBucket,
            'Key' => 'meta-object.txt',
        ]);

        $this->assertSame('custom-value', $result['Metadata']['custom-key'] ?? null);
        $this->assertSame('another-value', $result['Metadata']['another-key'] ?? null);
    }

    #[Depends('test_put_and_get_object')]
    public function test_delete_object(): void
    {
        // Put an object to delete.
        self::$s3->putObject([
            'Bucket' => self::$testBucket,
            'Key' => 'to-delete.txt',
            'Body' => 'delete me',
        ]);

        // Delete it.
        $result = self::$s3->deleteObject([
            'Bucket' => self::$testBucket,
            'Key' => 'to-delete.txt',
        ]);

        $this->assertSame(204, $result['@metadata']['statusCode']);

        // Verify it's gone.
        try {
            self::$s3->headObject([
                'Bucket' => self::$testBucket,
                'Key' => 'to-delete.txt',
            ]);
            $this->fail('Expected NoSuchKey (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[Depends('test_put_and_get_object')]
    public function test_delete_object_idempotent(): void
    {
        // Deleting a non-existent object should still return 204.
        $result = self::$s3->deleteObject([
            'Bucket' => self::$testBucket,
            'Key' => 'nonexistent-key-'.uniqid(),
        ]);

        $this->assertSame(204, $result['@metadata']['statusCode']);
    }

    #[Depends('test_put_and_get_object')]
    public function test_get_non_existent_object_returns404(): void
    {
        try {
            self::$s3->getObject([
                'Bucket' => self::$testBucket,
                'Key' => 'no-such-key-'.uniqid(),
            ]);
            $this->fail('Expected NoSuchKey exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    #[Depends('test_create_bucket')]
    public function test_multi_tenant_isolation(): void
    {
        // Create a second client with different credentials.
        // This should not be able to access our bucket.
        // Since the server only has one credential set, any other key should get 403.
        $otherClient = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => 'unknown-key',
                'secret' => 'unknown-secret',
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 10,
            ],
        ]);

        try {
            $otherClient->headBucket([
                'Bucket' => self::$testBucket,
            ]);
            $this->fail('Expected 403 or 404 for unauthorized access');
        } catch (S3Exception $e) {
            $this->assertContains($e->getStatusCode(), [403, 404]);
        }
    }
}
