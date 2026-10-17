<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\SqliteStorageIntegrityReferenceEnumerator as Inventory;
use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator as Source;
use PHPUnit\Framework\TestCase;

final class SqliteStorageIntegrityReferenceEnumeratorTest extends TestCase
{
    private string $databasePath;

    private \PDO $writer;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-storage-integrity-sqlite-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->putObjectMetadata('bucket', 'current', 'owner', 3, 'etag', 'text/plain', '/hot/current');
        $metadata->createMultipartUploadWithTier('plain-upload', 'bucket', 'plain', 'owner', 'HOT', userMetadata: ['__mpu-part-format' => 'plaintext-v1']);
        $metadata->putPart('plain-upload', 1, 'etag', 5, '/hot/plain-part');
        $metadata->createMultipartUploadWithTier('encrypted-upload', 'bucket', 'encrypted', 'owner', 'COLD', userMetadata: ['__mpu-part-format' => 'encrypted-v2']);
        $metadata->putPart('encrypted-upload', 1, 'etag', 7, '/cold/encrypted-part');

        $this->writer = new \PDO('sqlite:' . $this->databasePath, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->writer->exec("UPDATE s3_objects SET storage_tier = 'HOT', restored_storage_path = '/hot/restored', restored_storage_tier = 'HOT', restore_status = 'restored' WHERE key_name = 'current'");
        $this->writer->exec("INSERT INTO s3_objects (bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, storage_tier, storage_path) VALUES ('bucket', 'versioned', 'v1', 0, 0, 'owner', 'etag', 4, 'COLD', '/cold/version')");
        $this->writer->exec("INSERT INTO s3_restore_jobs (bucket, key_name, version_id, source_tier, source_storage_path, restored_storage_path, restore_days, next_attempt_at) VALUES ('bucket', 'current', 'null', 'HOT', '/hot/current', '/hot/restored', 1, 0)");
        $this->writer->exec("INSERT INTO s3_restore_jobs (bucket, key_name, version_id, source_tier, source_storage_path, restored_storage_path, restore_days, next_attempt_at) VALUES ('bucket', 'current', 'null', 'hot', '/wrong/source', '/wrong/target', 1, 0)");
        $this->writer->exec("INSERT INTO s3_restore_jobs (bucket, key_name, version_id, source_tier, source_storage_path, restored_storage_path, restore_days, status, next_attempt_at) VALUES ('bucket', 'current', 'null', 'HOT', '/hot/current', '/hot/restored', 1, 'completed', 0)");
    }

    protected function tearDown(): void
    {
        unset($this->writer);
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_all_required_sources_preserve_exact_tiers_sizes_and_keyset_pages(): void
    {
        $inventory = new Inventory($this->databasePath);
        $objectPage = $inventory->page(Source::OBJECT, null, 1);
        self::assertCount(1, $objectPage);
        self::assertCount(1, $inventory->page(Source::OBJECT, $objectPage[0]['id'], 1));

        self::assertSame(
            [
                ['HOT', '/hot/current', 3],
                ['COLD', '/cold/version', 4],
            ],
            array_map(static fn(array $row): array => [$row['storage_tier'], $row['path'], $row['expected_size']], $this->all($inventory, Source::OBJECT)),
        );
        self::assertSame([['HOT', '/hot/restored', 3]], $this->placements($inventory, Source::RESTORED_OBJECT));
        self::assertSame(
            [
                ['HOT', '/hot/plain-part', 5],
                ['COLD', '/cold/encrypted-part', null],
            ],
            $this->placements($inventory, Source::MULTIPART_PART),
        );
        self::assertSame([['HOT', '/hot/current', 3]], $this->placements($inventory, Source::RESTORE_SOURCE));
        self::assertSame([['HOT', '/hot/restored', 3]], $this->placements($inventory, Source::RESTORE_TARGET));
    }

    /** @return list<array{source: string, id: string, storage_tier: ?string, path: string, expected_size: ?int}> */
    private function all(Inventory $inventory, string $source): array
    {
        return $inventory->page($source, null, 10_000);
    }

    /** @return list<array{?string, string, ?int}> */
    private function placements(Inventory $inventory, string $source): array
    {
        return array_map(
            static fn(array $row): array => [$row['storage_tier'], $row['path'], $row['expected_size']],
            $this->all($inventory, $source),
        );
    }
}
