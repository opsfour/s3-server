<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\ExternalStorageIntegrityReferenceEnumerator as Inventory;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator as Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalStorageIntegrityReferenceEnumeratorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_all_queries_preserve_tiers_sizes_pagination_and_ignore_stale_restore_jobs(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for an isolated disposable database.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartUploadTierStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $inventory = $driver === 'postgres'
            ? Inventory::forPostgresDsn($dsn)
            : Inventory::forMysqlDsn($dsn);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'integrity-' . $suffix;
        $owner = 'owner-' . $suffix;
        $plainUpload = 'plain-' . $suffix;
        $encryptedUpload = 'encrypted-' . $suffix;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->putObjectMetadata($bucket, 'current', $owner, 3, 'etag', 'text/plain', '/hot/current');
            $this->execute(
                $pool,
                $driver,
                "UPDATE s3_objects SET storage_tier = 'HOT', restored_storage_path = '/hot/restored', restored_storage_tier = 'HOT', restore_status = 'restored' WHERE bucket = $1 AND key_name = 'current'",
                "UPDATE s3_objects SET storage_tier = 'HOT', restored_storage_path = '/hot/restored', restored_storage_tier = 'HOT', restore_status = 'restored' WHERE bucket = ? AND BINARY key_name = BINARY 'current'",
                [$bucket],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_objects (bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, storage_tier, storage_path) VALUES ($1, 'versioned', 'v1', FALSE, FALSE, $2, 'etag', 4, 'COLD', '/cold/version')",
                "INSERT INTO s3_objects (bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, storage_tier, storage_path, user_metadata) VALUES (?, 'versioned', 'v1', 0, 0, ?, 'etag', 4, 'COLD', '/cold/version', '{}')",
                [$bucket, $owner],
            );
            $metadata->createMultipartUploadWithTier($plainUpload, $bucket, 'plain', $owner, 'HOT', userMetadata: ['__mpu-part-format' => 'plaintext-v1']);
            $metadata->putPart($plainUpload, 1, 'etag', 5, '/hot/plain-part');
            $metadata->createMultipartUploadWithTier($encryptedUpload, $bucket, 'encrypted', $owner, 'COLD', userMetadata: ['__mpu-part-format' => 'encrypted-v2']);
            $metadata->putPart($encryptedUpload, 1, 'etag', 7, '/cold/encrypted-part');
            $matchedJob = $metadata->enqueueRestoreJob($bucket, 'current', 'null', 'HOT', '/hot/current', 1);
            $staleJob = $metadata->enqueueRestoreJob($bucket, 'current', 'null', 'hot', '/wrong/source', 1);
            $completedJob = $metadata->enqueueRestoreJob($bucket, 'current', 'null', 'HOT', '/hot/current', 1);
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_restore_jobs SET restored_storage_path = $1 WHERE id = $2',
                'UPDATE s3_restore_jobs SET restored_storage_path = ? WHERE id = ?',
                ['/hot/restored', $matchedJob],
            );
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_restore_jobs SET restored_storage_path = $1 WHERE id = $2',
                'UPDATE s3_restore_jobs SET restored_storage_path = ? WHERE id = ?',
                ['/wrong/target', $staleJob],
            );
            $this->execute(
                $pool,
                $driver,
                "UPDATE s3_restore_jobs SET status = 'completed', restored_storage_path = $1 WHERE id = $2",
                "UPDATE s3_restore_jobs SET status = 'completed', restored_storage_path = ? WHERE id = ?",
                ['/hot/restored', $completedJob],
            );

            $firstObjectPage = $inventory->page(Source::OBJECT, null, 1);
            self::assertCount(1, $firstObjectPage);
            self::assertNotSame([], $inventory->page(Source::OBJECT, $firstObjectPage[0]['id'], 1));
            self::assertSame([['HOT', '/hot/restored', 3]], $this->placements($inventory, Source::RESTORED_OBJECT));
            self::assertSame(
                [['HOT', '/hot/plain-part', 5], ['COLD', '/cold/encrypted-part', null]],
                $this->placements($inventory, Source::MULTIPART_PART),
            );
            self::assertSame([['HOT', '/hot/current', 3]], $this->placements($inventory, Source::RESTORE_SOURCE));
            self::assertSame([['HOT', '/hot/restored', 3]], $this->placements($inventory, Source::RESTORE_TARGET));
            self::assertSame(
                [['HOT', '/hot/current', 3], ['COLD', '/cold/version', 4]],
                $this->placements($inventory, Source::OBJECT),
            );
        } finally {
            $this->execute($pool, $driver, 'DELETE FROM s3_restore_jobs WHERE bucket = $1', 'DELETE FROM s3_restore_jobs WHERE bucket = ?', [$bucket]);
            $this->execute($pool, $driver, 'DELETE FROM s3_parts WHERE upload_id IN ($1, $2)', 'DELETE FROM s3_parts WHERE upload_id IN (?, ?)', [$plainUpload, $encryptedUpload]);
            $this->execute($pool, $driver, 'DELETE FROM s3_multipart_uploads WHERE bucket = $1', 'DELETE FROM s3_multipart_uploads WHERE bucket = ?', [$bucket]);
            $this->execute($pool, $driver, 'DELETE FROM s3_objects WHERE bucket = $1', 'DELETE FROM s3_objects WHERE bucket = ?', [$bucket]);
            $this->execute($pool, $driver, 'DELETE FROM s3_buckets WHERE name = $1', 'DELETE FROM s3_buckets WHERE name = ?', [$bucket]);
            $inventory->close();
            $pool->close();
        }
    }

    /** @return list<array{?string, string, ?int}> */
    private function placements(Inventory $inventory, string $source): array
    {
        return array_map(
            static fn(array $row): array => [$row['storage_tier'], $row['path'], $row['expected_size']],
            $inventory->page($source, null, 10_000),
        );
    }

    /**
     * @param PostgresConnectionPool|MysqlConnectionPool $pool
     * @param list<mixed> $params
     */
    private function execute(PostgresConnectionPool|MysqlConnectionPool $pool, string $driver, string $postgres, string $mysql, array $params): void
    {
        $pool->execute($driver === 'postgres' ? $postgres : $mysql, $params);
    }
}
