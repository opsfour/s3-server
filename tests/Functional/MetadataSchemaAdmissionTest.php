<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Console\QuotaCommand;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function Amp\async;

final class MetadataSchemaAdmissionTest extends TestCase
{
    public function test_two_api_starts_wait_for_single_migration_publication(): void
    {
        $driver = getenv('S3_TEST_SCHEMA_ADMISSION_DRIVER');
        $dsn = getenv('S3_TEST_SCHEMA_ADMISSION_DSN');
        if (! in_array($driver, ['postgres', 'mysql'], true)
            || ! is_string($dsn)
            || preg_match('/(?:^|[;\s])dbname=s3audit_schema_admission(?:[;\s]|$)/i', $dsn) !== 1
            || getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires a dedicated disposable s3audit_schema_admission database and destructive migration opt-in.');
        }

        $latest = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));

        $original = $pool->query('SELECT version, applied_at, description FROM s3_schema_version WHERE version = ' . $latest)->fetchRow();
        self::assertNotNull($original, 'Bootstrap the dedicated test database before this test.');
        $originalCount = $pool->query('SELECT COUNT(*) AS total FROM s3_schema_version WHERE version = ' . $latest)->fetchRow();
        self::assertSame(1, (int) ($originalCount['total'] ?? 0), 'The dedicated test database must have exactly one current version row.');

        try {
            $pool->execute('DELETE FROM s3_schema_version WHERE version = ' . $latest);
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (" . ($latest - 1) . ", 'Admission test pending migration')");

            $starts = [
                async(static fn() => MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false)),
                async(static fn() => MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false)),
            ];
            foreach ($starts as $start) {
                try {
                    $start->await();
                    self::fail('An API node started before the migration published its current version.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('run metadata:migrate', $error->getMessage());
                }
            }
            $injected = $driver === 'postgres'
                ? PostgresMetadataStore::fromDsn($dsn)
                : MysqlMetadataStore::fromDsn($dsn);
            try {
                $injected->assertCurrentSchema();
                self::fail('An injected SQL metadata store accepted a stale schema.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('run metadata:migrate', $error->getMessage());
            }
            self::assertNull($pool->query('SELECT version FROM s3_schema_version WHERE version = ' . $latest)->fetchRow());

            $quota = new CommandTester(new QuotaCommand());
            self::assertSame(Command::FAILURE, $quota->execute([
                'action' => 'list',
                '--metadata-driver' => $driver,
                '--metadata-dsn' => $dsn,
            ]));
            self::assertNull($pool->query('SELECT version FROM s3_schema_version WHERE version = ' . $latest)->fetchRow());

            // The one bootstrap completes and publishes the version last.
            $migrator = new CommandTester(new MetadataMigrateCommand());
            self::assertSame(Command::SUCCESS, $migrator->execute([
                '--metadata-driver' => $driver,
                '--metadata-dsn' => $dsn,
            ]));
            $after = [
                async(static fn() => MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false)),
                async(static fn() => MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false)),
            ];
            foreach ($after as $start) {
                $start->await();
            }
            $injected->assertCurrentSchema();
            self::assertSame(Command::SUCCESS, $quota->execute([
                'action' => 'list',
                '--metadata-driver' => $driver,
                '--metadata-dsn' => $dsn,
            ]));
            $versionRow = $pool->query('SELECT COUNT(*) AS total FROM s3_schema_version WHERE version = ' . $latest)->fetchRow();
            self::assertNotNull($versionRow);
            self::assertArrayHasKey('total', $versionRow);
            self::assertSame(1, (int) $versionRow['total']);
        } finally {
            try {
                $pool->execute('DELETE FROM s3_schema_version WHERE version = ' . $latest);
                $pool->execute('DELETE FROM s3_schema_version WHERE version = ' . ($latest - 1) . " AND description = 'Admission test pending migration'");
                $pool->execute(
                    $driver === 'postgres'
                        ? 'INSERT INTO s3_schema_version (version, applied_at, description) VALUES ($1, $2, $3)'
                        : 'INSERT INTO s3_schema_version (version, applied_at, description) VALUES (?, ?, ?)',
                    [$original['version'], $original['applied_at'], $original['description']],
                );
            } finally {
                $pool->close();
            }
        }
    }
}
