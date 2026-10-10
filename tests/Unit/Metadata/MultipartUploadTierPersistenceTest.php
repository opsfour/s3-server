<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MultipartUploadTierPersistenceTest extends TestCase
{
    public function test_upgrade_keeps_existing_upload_tier_unknown(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(28);
        $pdo->exec("INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id)
            VALUES ('legacy-upload', 'bucket', 'key', 'owner')");
        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $row = $pdo->query("SELECT storage_tier FROM s3_multipart_uploads WHERE upload_id = 'legacy-upload'");
        self::assertInstanceOf(\PDOStatement::class, $row);
        self::assertNull($row->fetchColumn());
    }

    public function test_pinned_uploads_drive_inventory_and_exact_part_cleanup_tiers(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->createMultipartUploadWithTier('hot-upload', 'bucket', 'a', 'owner', 'HOT');
        $store->createMultipartUploadWithTier('cold-upload', 'bucket', 'b', 'owner', 'COLD');
        $store->putPart('hot-upload', 1, 'etag', 1, 'parts/hot');
        $store->putPart('cold-upload', 1, 'etag', 1, 'parts/cold');

        self::assertSame('HOT', $store->getMultipartUpload('hot-upload')['storage_tier'] ?? null);
        self::assertSame(['COLD', 'HOT'], $store->listReferencedStorageTiers());
        $store->transaction(function () use ($store): void {
            $store->stageBucketMultipartCleanup('bucket', 'WRONG-DEFAULT');
            $store->deleteBucket('owner', 'bucket');
        });
        $jobs = $store->dequeueStorageGarbage(10);
        self::assertCount(2, $jobs);
        $byPath = [];
        foreach ($jobs as $job) {
            $byPath[$job['storage_path']] = $job['storage_tier'];
        }
        self::assertSame(['parts/hot' => 'HOT', 'parts/cold' => 'COLD'], $byPath);
    }

    public function test_legacy_unknown_tier_blocks_inventory_and_bulk_delete(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->createMultipartUpload('upload', 'bucket', 'key', 'owner');
        $store->putPart('upload', 1, 'etag', 1, 'parts/path');
        $pdo = (new \ReflectionClass($store))->getMethod('connection')->invoke($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec("UPDATE s3_multipart_uploads SET storage_tier = NULL WHERE upload_id = 'upload'");

        try {
            $store->listReferencedStorageTiers();
            self::fail('Unknown legacy tier must block API startup.');
        } catch (\UnexpectedValueException) {
            $upload = $store->getMultipartUpload('upload');
            self::assertNotNull($upload);
            self::assertNull($upload['storage_tier']);
        }
        try {
            $store->transaction(function () use ($store): void {
                $store->stageBucketMultipartCleanup('bucket', 'STANDARD');
                $store->deleteBucket('owner', 'bucket');
            });
            self::fail('Unknown legacy tier must block bucket cleanup.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no pinned storage tier', $error->getMessage());
        }
        self::assertNotNull($store->getMultipartUpload('upload'));
        self::assertSame([], $store->dequeueStorageGarbage(1));
    }

    public function test_part_and_completion_intents_reject_mismatched_tiers(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'HOT');
        $bucketInfo = $store->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $generation = $bucketInfo->generation;

        try {
            $store->createMultipartPartWriteIntent('bucket', $generation, 'COLD', 'upload', 'parts/wrong');
            self::fail('Part intent may not cross physical tiers.');
        } catch (NoSuchUploadException) {
            self::assertSame([], $store->dequeueStorageGarbage(1));
        }

        $wrong = new MultipartWriteIntent('attempt', 'upload', 'bucket', $generation, 'owner', 'COLD', 'assembly', 'final', 'digest', null, microtime(true) + 60, microtime(true) + 60);
        $this->expectException(NoSuchUploadException::class);
        $store->createMultipartWriteIntent($wrong);
    }
}
