<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class BucketMultipartCleanupStagingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-bucket-parts-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_commit_persists_only_exact_committed_part_paths_and_preserves_legacy_jobs(): void
    {
        $store = $this->store();
        $store->createBucket('owner', 'target', 'us-east-1');
        $store->createBucket('owner', 'foreign', 'us-east-1');
        $store->createMultipartUpload('target-a', 'target', 'a', 'owner');
        $store->createMultipartUpload('target-b', 'target', 'b', 'owner');
        $store->createMultipartUpload('foreign-a', 'foreign', 'a', 'owner');
        $store->putPart('target-a', 1, 'etag', 1, 'parts/target/a-1');
        $store->putPart('target-a', 2, 'etag', 1, 'parts/target/a-2');
        $store->putPart('target-b', 1, 'etag', 1, 'parts/target/b-1');
        $store->putPart('foreign-a', 1, 'etag', 1, 'parts/foreign/a-1');

        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, key_name, upload_id, next_attempt_at)
            VALUES ('target', 'STANDARD', '', 'abort_multipart', 'old-key', 'old-upload', 0)");

        $store->transaction(function () use ($store): void {
            $store->stageBucketMultipartCleanup('target', 'STANDARD');
            $store->deleteBucket('owner', 'target');
        });
        $reopened = new SqliteMetadataStore($this->path);
        $reopened->initialize();
        self::assertNull($reopened->getBucket('target'));
        self::assertNotNull($reopened->getMultipartUpload('foreign-a'));
        $rows = $pdo->query('SELECT bucket, storage_tier, storage_path, operation, upload_id FROM s3_storage_garbage ORDER BY id');
        self::assertInstanceOf(\PDOStatement::class, $rows);
        self::assertEqualsCanonicalizing([
            ['bucket' => 'target', 'storage_tier' => 'STANDARD', 'storage_path' => '', 'operation' => 'abort_multipart', 'upload_id' => 'old-upload'],
            ['bucket' => 'target', 'storage_tier' => 'STANDARD', 'storage_path' => 'parts/target/a-1', 'operation' => 'delete_path', 'upload_id' => 'target-a'],
            ['bucket' => 'target', 'storage_tier' => 'STANDARD', 'storage_path' => 'parts/target/a-2', 'operation' => 'delete_path', 'upload_id' => 'target-a'],
            ['bucket' => 'target', 'storage_tier' => 'STANDARD', 'storage_path' => 'parts/target/b-1', 'operation' => 'delete_path', 'upload_id' => 'target-b'],
        ], $rows->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function test_failed_bucket_delete_rolls_back_every_staged_part_job(): void
    {
        $store = $this->store();
        $store->createBucket('owner', 'target', 'us-east-1');
        $store->createMultipartUpload('target-a', 'target', 'a', 'owner');
        $store->putPart('target-a', 1, 'etag', 1, 'parts/target/a-1');
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec("CREATE TRIGGER reject_target_delete BEFORE DELETE ON s3_buckets
            WHEN OLD.name = 'target' BEGIN SELECT RAISE(ABORT, 'blocked'); END");

        try {
            $store->transaction(function () use ($store): void {
                $store->stageBucketMultipartCleanup('target', 'STANDARD');
                $store->deleteBucket('owner', 'target');
            });
            self::fail('Bucket delete must fail.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('blocked', $error->getMessage());
        }
        self::assertNotNull($store->getMultipartUpload('target-a'));
        self::assertCount(1, $store->getParts('target-a'));
        $count = $pdo->query('SELECT COUNT(*) FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function test_cleanup_capacity_rejection_rolls_back_bulk_rows_and_bucket_mutation(): void
    {
        $store = $this->store();
        $store->createBucket('owner', 'target', 'us-east-1');
        $store->createMultipartUpload('target-a', 'target', 'a', 'owner');
        $store->putPart('target-a', 1, 'etag', 1, 'parts/target/a-1');
        $store->putPart('target-a', 2, 'etag', 1, 'parts/target/a-2');
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 899999 WHERE id = 1');

        try {
            $store->transaction(function () use ($store): void {
                $store->stageBucketMultipartCleanup('target', 'STANDARD');
                $store->deleteBucket('owner', 'target');
            });
            self::fail('Bulk cleanup exceeded the one remaining normal slot.');
        } catch (StorageCleanupCapacityException) {
        }

        self::assertNotNull($store->getBucket('target'));
        self::assertNotNull($store->getMultipartUpload('target-a'));
        self::assertCount(2, $store->getParts('target-a'));
        $garbage = $pdo->query('SELECT COUNT(*) FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $garbage);
        self::assertSame(0, (int) $garbage->fetchColumn());
        self::assertSame(899_999, $store->getStorageCleanupCount());
    }

    private function store(): SqliteMetadataStore
    {
        $store = new SqliteMetadataStore($this->path);
        $store->initialize();

        return $store;
    }
}
