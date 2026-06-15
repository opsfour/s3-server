<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Comprehensive end-to-end tests covering edge cases and cross-cutting
 * concerns across all S3 operations.
 *
 * Tests: Unicode/special keys, various object sizes, user metadata,
 * content-type preservation, cross-bucket copy, range request edge cases,
 * object overwrite, listing with common prefixes, conditional requests,
 * and storage class preservation.
 */
final class ComprehensiveE2ETest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-e2e-bucket';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // 1. Unicode / Special Character Object Keys
    // -----------------------------------------------------------------

    public function test_unicode_object_key(): void
    {
        $key = 'documents/日本語/テスト.txt';
        $body = 'unicode key content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        // Clean up.
        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_special_character_keys(): void
    {
        $keys = [
            'path/with spaces/file.txt',
            'path/with+plus/file.txt',
            'path/with#hash/file.txt',
            'file-with-dashes.txt',
            'file_with_underscores.txt',
            'file.multiple.dots.txt',
            'deeply/nested/path/to/object.dat',
        ];

        foreach ($keys as $key) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => "content for {$key}",
            ]);
        }

        foreach ($keys as $key) {
            $result = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            $this->assertSame("content for {$key}", (string) $result['Body']);
        }

        // Clean up.
        $deleteObjects = array_map(fn($k) => ['Key' => $k], $keys);
        self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => ['Objects' => $deleteObjects],
        ]);
    }

    // -----------------------------------------------------------------
    // 2. Various Object Sizes
    // -----------------------------------------------------------------

    public function test_zero_bytes_object(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'empty.dat',
            'Body' => '',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'empty.dat',
        ]);
        $this->assertSame('', (string) $result['Body']);
        $this->assertSame(0, $result['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'empty.dat']);
    }

    public function test_one_byte_object(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'single.dat',
            'Body' => 'X',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'single.dat',
        ]);
        $this->assertSame('X', (string) $result['Body']);
        $this->assertSame(1, $result['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'single.dat']);
    }

    public function test_large_object(): void
    {
        // 1 MB object with binary content.
        $body = random_bytes(1024 * 1024);

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'large.dat',
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'large.dat',
        ]);
        $this->assertSame($body, (string) $result['Body']);
        $this->assertSame(1024 * 1024, $result['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'large.dat']);
    }

    // -----------------------------------------------------------------
    // 3. User Metadata Preservation
    // -----------------------------------------------------------------

    public function test_user_metadata_round_trip(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'metadata-test.txt',
            'Body' => 'test',
            'Metadata' => [
                'author' => 'Jane Doe',
                'version' => '2.0',
                'description' => 'Test file with metadata',
            ],
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'metadata-test.txt',
        ]);

        $meta = $result['Metadata'] ?? [];
        $this->assertSame('Jane Doe', $meta['author'] ?? '');
        $this->assertSame('2.0', $meta['version'] ?? '');
        $this->assertSame('Test file with metadata', $meta['description'] ?? '');

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'metadata-test.txt']);
    }

    // -----------------------------------------------------------------
    // 4. Content-Type Preservation
    // -----------------------------------------------------------------

    public function test_content_type_preservation(): void
    {
        $types = [
            'text.html' => 'text/html',
            'data.json' => 'application/json',
            'image.png' => 'image/png',
            'archive.tar.gz' => 'application/gzip',
        ];

        foreach ($types as $key => $contentType) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => 'test',
                'ContentType' => $contentType,
            ]);
        }

        foreach ($types as $key => $contentType) {
            $result = self::$s3->headObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            $this->assertSame($contentType, $result['ContentType'], "Content-Type mismatch for {$key}");
        }

        // Clean up.
        $deleteObjects = array_map(fn($k) => ['Key' => $k], array_keys($types));
        self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => ['Objects' => $deleteObjects],
        ]);
    }

    // -----------------------------------------------------------------
    // 5. Cross-Bucket Copy
    // -----------------------------------------------------------------

    public function test_cross_reference_bucket_operations(): void
    {
        $srcBucket = 'test-e2e-src-bucket';
        $dstBucket = 'test-e2e-dst-bucket';

        self::$s3->createBucket(['Bucket' => $srcBucket]);
        self::$s3->createBucket(['Bucket' => $dstBucket]);

        // Put object in source bucket.
        self::$s3->putObject([
            'Bucket' => $srcBucket,
            'Key' => 'original.txt',
            'Body' => 'source content',
        ]);

        // Copy to destination bucket.
        self::$s3->copyObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied.txt',
            'CopySource' => "{$srcBucket}/original.txt",
        ]);

        // Verify copy.
        $result = self::$s3->getObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied.txt',
        ]);
        $this->assertSame('source content', (string) $result['Body']);

        // Clean up.
        self::$s3->deleteObject(['Bucket' => $srcBucket, 'Key' => 'original.txt']);
        self::$s3->deleteObject(['Bucket' => $dstBucket, 'Key' => 'copied.txt']);
        self::$s3->deleteBucket(['Bucket' => $srcBucket]);
        self::$s3->deleteBucket(['Bucket' => $dstBucket]);
    }

    // -----------------------------------------------------------------
    // 6. Range Request Edge Cases
    // -----------------------------------------------------------------

    public function test_range_request_edge_cases(): void
    {
        $body = 'ABCDEFGHIJ'; // 10 bytes

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'range-test.txt',
            'Body' => $body,
        ]);

        // First 5 bytes.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'range-test.txt',
            'Range' => 'bytes=0-4',
        ]);
        $this->assertSame('ABCDE', (string) $result['Body']);
        $this->assertSame(206, $result['@metadata']['statusCode']);

        // Last 3 bytes.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'range-test.txt',
            'Range' => 'bytes=-3',
        ]);
        $this->assertSame('HIJ', (string) $result['Body']);

        // From byte 5 to end.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'range-test.txt',
            'Range' => 'bytes=5-',
        ]);
        $this->assertSame('FGHIJ', (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'range-test.txt']);
    }

    // -----------------------------------------------------------------
    // 7. Overwrite Existing Object
    // -----------------------------------------------------------------

    public function test_overwrite_object(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'overwrite.txt',
            'Body' => 'version 1',
        ]);

        $etag1 = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'overwrite.txt',
        ])['ETag'];

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'overwrite.txt',
            'Body' => 'version 2 with different content',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'overwrite.txt',
        ]);

        $this->assertSame('version 2 with different content', (string) $result['Body']);
        $this->assertNotSame($etag1, $result['ETag']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'overwrite.txt']);
    }

    // -----------------------------------------------------------------
    // 8. ListObjectsV2 with Common Prefixes
    // -----------------------------------------------------------------

    public function test_list_objects_v2_with_common_prefixes(): void
    {
        // Create a directory-like structure.
        $keys = [
            'photos/2024/jan/photo1.jpg',
            'photos/2024/jan/photo2.jpg',
            'photos/2024/feb/photo3.jpg',
            'photos/2025/mar/photo4.jpg',
            'documents/report.pdf',
            'documents/summary.txt',
            'root-file.txt',
        ];

        foreach ($keys as $key) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => 'data',
            ]);
        }

        // List with delimiter at root level.
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Delimiter' => '/',
            'Prefix' => '',
        ]);

        // Check we get the common prefixes.
        $prefixes = array_map(fn($p) => $p['Prefix'], $result['CommonPrefixes'] ?? []);
        $this->assertContains('photos/', $prefixes);
        $this->assertContains('documents/', $prefixes);

        // List within photos/ prefix with delimiter.
        $result2 = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Delimiter' => '/',
            'Prefix' => 'photos/',
        ]);

        $prefixes2 = array_map(fn($p) => $p['Prefix'], $result2['CommonPrefixes'] ?? []);
        $this->assertContains('photos/2024/', $prefixes2);
        $this->assertContains('photos/2025/', $prefixes2);

        // Clean up all objects.
        $deleteObjects = array_map(fn($k) => ['Key' => $k], $keys);
        self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => ['Objects' => $deleteObjects],
        ]);
    }

    // -----------------------------------------------------------------
    // 9. Conditional Request Edge Cases
    // -----------------------------------------------------------------

    public function test_conditional_requests(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'conditional.txt',
            'Body' => 'conditional test',
        ]);

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'conditional.txt',
        ]);
        $etag = $head['ETag'];

        // If-Match with correct ETag: should succeed.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'conditional.txt',
            'IfMatch' => $etag,
        ]);
        $this->assertSame(200, $result['@metadata']['statusCode']);

        // If-Match with wrong ETag: should return 412.
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'conditional.txt',
                'IfMatch' => '"wrongetag"',
            ]);
            $this->fail('Expected PreconditionFailed');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
            $this->assertSame('PreconditionFailed', $e->getAwsErrorCode());
        }

        // If-None-Match with matching ETag: should return 304.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'conditional.txt',
            'IfNoneMatch' => $etag,
        ]);
        $this->assertSame(304, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'conditional.txt']);
    }

    // -----------------------------------------------------------------
    // 10. Storage Class
    // -----------------------------------------------------------------

    public function test_storage_class(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'glacierobj.txt',
            'Body' => 'cold storage',
            'StorageClass' => 'GLACIER',
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'glacierobj.txt',
        ]);

        $this->assertSame('GLACIER', $result['StorageClass'] ?? '');

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'glacierobj.txt']);
    }
}
