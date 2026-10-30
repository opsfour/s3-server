<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class Crc64NvmeMetadataPersistenceTest extends TestCase
{
    private const string CHECKSUM = 'rosUhgp5mIg=';

    public function test_sqlite_persists_crc64nvme_across_null_and_numbered_versions(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');

        $store->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            9,
            'etag',
            'text/plain',
            'objects/null',
            checksumCrc64Nvme: self::CHECKSUM,
        );

        self::assertSame(
            self::CHECKSUM,
            $store->getObjectMetadata('bucket', 'key')?->systemMetadata['checksum-crc64nvme'],
        );

        $store->setBucketVersioning('bucket', 'Enabled');
        $versionId = $store->putObjectVersioned(
            'bucket',
            'key',
            'owner',
            10,
            'etag-v1',
            'text/plain',
            'objects/v1',
            checksumCrc64Nvme: self::CHECKSUM,
        );

        self::assertSame(
            self::CHECKSUM,
            $store->getObjectMetadataByVersion('bucket', 'key', $versionId)?->systemMetadata['checksum-crc64nvme'],
        );
        self::assertSame(
            self::CHECKSUM,
            $store->listObjectVersions('bucket')['versions'][0]->systemMetadata['checksum-crc64nvme'],
        );
    }

    public function test_checksum_free_replacement_and_delete_marker_do_not_retain_crc64nvme(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            9,
            'etag',
            'text/plain',
            'objects/with-checksum',
            checksumCrc64Nvme: self::CHECKSUM,
        );
        $store->putObjectMetadata('bucket', 'key', 'owner', 9, 'etag-2', 'text/plain', 'objects/without-checksum');

        $replacement = $store->getObjectMetadata('bucket', 'key');
        self::assertNotNull($replacement);
        self::assertArrayNotHasKey('checksum-crc64nvme', $replacement->systemMetadata);

        $store->setBucketVersioning('bucket', 'Suspended');
        $store->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            9,
            'etag-3',
            'text/plain',
            'objects/with-checksum-again',
            checksumCrc64Nvme: self::CHECKSUM,
        );
        $store->deleteObjectVersioned('bucket', 'key', 'owner', suspended: true);

        $marker = $store->getCurrentObjectVersion('bucket', 'key');
        self::assertNotNull($marker);
        self::assertTrue($marker->isDeleteMarker);
        self::assertArrayNotHasKey('checksum-crc64nvme', $marker->systemMetadata);
    }

    public function test_sqlite_crc64nvme_migration_is_nullable_and_does_not_backfill_legacy_rows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate(SqliteSchema::VERSION - 1);
        $pdo->exec("INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES ('bucket', 'owner', 'us-east-1', 'generation')");
        $pdo->exec("INSERT INTO s3_objects (bucket, key_name, owner_id, etag, size, content_type, storage_path) VALUES ('bucket', 'legacy', 'owner', 'etag', 1, 'text/plain', 'objects/legacy')");

        $schema->migrate();

        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $checksum = $pdo->query("SELECT checksum_crc64nvme FROM s3_objects WHERE key_name = 'legacy'");
        if ($checksum === false) {
            throw new \RuntimeException('Legacy checksum state could not be queried.');
        }
        self::assertNull($checksum->fetchColumn());
    }
}
