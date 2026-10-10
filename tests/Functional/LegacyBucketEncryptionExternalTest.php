<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class LegacyBucketEncryptionExternalTest extends TestCase
{
    public function test_legacy_config_blocks_sql_admission_and_migration_until_explicit_repair(): void
    {
        $driver = getenv('S3_TEST_LEGACY_ENCRYPTION_DRIVER');
        $dsn = getenv('S3_TEST_LEGACY_ENCRYPTION_DSN');
        if (! in_array($driver, ['postgres', 'mysql'], true)
            || ! is_string($dsn)
            || preg_match('/(?:^|[;\s])dbname=s3legacy_encryption_[a-z0-9_]+(?:[;\s]|$)/', $dsn) !== 1
            || getenv('S3_TEST_DISPOSABLE_LEGACY_ENCRYPTION') !== '1') {
            self::markTestSkipped('Requires a dedicated disposable legacy encryption database.');
        }

        $migration = new CommandTester(new MetadataMigrateCommand());
        $options = ['--metadata-driver' => $driver, '--metadata-dsn' => $dsn];
        self::assertSame(Command::SUCCESS, $migration->execute($options));
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $store = $driver === 'postgres' ? PostgresMetadataStore::fromDsn($dsn) : MysqlMetadataStore::fromDsn($dsn);

        try {
            $store->createBucket('owner', 'legacy-encryption-bucket', 'us-east-1');
            $pool->query($driver === 'postgres'
                ? 'ALTER TABLE s3_encryption_configs DROP CONSTRAINT chk_s3_encryption_configs_supported'
                : 'ALTER TABLE s3_encryption_configs DROP CHECK chk_s3_encryption_configs_supported');
            $latest = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
            $encryptionMigrationBase = $driver === 'postgres' ? $latest - 2 : $latest - 3;
            $pool->execute(
                $driver === 'postgres' ? 'UPDATE s3_schema_version SET version = $1' : 'UPDATE s3_schema_version SET version = ?',
                // MySQL now has restore-expiry widening after the encryption
                // and noncurrent-time migrations. Rewind to the version before
                // the encryption invariant so the dropped constraint is rebuilt.
                [$encryptionMigrationBase],
            );
            foreach ([
                ['AES256', 'alias/legacy', false],
                ['AES256', null, true],
                ['aws:kms', 'alias/legacy', false],
            ] as [$algorithm, $keyId, $bucketKey]) {
                $pool->execute(
                    $driver === 'postgres'
                        ? 'INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled) VALUES ($1, $2, $3, $4) ON CONFLICT (bucket) DO UPDATE SET sse_algorithm = EXCLUDED.sse_algorithm, kms_master_key_id = EXCLUDED.kms_master_key_id, bucket_key_enabled = EXCLUDED.bucket_key_enabled'
                        : 'INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE sse_algorithm = VALUES(sse_algorithm), kms_master_key_id = VALUES(kms_master_key_id), bucket_key_enabled = VALUES(bucket_key_enabled)',
                    ['legacy-encryption-bucket', $algorithm, $keyId, $driver === 'postgres' ? $bucketKey : (int) $bucketKey],
                );

                foreach ([
                    fn() => MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false),
                    fn() => $store->assertCurrentSchema(),
                ] as $operation) {
                    try {
                        $operation();
                        self::fail('An API accepted a schema without the encryption invariant.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('run metadata:migrate', $error->getMessage());
                        self::assertStringNotContainsString('alias/legacy', $error->getMessage());
                    }
                }
                try {
                    $store->getBucketEncryption('legacy-encryption-bucket');
                    self::fail('An API accepted a legacy encryption row.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Stored bucket encryption configuration is unsupported', $error->getMessage());
                }
                self::assertSame(Command::FAILURE, $migration->execute($options));
                self::assertStringNotContainsString('alias/legacy', $migration->getDisplay());
            }

            $pool->execute(
                $driver === 'postgres'
                    ? "UPDATE s3_encryption_configs SET sse_algorithm = 'AES256', kms_master_key_id = NULL, bucket_key_enabled = FALSE WHERE bucket = $1"
                    : "UPDATE s3_encryption_configs SET sse_algorithm = 'AES256', kms_master_key_id = NULL, bucket_key_enabled = 0 WHERE bucket = ?",
                ['legacy-encryption-bucket'],
            );
            self::assertSame(Command::SUCCESS, $migration->execute($options));
            MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false);
            $store->assertCurrentSchema();
            self::assertSame('AES256', $store->getBucketEncryption('legacy-encryption-bucket')['sseAlgorithm'] ?? null);

            // Simulate a process that completed the constraint DDL but exited
            // after both final DDL migrations but before publishing the schema
            // version. Retrying the constraint and noncurrent column must be safe.
            $pool->execute(
                $driver === 'postgres'
                    ? 'DELETE FROM s3_schema_version WHERE version >= $1'
                    : 'DELETE FROM s3_schema_version WHERE version >= ?',
                [$latest - 1],
            );
            self::assertSame(Command::SUCCESS, $migration->execute($options));
            $store->assertCurrentSchema();

            $constraintRejected = false;
            try {
                $pool->execute(
                    $driver === 'postgres'
                        ? "UPDATE s3_encryption_configs SET kms_master_key_id = 'alias/rejected' WHERE bucket = $1"
                        : "UPDATE s3_encryption_configs SET kms_master_key_id = 'alias/rejected' WHERE bucket = ?",
                    ['legacy-encryption-bucket'],
                );
            } catch (\Throwable) {
                $constraintRejected = true;
            }
            self::assertTrue($constraintRejected, 'The current schema must reject direct invalid encryption SQL.');
            self::assertSame('AES256', $store->getBucketEncryption('legacy-encryption-bucket')['sseAlgorithm'] ?? null);
        } finally {
            $pool->close();
        }
    }

    public function test_noncurrent_time_migration_backfills_conservatively_and_new_demotions_use_their_own_clock(): void
    {
        $driver = getenv('S3_TEST_LEGACY_ENCRYPTION_DRIVER');
        $dsn = getenv('S3_TEST_LEGACY_ENCRYPTION_DSN');
        if (! in_array($driver, ['postgres', 'mysql'], true)
            || ! is_string($dsn)
            || preg_match('/(?:^|[;\s])dbname=s3legacy_encryption_[a-z0-9_]+(?:[;\s]|$)/', $dsn) !== 1
            || getenv('S3_TEST_DISPOSABLE_LEGACY_ENCRYPTION') !== '1') {
            self::markTestSkipped('Requires a dedicated disposable legacy encryption database.');
        }

        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $store = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $bucket = 'noncurrent-time-' . bin2hex(random_bytes(6));
        $versions = [];
        $migrated = false;

        try {
            $store->createBucket('owner', $bucket, 'us-east-1');
            $store->setBucketVersioning($bucket, 'Enabled');
            $versions[] = $store->putObjectVersioned($bucket, 'legacy', 'owner', 1, 'legacy-1', 'text/plain', 'legacy-1');
            $versions[] = $store->putObjectVersioned($bucket, 'legacy', 'owner', 1, 'legacy-2', 'text/plain', 'legacy-2');
            $pool->execute(
                $driver === 'postgres'
                    ? "UPDATE s3_objects SET created_at = '2000-01-01T00:00:00Z' WHERE bucket = $1 AND version_id = $2"
                    : "UPDATE s3_objects SET created_at = '2000-01-01 00:00:00' WHERE bucket = ? AND version_id = ?",
                [$bucket, $versions[0]],
            );
            if ($driver === 'postgres') {
                $pool->query('DROP INDEX idx_s3_objects_noncurrent_at');
                $pool->query('ALTER TABLE s3_objects DROP COLUMN noncurrent_at');
            } else {
                $pool->query('ALTER TABLE s3_objects DROP INDEX idx_s3_objects_noncurrent_at');
                $pool->query('ALTER TABLE s3_objects DROP COLUMN noncurrent_at');
            }
            $latest = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
            $noncurrentMigrationBase = $driver === 'postgres' ? $latest - 1 : $latest - 2;
            $pool->execute(
                $driver === 'postgres' ? 'UPDATE s3_schema_version SET version = $1' : 'UPDATE s3_schema_version SET version = ?',
                [$noncurrentMigrationBase],
            );

            $migration = new CommandTester(new MetadataMigrateCommand());
            self::assertSame(Command::SUCCESS, $migration->execute(['--metadata-driver' => $driver, '--metadata-dsn' => $dsn]));
            $store = MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false);
            $migrated = true;
            $legacy = $store->getObjectMetadataByVersion($bucket, 'legacy', $versions[0]);
            self::assertNotNull($legacy);
            self::assertSame(946684800, (new \DateTimeImmutable($legacy->systemMetadata['createdAt']))->getTimestamp());
            self::assertArrayHasKey('noncurrentAt', $legacy->systemMetadata);
            self::assertSame([], $store->listExpiredNoncurrentVersions($bucket, null, 1));

            $fresh = $store->putObjectVersioned($bucket, 'fresh', 'owner', 1, 'fresh-1', 'text/plain', 'fresh-1');
            $versions[] = $fresh;
            $pool->execute(
                $driver === 'postgres'
                    ? "UPDATE s3_objects SET created_at = '2000-01-01T00:00:00Z' WHERE bucket = $1 AND version_id = $2"
                    : "UPDATE s3_objects SET created_at = '2000-01-01 00:00:00' WHERE bucket = ? AND version_id = ?",
                [$bucket, $fresh],
            );
            $versions[] = $store->putObjectVersioned($bucket, 'fresh', 'owner', 1, 'fresh-2', 'text/plain', 'fresh-2');
            self::assertSame([], $store->listExpiredNoncurrentVersions($bucket, null, 1));

            $pool->execute(
                $driver === 'postgres'
                    ? "UPDATE s3_objects SET noncurrent_at = '2000-01-02T00:00:00Z' WHERE bucket = $1 AND version_id = $2"
                    : "UPDATE s3_objects SET noncurrent_at = '2000-01-02 00:00:00' WHERE bucket = ? AND version_id = ?",
                [$bucket, $fresh],
            );
            self::assertCount(1, $store->listExpiredNoncurrentVersions($bucket, null, 1));

            $store->deleteObjectVersion($bucket, 'fresh', $versions[3]);
            $promoted = $store->getObjectMetadataByVersion($bucket, 'fresh', $fresh);
            self::assertNotNull($promoted);
            self::assertArrayNotHasKey('noncurrentAt', $promoted->systemMetadata);
        } finally {
            if ($migrated) {
                foreach ([['legacy', $versions[1] ?? null], ['legacy', $versions[0] ?? null], ['fresh', $versions[3] ?? null], ['fresh', $versions[2] ?? null]] as [$key, $version]) {
                    if (is_string($version)) {
                        $store->deleteObjectVersion($bucket, $key, $version);
                    }
                }
                if ($store->getBucketOwner($bucket) === 'owner') {
                    $store->deleteBucket('owner', $bucket);
                }
            }
            $pool->close();
        }
    }
}
