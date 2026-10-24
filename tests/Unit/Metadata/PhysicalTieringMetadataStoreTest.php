<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class PhysicalTieringMetadataStoreTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-tiering-metadata-' . bin2hex(random_bytes(4)) . '.sqlite';
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

    public function test_schema_migrates_to_latest_version(): void
    {
        self::assertSame(SqliteSchema::VERSION, $this->schemaVersion());
        self::assertSame(40, SqliteSchema::VERSION);
    }

    public function test_owner_and_bucket_listing_indexes_are_added_to_existing_v14_database(): void
    {
        $this->store->putObjectMetadata('bucket', 'owned.txt', 'owner', 4, '"etag"', 'text/plain', '/owned.txt');
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('DROP INDEX idx_s3_objects_owner');
        $pdo->exec('DROP INDEX idx_s3_multipart_uploads_owner');
        $pdo->exec('DROP INDEX idx_s3_buckets_owner_name');
        $pdo->exec('DROP INDEX idx_s3_objects_transition_target_tier');
        $pdo->exec('DROP INDEX idx_s3_storage_gc_operation_owner');
        $pdo->exec('ALTER TABLE s3_storage_garbage DROP COLUMN owner_id');
        $pdo->exec('ALTER TABLE s3_storage_garbage DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_tier_transition_jobs DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_restore_jobs DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_notification_queue DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_object_write_intents DROP COLUMN dispatch_id');
        $pdo->exec('DELETE FROM s3_schema_version WHERE version >= 15');

        (new \OpsFour\S3Server\Metadata\Schema\SchemaManager($pdo))->migrate();

        $versionQuery = $pdo->query('SELECT MAX(version) FROM s3_schema_version');
        self::assertInstanceOf(\PDOStatement::class, $versionQuery);
        self::assertSame(SqliteSchema::VERSION, (int) $versionQuery->fetchColumn());

        $objectIndexQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_s3_objects_owner'");
        self::assertInstanceOf(\PDOStatement::class, $objectIndexQuery);
        self::assertSame('idx_s3_objects_owner', $objectIndexQuery->fetchColumn());

        $multipartIndexQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_s3_multipart_uploads_owner'");
        self::assertInstanceOf(\PDOStatement::class, $multipartIndexQuery);
        self::assertSame('idx_s3_multipart_uploads_owner', $multipartIndexQuery->fetchColumn());
        $bucketIndexQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_s3_buckets_owner_name'");
        self::assertInstanceOf(\PDOStatement::class, $bucketIndexQuery);
        self::assertSame('idx_s3_buckets_owner_name', $bucketIndexQuery->fetchColumn());
        $targetIndexQuery = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'idx_s3_objects_transition_target_tier'");
        self::assertInstanceOf(\PDOStatement::class, $targetIndexQuery);
        self::assertSame('idx_s3_objects_transition_target_tier', $targetIndexQuery->fetchColumn());
        self::assertSame(['objectCount' => 1, 'bytesUsed' => 4], $this->store->getOwnerStorageStats('owner'));
    }

    public function test_bucket_generation_is_backfilled_from_v18_and_changes_on_name_reuse(): void
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('ALTER TABLE s3_buckets DROP COLUMN generation');
        $pdo->exec('DROP INDEX idx_s3_storage_gc_operation_owner');
        $pdo->exec('ALTER TABLE s3_storage_garbage DROP COLUMN owner_id');
        $pdo->exec('ALTER TABLE s3_storage_garbage DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_tier_transition_jobs DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_restore_jobs DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_notification_queue DROP COLUMN lease_generation');
        $pdo->exec('ALTER TABLE s3_object_write_intents DROP COLUMN dispatch_id');
        $pdo->exec('DELETE FROM s3_schema_version WHERE version >= 19');
        (new \OpsFour\S3Server\Metadata\Schema\SchemaManager($pdo))->migrate();

        $oldGeneration = $this->store->getBucket('bucket')?->generation;
        self::assertNotNull($oldGeneration);
        self::assertSame(32, strlen($oldGeneration));
        $this->store->deleteBucket('owner', 'bucket');
        $this->store->createBucket('owner', 'bucket', 'us-east-1');
        self::assertNotSame($oldGeneration, $this->store->getBucket('bucket')?->generation);
    }

    public function test_updates_latest_object_placement_and_restore_state(): void
    {
        $this->store->putObjectMetadata(
            'bucket',
            'archive.txt',
            'owner',
            12,
            '"etag"',
            'text/plain',
            '/hot/archive.txt',
        );

        $this->store->updateObjectPlacement(
            bucket: 'bucket',
            key: 'archive.txt',
            versionId: null,
            storageClass: 'GLACIER',
            storageTier: 'cold-archive',
            storagePath: '/cold/archive.txt',
            transitionStatus: 'available',
        );

        $expiresAt = new \DateTimeImmutable('2030-01-02T03:04:05Z');
        $this->store->updateObjectRestoreStateWithTier(
            bucket: 'bucket',
            key: 'archive.txt',
            versionId: null,
            restoreStatus: 'restored',
            restoredStoragePath: '/hot-restored/archive.txt',
            restoreExpiresAt: $expiresAt,
            restoredStorageTier: 'hot',
        );

        $object = $this->store->getObjectMetadata('bucket', 'archive.txt');

        self::assertNotNull($object);
        self::assertSame('GLACIER', $object->storageClass);
        self::assertSame('cold-archive', $object->storageTier);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame('/cold/archive.txt', $object->systemMetadata['storagePath']);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('/hot-restored/archive.txt', $object->restoredStoragePath);
        self::assertSame('2030-01-02T03:04:05+00:00', $object->restoreExpiresAt?->format(\DateTimeInterface::ATOM));
    }

    public function test_placement_updates_preserve_s3_last_modified_timestamp(): void
    {
        $this->store->putObjectMetadata('bucket', 'latest.txt', 'owner', 4, '"latest"', 'text/plain', '/hot/latest.txt');
        $pdo = $this->pdo();
        $stmt = $pdo->prepare('UPDATE s3_objects SET updated_at = ? WHERE bucket = ? AND key_name = ?');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->execute(['2000-01-02T03:04:05Z', 'bucket', 'latest.txt']);

        $this->store->updateObjectPlacement(
            'bucket',
            'latest.txt',
            null,
            'GLACIER',
            'cold',
            '/cold/latest.txt',
            'pending',
            'deep',
        );

        $latest = $this->store->getObjectMetadata('bucket', 'latest.txt');
        self::assertNotNull($latest);
        self::assertSame('2000-01-02T03:04:05+00:00', $latest->lastModified->format(\DateTimeInterface::ATOM));
        self::assertSame('pending', $latest->transitionStatus);

        $this->store->setBucketVersioning('bucket', 'Enabled');
        $versionId = $this->store->putObjectVersioned(
            'bucket',
            'versioned.txt',
            'owner',
            7,
            '"version"',
            'text/plain',
            '/hot/versioned.txt',
        );
        $stmt->execute(['2001-02-03T04:05:06Z', 'bucket', 'versioned.txt']);

        $this->store->updateObjectPlacement(
            'bucket',
            'versioned.txt',
            $versionId,
            'DEEP_ARCHIVE',
            'deep',
            '/deep/versioned.txt',
        );

        $version = $this->store->getObjectMetadataByVersion('bucket', 'versioned.txt', $versionId);
        self::assertNotNull($version);
        self::assertSame('2001-02-03T04:05:06+00:00', $version->lastModified->format(\DateTimeInterface::ATOM));
        self::assertSame('/deep/versioned.txt', $version->systemMetadata['storagePath']);
    }

    public function test_updates_specific_object_version_without_touching_latest(): void
    {
        $this->store->setBucketVersioning('bucket', 'Enabled');
        $firstVersionId = $this->store->putObjectVersioned(
            'bucket',
            'versioned.txt',
            'owner',
            5,
            '"first"',
            'text/plain',
            '/hot/first.txt',
        );
        $latestVersionId = $this->store->putObjectVersioned(
            'bucket',
            'versioned.txt',
            'owner',
            6,
            '"latest"',
            'text/plain',
            '/hot/latest.txt',
        );

        $this->store->updateObjectPlacement(
            bucket: 'bucket',
            key: 'versioned.txt',
            versionId: $firstVersionId,
            storageClass: 'DEEP_ARCHIVE',
            storageTier: 'deep-archive',
            storagePath: '/deep/first.txt',
        );

        $first = $this->store->getObjectMetadataByVersion('bucket', 'versioned.txt', $firstVersionId);
        $latest = $this->store->getObjectMetadataByVersion('bucket', 'versioned.txt', $latestVersionId);

        self::assertNotNull($first);
        self::assertNotNull($latest);
        self::assertSame('DEEP_ARCHIVE', $first->storageClass);
        self::assertSame('deep-archive', $first->storageTier);
        self::assertSame('/deep/first.txt', $first->systemMetadata['storagePath']);
        self::assertSame('STANDARD', $latest->storageClass);
        self::assertSame('STANDARD', $latest->storageTier);
        self::assertSame('/hot/latest.txt', $latest->systemMetadata['storagePath']);
    }

    public function test_update_missing_object_throws_no_such_key(): void
    {
        $this->expectException(NoSuchKeyException::class);

        $this->store->updateObjectPlacement(
            bucket: 'bucket',
            key: 'missing.txt',
            versionId: null,
            storageClass: 'GLACIER',
            storageTier: 'cold',
            storagePath: '/cold/missing.txt',
        );
    }

    public function test_lists_only_expired_restored_objects(): void
    {
        $this->store->putObjectMetadata('bucket', 'expired.txt', 'owner', 1, '"expired"', 'text/plain', '/cold/expired.txt');
        $this->store->putObjectMetadata('bucket', 'active.txt', 'owner', 1, '"active"', 'text/plain', '/cold/active.txt');
        $this->store->updateObjectRestoreStateWithTier(
            'bucket',
            'expired.txt',
            null,
            'restored',
            '/hot/expired.txt',
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
            'hot',
        );
        $this->store->updateObjectRestoreStateWithTier(
            'bucket',
            'active.txt',
            null,
            'restored',
            '/hot/active.txt',
            new \DateTimeImmutable('2020-01-03T00:00:00Z'),
            'hot',
        );

        $expired = $this->store->listExpiredRestoredObjects(new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        self::assertCount(1, $expired);
        self::assertSame('expired.txt', $expired[0]->key);
        self::assertSame('/hot/expired.txt', $expired[0]->restoredStoragePath);
    }

    private function schemaVersion(): int
    {
        $pdo = $this->pdo();
        $stmt = $pdo->query('SELECT MAX(version) FROM s3_schema_version');
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return $value !== false ? (int) $value : 0;
    }

    private function pdo(): \PDO
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('connection');

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);

        return $pdo;
    }
}
