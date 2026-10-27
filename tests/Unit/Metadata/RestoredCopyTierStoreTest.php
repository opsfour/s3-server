<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class RestoredCopyTierStoreTest extends TestCase
{
    public function test_schema_upgrade_preserves_unknown_legacy_restored_tier(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(29);
        $pdo->exec("INSERT INTO s3_objects
            (bucket, key_name, owner_id, etag, size, storage_path, restored_storage_path)
            VALUES ('bucket', 'key', 'owner', 'etag', 1, 'cold/old', 'restore/old')");
        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $row = $pdo->query("SELECT restored_storage_tier FROM s3_objects WHERE key_name = 'key'");
        self::assertInstanceOf(\PDOStatement::class, $row);
        self::assertNull($row->fetchColumn());
    }

    public function test_partial_legacy_schema_without_objects_table_fails_layout_migration(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(29);
        $pdo->exec('DROP TABLE s3_objects');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQLite metadata schema is corrupted: s3_objects is missing before object part layout migration.');
        $schema->migrate();
    }

    public function test_restore_copy_path_tier_and_expiry_are_atomic_for_current_and_versioned_rows(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $versionId = $store->putObjectVersioned('bucket', 'versioned', 'owner', 1, 'etag', 'text/plain', 'cold/versioned');
        $store->putObjectMetadata('bucket', 'current', 'owner', 1, 'etag', 'text/plain', 'cold/current');
        $pdo = (new \ReflectionClass($store))->getMethod('connection')->invoke($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec("UPDATE s3_objects SET updated_at = '2000-01-02T03:04:05Z' WHERE bucket = 'bucket'");
        $store->updateObjectRestoreState('bucket', 'versioned', $versionId, 'pending');
        $store->updateObjectRestoreState('bucket', 'current', null, 'pending');
        $expires = new \DateTimeImmutable('+1 day');

        $store->updateObjectRestoreStateWithTier('bucket', 'versioned', $versionId, 'restored', 'hot/versioned', $expires, 'HOT');
        $store->updateObjectRestoreStateWithTier('bucket', 'current', null, 'restored', 'warm/current', $expires, 'WARM');
        $versioned = $store->getObjectMetadataByVersion('bucket', 'versioned', $versionId);
        $current = $store->getObjectMetadata('bucket', 'current');
        self::assertNotNull($versioned);
        self::assertNotNull($current);
        self::assertSame(['hot/versioned', 'HOT'], [$versioned->restoredStoragePath, $versioned->restoredStorageTier]);
        self::assertSame(['warm/current', 'WARM'], [$current->restoredStoragePath, $current->restoredStorageTier]);
        self::assertSame('2000-01-02T03:04:05+00:00', $versioned->lastModified->format(\DateTimeInterface::ATOM));
        self::assertSame('2000-01-02T03:04:05+00:00', $current->lastModified->format(\DateTimeInterface::ATOM));
        self::assertContains('HOT', $store->listReferencedStorageTiers());
        self::assertContains('WARM', $store->listReferencedStorageTiers());

        $store->updateObjectRestoreState('bucket', 'versioned', $versionId, null);
        $store->updateObjectRestoreState('bucket', 'current', null, null);
        $clearedVersion = $store->getObjectMetadataByVersion('bucket', 'versioned', $versionId);
        $clearedCurrent = $store->getObjectMetadata('bucket', 'current');
        self::assertNotNull($clearedVersion);
        self::assertNotNull($clearedCurrent);
        self::assertNull($clearedVersion->restoredStoragePath);
        self::assertNull($clearedVersion->restoredStorageTier);
        self::assertNull($clearedVersion->restoreExpiresAt);
        self::assertNull($clearedCurrent->restoredStoragePath);
        self::assertNull($clearedCurrent->restoredStorageTier);
        self::assertNull($clearedCurrent->restoreExpiresAt);
        self::assertSame('2000-01-02T03:04:05+00:00', $clearedVersion->lastModified->format(\DateTimeInterface::ATOM));
        self::assertSame('2000-01-02T03:04:05+00:00', $clearedCurrent->lastModified->format(\DateTimeInterface::ATOM));
    }

    public function test_old_api_cannot_publish_path_without_tier_and_legacy_path_blocks_inventory(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata('bucket', 'key', 'owner', 1, 'etag', 'text/plain', 'cold/key');
        try {
            $store->updateObjectRestoreState('bucket', 'key', null, 'restored', 'hot/key', new \DateTimeImmutable('+1 day'));
            self::fail('A physical copy without a tier must not be published.');
        } catch (\InvalidArgumentException) {
            self::assertNull($store->getObjectMetadata('bucket', 'key')?->restoredStoragePath);
        }

        $pdo = (new \ReflectionClass($store))->getMethod('connection')->invoke($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec("UPDATE s3_objects SET restored_storage_path = 'hot/legacy', restored_storage_tier = NULL WHERE key_name = 'key'");
        $this->expectException(\UnexpectedValueException::class);
        $store->listReferencedStorageTiers();
    }
}
