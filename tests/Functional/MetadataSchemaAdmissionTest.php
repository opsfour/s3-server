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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function Amp\async;

final class MetadataSchemaAdmissionTest extends TestCase
{
    public function test_two_api_starts_wait_for_single_migration_publication(): void
    {
        [$driver, $dsn, $pool] = self::database();

        $latest = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
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

    public function test_current_schema_admission_rejects_multipart_completion_capacity_drift(): void
    {
        [$driver, $dsn, $pool] = self::database();
        $capacity = $pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow();
        self::assertNotNull($capacity, 'Bootstrap the dedicated test database before this test.');
        $original = (int) ($capacity['retained_count'] ?? -1);
        self::assertGreaterThanOrEqual(0, $original);
        $drifted = $original === 0 ? 1 : $original - 1;

        try {
            $pool->execute(
                $driver === 'postgres'
                    ? 'UPDATE s3_multipart_completion_capacity SET retained_count = $1 WHERE id = 1'
                    : 'UPDATE s3_multipart_completion_capacity SET retained_count = ? WHERE id = 1',
                [$drifted],
            );
            foreach (['factory', 'injected store'] as $path) {
                try {
                    if ($path === 'factory') {
                        MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false);
                    } else {
                        $store = $driver === 'postgres'
                            ? PostgresMetadataStore::fromDsn($dsn)
                            : MysqlMetadataStore::fromDsn($dsn);
                        $store->assertCurrentSchema();
                    }
                    self::fail("The {$path} accepted a drifted multipart completion capacity counter.");
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Multipart completion recovery capacity counter is inconsistent', $error->getMessage());
                }
            }
        } finally {
            try {
                $pool->execute(
                    $driver === 'postgres'
                        ? 'UPDATE s3_multipart_completion_capacity SET retained_count = $1 WHERE id = 1'
                        : 'UPDATE s3_multipart_completion_capacity SET retained_count = ? WHERE id = 1',
                    [$original],
                );
            } finally {
                $pool->close();
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function completionCapacityTriggers(): iterable
    {
        yield 'insert' => ['insert'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('completionCapacityTriggers')]
    public function test_schema_admission_rejects_a_missing_multipart_completion_trigger(string $operation): void
    {
        [$driver, $dsn, $pool] = self::database();
        $trigger = 's3_multipart_completion_capacity_' . $operation;
        $pool->query('DROP TRIGGER ' . $trigger . ($driver === 'postgres' ? ' ON s3_multipart_write_intents' : ''));

        try {
            try {
                if ($driver === 'postgres') {
                    MetadataStoreFactory::create('postgres', ['dsn' => $dsn], migrate: false);
                } else {
                    // MySQL serving roles intentionally have no TRIGGER privilege.
                    // The DDL-capable migrator verifies the complete inventory.
                    MetadataStoreFactory::create('mysql', ['dsn' => $dsn], migrate: true);
                }
                self::fail("Schema admission accepted a missing multipart completion {$operation} trigger.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('recovery triggers are missing', $error->getMessage());
            }
        } finally {
            $restored = false;
            $statements = $driver === 'postgres'
                ? PostgresSchema::getCreateStatements()
                : MysqlSchema::getCreateStatements();
            foreach ($statements as $statement) {
                if (str_starts_with(ltrim($statement), 'CREATE TRIGGER ' . $trigger . ' ')) {
                    $pool->query($statement);
                    $restored = true;
                    break;
                }
            }
            $pool->close();
            self::assertTrue($restored, "The multipart completion {$operation} trigger fixture must restore its database state.");
        }
    }

    /** @return array{string, string, PostgresConnectionPool|MysqlConnectionPool} */
    private static function database(): array
    {
        $driver = getenv('S3_TEST_SCHEMA_ADMISSION_DRIVER');
        $dsn = getenv('S3_TEST_SCHEMA_ADMISSION_DSN');
        if (! in_array($driver, ['postgres', 'mysql'], true)
            || ! is_string($dsn)
            || preg_match('/(?:^|[;\s])dbname=s3audit_schema_admission(?:[;\s]|$)/i', $dsn) !== 1
            || getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires a dedicated disposable s3audit_schema_admission database and destructive migration opt-in.');
        }

        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $database = $pool->query($driver === 'postgres'
            ? 'SELECT current_database() AS database_name'
            : 'SELECT DATABASE() AS database_name')->fetchRow();
        if (($database['database_name'] ?? null) !== 's3audit_schema_admission') {
            $pool->close();
            self::fail('Schema admission tests require the disposable s3audit_schema_admission database.');
        }

        return [$driver, $dsn, $pool];
    }
}
