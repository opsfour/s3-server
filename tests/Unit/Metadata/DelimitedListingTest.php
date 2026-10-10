<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class DelimitedListingTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-delimited-listing-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
        $this->store->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_a_large_single_group_is_not_reported_as_truncated(): void
    {
        $this->putGroupedKeys(210);

        $page = $this->store->listObjects('bucket', prefix: 'grouped/', delimiter: '/', maxKeys: 1);

        self::assertSame(['grouped/a/'], $page->commonPrefixes);
        self::assertSame(1, $page->keyCount);
        self::assertFalse($page->isTruncated);
        self::assertNull($page->nextContinuationToken);
    }

    public function test_continuation_skips_all_keys_from_the_previous_group(): void
    {
        $this->putGroupedKeys(210);
        $this->put('grouped/b/001');

        $first = $this->store->listObjects('bucket', prefix: 'grouped/', delimiter: '/', maxKeys: 1);
        self::assertSame(['grouped/a/'], $first->commonPrefixes);
        self::assertTrue($first->isTruncated);
        self::assertNotNull($first->nextContinuationToken);

        $second = $this->store->listObjects(
            'bucket',
            prefix: 'grouped/',
            delimiter: '/',
            maxKeys: 1,
            continuationToken: $first->nextContinuationToken,
        );
        self::assertSame(['grouped/b/'], $second->commonPrefixes);
        self::assertFalse($second->isTruncated);
    }

    public function test_start_after_filters_common_prefixes_before_the_marker(): void
    {
        $this->put('grouped/a/001');
        $this->put('grouped/a/002');
        $this->put('grouped/b/001');

        $page = $this->store->listObjects(
            'bucket',
            prefix: 'grouped/',
            delimiter: '/',
            maxKeys: 1,
            startAfter: 'grouped/a/001',
        );

        self::assertSame(['grouped/b/'], $page->commonPrefixes);
        self::assertFalse($page->isTruncated);
    }

    public function test_numeric_common_prefix_uses_binary_order_for_v2_start_after_and_continuation(): void
    {
        foreach (['9a', '9b', 'z'] as $key) {
            $this->put($key);
        }

        // Binary order places "9" after "10". PHP's string <= would compare
        // these numeric strings as numbers and omit this common prefix.
        $first = $this->store->listObjects('bucket', delimiter: '9', maxKeys: 1, startAfter: '10');
        self::assertSame(['9'], $first->commonPrefixes);
        self::assertSame([], $first->objects);
        self::assertTrue($first->isTruncated);
        self::assertNotNull($first->nextContinuationToken);

        $second = $this->store->listObjects(
            'bucket',
            delimiter: '9',
            maxKeys: 1,
            continuationToken: $first->nextContinuationToken,
        );
        self::assertSame([], $second->commonPrefixes);
        self::assertSame(['z'], array_map(static fn($object): string => $object->key, $second->objects));
        self::assertFalse($second->isTruncated);
    }

    public function test_numeric_common_prefix_uses_binary_order_for_version_and_upload_markers(): void
    {
        foreach (['9a', '9b', 'z'] as $key) {
            $this->put($key);
            $this->store->createMultipartUpload('upload-' . $key, 'bucket', $key, 'owner');
        }

        $firstVersions = $this->store->listObjectVersions('bucket', delimiter: '9', maxKeys: 1, keyMarker: '10');
        self::assertSame(['9'], $firstVersions['commonPrefixes']);
        self::assertTrue($firstVersions['isTruncated']);
        $secondVersions = $this->store->listObjectVersions(
            'bucket',
            delimiter: '9',
            maxKeys: 1,
            keyMarker: $firstVersions['nextKeyMarker'],
            versionIdMarker: $firstVersions['nextVersionIdMarker'],
        );
        self::assertSame(['z'], array_map(static fn($object): string => $object->key, $secondVersions['versions']));
        self::assertSame([], $secondVersions['commonPrefixes']);
        self::assertFalse($secondVersions['isTruncated']);

        $firstUploads = $this->store->listMultipartUploads('bucket', delimiter: '9', maxUploads: 1, keyMarker: '10');
        self::assertSame(['9'], $firstUploads['commonPrefixes']);
        self::assertTrue($firstUploads['isTruncated']);
        $secondUploads = $this->store->listMultipartUploads(
            'bucket',
            delimiter: '9',
            maxUploads: 1,
            keyMarker: $firstUploads['nextKeyMarker'],
            uploadIdMarker: $firstUploads['nextUploadIdMarker'],
        );
        self::assertSame(['z'], array_column($secondUploads['uploads'], 'key_name'));
        self::assertSame([], $secondUploads['commonPrefixes']);
        self::assertFalse($secondUploads['isTruncated']);
    }

    public function test_version_listing_does_not_split_or_repeat_a_common_prefix(): void
    {
        $this->putGroupedKeys(530);
        $this->put('grouped/b/001');

        $first = $this->store->listObjectVersions('bucket', prefix: 'grouped/', delimiter: '/', maxKeys: 1);
        self::assertSame(['grouped/a/'], $first['commonPrefixes']);
        self::assertTrue($first['isTruncated']);
        self::assertNotNull($first['nextKeyMarker']);

        $second = $this->store->listObjectVersions(
            'bucket',
            prefix: 'grouped/',
            delimiter: '/',
            maxKeys: 1,
            keyMarker: $first['nextKeyMarker'],
            versionIdMarker: $first['nextVersionIdMarker'],
        );
        self::assertSame(['grouped/b/'], $second['commonPrefixes']);
        self::assertFalse($second['isTruncated']);
    }

    public function test_multipart_listing_counts_a_large_group_once_and_resumes_after_it(): void
    {
        for ($i = 1; $i <= 530; $i++) {
            $this->store->createMultipartUpload(sprintf('upload-%04d', $i), 'bucket', sprintf('grouped/a/%04d', $i), 'owner');
        }
        $this->store->createMultipartUpload('upload-next', 'bucket', 'grouped/b/001', 'owner');

        $first = $this->store->listMultipartUploads('bucket', prefix: 'grouped/', delimiter: '/', maxUploads: 1);
        self::assertSame(['grouped/a/'], $first['commonPrefixes']);
        self::assertTrue($first['isTruncated']);

        $second = $this->store->listMultipartUploads(
            'bucket',
            prefix: 'grouped/',
            delimiter: '/',
            maxUploads: 1,
            keyMarker: $first['nextKeyMarker'],
            uploadIdMarker: $first['nextUploadIdMarker'],
        );
        self::assertSame(['grouped/b/'], $second['commonPrefixes']);
        self::assertFalse($second['isTruncated']);
        self::assertSame([], $second['uploads']);
    }

    public function test_same_key_multipart_uploads_page_by_initiation_time_then_bytewise_id(): void
    {
        foreach (['z-earliest', 'b-tie', 'a-tie'] as $uploadId) {
            $this->store->createMultipartUpload($uploadId, 'bucket', 'same-key', 'owner');
        }
        $pdo = new \PDO('sqlite:' . $this->path);
        $statement = $pdo->prepare('UPDATE s3_multipart_uploads SET created_at = ? WHERE upload_id = ?');
        $statement->execute(['2024-01-01T00:00:00Z', 'z-earliest']);
        $statement->execute(['2024-01-02T00:00:00Z', 'b-tie']);
        $statement->execute(['2024-01-02T00:00:00Z', 'a-tie']);

        foreach ([null, '/'] as $delimiter) {
            $uploadIds = [];
            $keyMarker = null;
            $uploadIdMarker = null;
            do {
                $page = $this->store->listMultipartUploads(
                    'bucket',
                    delimiter: $delimiter,
                    maxUploads: 1,
                    keyMarker: $keyMarker,
                    uploadIdMarker: $uploadIdMarker,
                );
                array_push($uploadIds, ...array_column($page['uploads'], 'upload_id'));
                $keyMarker = $page['nextKeyMarker'];
                $uploadIdMarker = $page['nextUploadIdMarker'];
            } while ($page['isTruncated']);

            self::assertSame(['z-earliest', 'a-tie', 'b-tie'], $uploadIds);
        }
    }

    public function test_generation_bound_multipart_listing_filters_stale_rows_before_prefixes_and_pagination(): void
    {
        $bucket = $this->store->getBucket('bucket');
        self::assertNotNull($bucket);
        foreach ([
            ['stale-same-owner', 'grouped/a/old', 'owner'],
            ['stale-other-owner', 'grouped/b/old', 'other'],
            ['current-first', 'grouped/c/new', 'owner'],
            ['current-second', 'grouped/d/new', 'owner'],
        ] as [$uploadId, $key, $owner]) {
            $this->store->createMultipartUpload($uploadId, 'bucket', $key, $owner);
        }
        $pdo = new \PDO('sqlite:' . $this->path);
        $stmt = $pdo->prepare('UPDATE s3_multipart_uploads SET bucket_generation = ? WHERE upload_id = ?');
        $stmt->execute([str_repeat('a', 32), 'stale-same-owner']);
        $stmt->execute([str_repeat('a', 32), 'stale-other-owner']);

        $first = $this->store->listMultipartUploadsForBucket($bucket, prefix: 'grouped/', delimiter: '/', maxUploads: 1);
        self::assertSame(['grouped/c/'], $first['commonPrefixes']);
        self::assertSame('grouped/c/', $first['nextKeyMarker']);
        self::assertNull($first['nextUploadIdMarker']);
        self::assertTrue($first['isTruncated']);

        $second = $this->store->listMultipartUploadsForBucket(
            $bucket,
            prefix: 'grouped/',
            delimiter: '/',
            maxUploads: 1,
            keyMarker: $first['nextKeyMarker'],
            uploadIdMarker: $first['nextUploadIdMarker'],
        );
        self::assertSame(['grouped/d/'], $second['commonPrefixes']);
        self::assertFalse($second['isTruncated']);

        $plain = $this->store->listMultipartUploadsForBucket($bucket, prefix: 'grouped/');
        self::assertSame(['current-first', 'current-second'], array_column($plain['uploads'], 'upload_id'));
    }

    public function test_generation_bound_multipart_listing_limits_legacy_uploads_to_bucket_owner(): void
    {
        $bucket = $this->store->getBucket('bucket');
        self::assertNotNull($bucket);
        $this->store->createMultipartUpload('legacy-owner', 'bucket', 'legacy/owner', 'owner');
        $this->store->createMultipartUpload('legacy-other', 'bucket', 'legacy/other', 'other');
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("UPDATE s3_multipart_uploads SET bucket_generation = '' WHERE upload_id IN ('legacy-owner', 'legacy-other')");

        $listing = $this->store->listMultipartUploadsForBucket($bucket, prefix: 'legacy/');
        self::assertSame(['legacy-owner'], array_column($listing['uploads'], 'upload_id'));
    }

    public function test_version_marker_keeps_latest_first_then_uses_timestamp_order(): void
    {
        $firstId = $this->store->putObjectVersioned('bucket', 'versioned', 'owner', 1, '"etag"', 'text/plain', 'first');
        $secondId = $this->store->putObjectVersioned('bucket', 'versioned', 'owner', 1, '"etag"', 'text/plain', 'second');
        $thirdId = $this->store->putObjectVersioned('bucket', 'versioned', 'owner', 1, '"etag"', 'text/plain', 'third');

        $pdo = new \PDO('sqlite:' . $this->path);
        $statement = $pdo->prepare('UPDATE s3_objects SET created_at = ? WHERE version_id = ?');
        $statement->execute(['2024-01-01T00:00:00Z', $firstId]);
        $statement->execute(['2024-01-03T00:00:00Z', $secondId]);
        $statement->execute(['2024-01-02T00:00:00Z', $thirdId]);

        $first = $this->store->listObjectVersions('bucket', maxKeys: 1);
        self::assertSame($thirdId, $first['versions'][0]->versionId);
        self::assertTrue($first['isTruncated']);

        $second = $this->store->listObjectVersions(
            'bucket',
            maxKeys: 1,
            keyMarker: $first['nextKeyMarker'],
            versionIdMarker: $first['nextVersionIdMarker'],
        );
        self::assertSame($secondId, $second['versions'][0]->versionId);
    }

    public function test_prefix_filters_are_case_sensitive_across_listing_types(): void
    {
        $this->put('grouped/item');
        $this->put('Grouped/item');
        $this->store->createMultipartUpload('lower-upload', 'bucket', 'grouped/upload', 'owner');
        $this->store->createMultipartUpload('upper-upload', 'bucket', 'Grouped/upload', 'owner');

        $objects = $this->store->listObjects('bucket', prefix: 'grouped/');
        self::assertSame(['grouped/item'], array_map(static fn($item): string => $item->key, $objects->objects));

        $versions = $this->store->listObjectVersions('bucket', prefix: 'grouped/');
        self::assertSame(['grouped/item'], array_map(static fn($item): string => $item->key, $versions['versions']));

        $uploads = $this->store->listMultipartUploads('bucket', prefix: 'grouped/');
        self::assertSame(['grouped/upload'], array_column($uploads['uploads'], 'key_name'));

        $cutoff = new \DateTimeImmutable('2100-01-01T00:00:00Z');
        $expired = $this->store->listExpiredObjects('bucket', 'grouped/', $cutoff);
        self::assertSame(['grouped/item'], array_map(static fn($item): string => $item->key, $expired));

        $oldUploads = $this->store->listExpiredMultipartUploads('bucket', 1, prefix: 'grouped/', createdBefore: $cutoff);
        self::assertSame(['grouped/upload'], array_column($oldUploads, 'key_name'));
    }

    private function putGroupedKeys(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->put(sprintf('grouped/a/%03d', $i));
        }
    }

    private function put(string $key): void
    {
        $this->store->putObjectMetadata('bucket', $key, 'owner', 1, '"etag"', 'text/plain', $key);
    }
}
