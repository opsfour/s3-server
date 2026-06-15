<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for Phase 3: ListObjectsV2, ListObjects v1,
 * DeleteObjects batch, and CopyObject.
 */
final class ListingAndCopyTest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-listing-bucket';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        // Create bucket and seed test objects.
        self::$s3->createBucket(['Bucket' => self::$bucket]);

        $objects = [
            'file1.txt' => 'content1',
            'file2.txt' => 'content2',
            'dir1/file3.txt' => 'content3',
            'dir1/file4.txt' => 'content4',
            'dir1/sub/file5.txt' => 'content5',
            'dir2/file6.txt' => 'content6',
            'photos/2024/jan.jpg' => 'jan-photo',
            'photos/2024/feb.jpg' => 'feb-photo',
            'photos/2025/mar.jpg' => 'mar-photo',
        ];

        foreach ($objects as $key => $body) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => $body,
            ]);
        }

        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // ListObjectsV2
    // -----------------------------------------------------------------

    public function test_list_objects_v2_basic(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame(9, $result['KeyCount']);
        $this->assertFalse($result['IsTruncated']);
        $this->assertCount(9, $result['Contents']);
    }

    public function test_list_objects_v2_with_prefix(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'dir1/',
        ]);

        $this->assertSame(3, $result['KeyCount']);

        $keys = array_map(fn($c) => $c['Key'], $result['Contents']);
        sort($keys);
        $this->assertSame(['dir1/file3.txt', 'dir1/file4.txt', 'dir1/sub/file5.txt'], $keys);
    }

    public function test_list_objects_v2_with_delimiter(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Delimiter' => '/',
        ]);

        // Top-level files: file1.txt, file2.txt
        $keys = array_map(fn($c) => $c['Key'], $result['Contents'] ?? []);
        sort($keys);
        $this->assertSame(['file1.txt', 'file2.txt'], $keys);

        // Common prefixes: dir1/, dir2/, photos/
        $prefixes = array_map(fn($p) => $p['Prefix'], $result['CommonPrefixes'] ?? []);
        sort($prefixes);
        $this->assertSame(['dir1/', 'dir2/', 'photos/'], $prefixes);
    }

    public function test_list_objects_v2_with_prefix_and_delimiter(): void
    {
        $result = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'Prefix' => 'photos/',
            'Delimiter' => '/',
        ]);

        // No direct objects under photos/ (they're all under photos/year/)
        $this->assertEmpty($result['Contents'] ?? []);

        // Common prefixes: photos/2024/, photos/2025/
        $prefixes = array_map(fn($p) => $p['Prefix'], $result['CommonPrefixes'] ?? []);
        sort($prefixes);
        $this->assertSame(['photos/2024/', 'photos/2025/'], $prefixes);
    }

    public function test_list_objects_v2_pagination(): void
    {
        $result1 = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 3,
        ]);

        $this->assertTrue($result1['IsTruncated']);
        $this->assertSame(3, $result1['KeyCount']);
        $this->assertNotEmpty($result1['NextContinuationToken']);

        // Get second page.
        $result2 = self::$s3->listObjectsV2([
            'Bucket' => self::$bucket,
            'MaxKeys' => 3,
            'ContinuationToken' => $result1['NextContinuationToken'],
        ]);

        $this->assertSame(3, $result2['KeyCount']);

        // Keys from page 1 and page 2 should not overlap.
        $keys1 = array_map(fn($c) => $c['Key'], $result1['Contents']);
        $keys2 = array_map(fn($c) => $c['Key'], $result2['Contents']);
        $this->assertEmpty(array_intersect($keys1, $keys2));

        // Collect all keys across all pages.
        $allKeys = $keys1;
        $token = $result2['NextContinuationToken'] ?? null;
        $allKeys = array_merge($allKeys, $keys2);

        while ($token !== null) {
            $page = self::$s3->listObjectsV2([
                'Bucket' => self::$bucket,
                'MaxKeys' => 3,
                'ContinuationToken' => $token,
            ]);
            $allKeys = array_merge(
                $allKeys,
                array_map(fn($c) => $c['Key'], $page['Contents'] ?? []),
            );
            $token = $page['NextContinuationToken'] ?? null;
        }

        $this->assertCount(9, $allKeys);
    }

    // -----------------------------------------------------------------
    // ListObjects v1
    // -----------------------------------------------------------------

    public function test_list_objects_v1_basic(): void
    {
        $result = self::$s3->listObjects([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertFalse($result['IsTruncated']);
        $this->assertCount(9, $result['Contents']);
    }

    public function test_list_objects_v1_with_marker_pagination(): void
    {
        $result1 = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'MaxKeys' => 4,
        ]);

        $this->assertTrue($result1['IsTruncated']);
        $this->assertCount(4, $result1['Contents']);

        $marker = $result1['NextMarker']
            ?? $result1['Contents'][count($result1['Contents']) - 1]['Key'];

        $result2 = self::$s3->listObjects([
            'Bucket' => self::$bucket,
            'MaxKeys' => 4,
            'Marker' => $marker,
        ]);

        $this->assertGreaterThanOrEqual(1, count($result2['Contents']));
        $this->assertLessThanOrEqual(5, count($result2['Contents']));

        // Ensure no overlap.
        $keys1 = array_map(fn($c) => $c['Key'], $result1['Contents']);
        $keys2 = array_map(fn($c) => $c['Key'], $result2['Contents']);
        $this->assertEmpty(array_intersect($keys1, $keys2));
    }

    // -----------------------------------------------------------------
    // DeleteObjects (batch)
    // -----------------------------------------------------------------

    public function test_delete_objects_batch(): void
    {
        // Create objects to delete.
        $keysToDelete = [];
        for ($i = 0; $i < 5; $i++) {
            $key = 'batch-delete-' . $i . '.txt';
            $keysToDelete[] = $key;
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => 'delete me ' . $i,
            ]);
        }

        // Batch delete.
        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Objects' => array_map(fn($k) => ['Key' => $k], $keysToDelete),
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertCount(5, $result['Deleted']);

        $deletedKeys = array_map(fn($d) => $d['Key'], $result['Deleted']);
        sort($deletedKeys);
        sort($keysToDelete);
        $this->assertSame($keysToDelete, $deletedKeys);

        // Verify objects are gone.
        foreach ($keysToDelete as $key) {
            try {
                self::$s3->headObject([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                ]);
                $this->fail("Expected 404 for deleted key: {$key}");
            } catch (S3Exception $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_delete_objects_quiet_mode(): void
    {
        $key = 'quiet-delete.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'quiet delete',
        ]);

        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Quiet' => true,
                'Objects' => [['Key' => $key]],
            ],
        ]);

        // In quiet mode, Deleted list should be empty (only errors shown).
        $this->assertEmpty($result['Deleted'] ?? []);
        $this->assertEmpty($result['Errors'] ?? []);
    }

    public function test_delete_objects_non_existent_keys(): void
    {
        // Deleting non-existent keys should succeed silently.
        $result = self::$s3->deleteObjects([
            'Bucket' => self::$bucket,
            'Delete' => [
                'Objects' => [
                    ['Key' => 'nonexistent-1-' . uniqid()],
                    ['Key' => 'nonexistent-2-' . uniqid()],
                ],
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertCount(2, $result['Deleted']);
        $this->assertEmpty($result['Errors'] ?? []);
    }

    // -----------------------------------------------------------------
    // CopyObject
    // -----------------------------------------------------------------

    public function test_copy_object_same_bucket(): void
    {
        // Source already exists from seeding: file1.txt
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-copy.txt',
            'CopySource' => self::$bucket . '/file1.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertNotEmpty($result['CopyObjectResult']['ETag'] ?? $result['ETag'] ?? '');

        // Verify the copy has the same content.
        $getResult = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-copy.txt',
        ]);
        $this->assertSame('content1', (string) $getResult['Body']);
    }

    public function test_copy_object_cross_bucket(): void
    {
        $dstBucket = 'test-copy-dst-bucket';
        self::$s3->createBucket(['Bucket' => $dstBucket]);

        $result = self::$s3->copyObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied-file.txt',
            'CopySource' => self::$bucket . '/dir1/file3.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Verify the copy.
        $getResult = self::$s3->getObject([
            'Bucket' => $dstBucket,
            'Key' => 'copied-file.txt',
        ]);
        $this->assertSame('content3', (string) $getResult['Body']);

        // Clean up.
        self::$s3->deleteObject(['Bucket' => $dstBucket, 'Key' => 'copied-file.txt']);
        self::$s3->deleteBucket(['Bucket' => $dstBucket]);
    }

    public function test_copy_object_with_replace_metadata(): void
    {
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-replace.txt',
            'CopySource' => self::$bucket . '/file1.txt',
            'MetadataDirective' => 'REPLACE',
            'ContentType' => 'text/html',
            'Metadata' => [
                'custom-key' => 'custom-value',
            ],
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Verify the copy has new metadata.
        $headResult = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'file1-replace.txt',
        ]);
        $this->assertSame('text/html', $headResult['ContentType']);
        $this->assertSame('custom-value', $headResult['Metadata']['custom-key'] ?? null);
    }

    public function test_copy_object_preserves_metadata_by_default(): void
    {
        // Put object with metadata.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-source.txt',
            'Body' => 'meta test',
            'ContentType' => 'text/csv',
            'Metadata' => [
                'origin' => 'test',
            ],
        ]);

        // Copy without metadata directive (defaults to COPY).
        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-copy.txt',
            'CopySource' => self::$bucket . '/meta-source.txt',
        ]);

        $headResult = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'meta-copy.txt',
        ]);
        $this->assertSame('text/csv', $headResult['ContentType']);
        $this->assertSame('test', $headResult['Metadata']['origin'] ?? null);
    }
}
