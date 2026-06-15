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

    public function test_schema_migrates_to_physical_tiering_version(): void
    {
        self::assertSame(SqliteSchema::VERSION, $this->schemaVersion());
        self::assertSame(11, SqliteSchema::VERSION);
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
        $this->store->updateObjectRestoreState(
            bucket: 'bucket',
            key: 'archive.txt',
            versionId: null,
            restoreStatus: 'restored',
            restoredStoragePath: '/hot-restored/archive.txt',
            restoreExpiresAt: $expiresAt,
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
        $this->store->updateObjectRestoreState(
            'bucket',
            'expired.txt',
            null,
            'restored',
            '/hot/expired.txt',
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
        );
        $this->store->updateObjectRestoreState(
            'bucket',
            'active.txt',
            null,
            'restored',
            '/hot/active.txt',
            new \DateTimeImmutable('2020-01-03T00:00:00Z'),
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
        $method->setAccessible(true);

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);

        return $pdo;
    }
}
