<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalRecursiveCleanupAdmissionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_external_store_admits_only_exact_path_cleanup_for_the_candidate_tier(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for an isolated disposable database.");
        }
        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(RecursiveCleanupAdmissionStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'recursive-admission-' . bin2hex(random_bytes(8));

        try {
            $this->insert($pool, $driver, $bucket, 'STANDARD', 'delete_path', 'pending');
            $this->insert($pool, $driver, $bucket, 'STANDARD', 'part_write_intent', 'processing');
            $this->insert($pool, $driver, $bucket, 'COLD', 'abort_multipart', 'pending');
            $metadata->assertNoUnsafeRecursiveCleanup('STANDARD');
            self::assertSame(3, $this->countRows($pool, $driver, $bucket));

            $this->insert($pool, $driver, $bucket, 'STANDARD', 'abort_multipart', 'processing');
            try {
                $metadata->assertNoUnsafeRecursiveCleanup('STANDARD');
                self::fail('Legacy recursive cleanup was admitted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Legacy recursive multipart cleanup blocks local storage admission.', $error->getMessage());
            }
            self::assertSame(4, $this->countRows($pool, $driver, $bucket));

            $this->execute(
                $pool,
                $driver,
                "DELETE FROM s3_storage_garbage WHERE bucket = $1 AND storage_tier = 'STANDARD' AND operation = 'abort_multipart'",
                "DELETE FROM s3_storage_garbage WHERE BINARY bucket = BINARY ? AND BINARY storage_tier = BINARY 'STANDARD' AND operation = 'abort_multipart'",
                [$bucket],
            );
            $this->insert($pool, $driver, $bucket, 'STANDARD', 'DELETE_PATH', 'pending');
            try {
                $metadata->assertNoUnsafeRecursiveCleanup('STANDARD');
                self::fail('Unclassified cleanup was admitted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Unclassified storage cleanup blocks local storage admission.', $error->getMessage());
            }
            self::assertSame(4, $this->countRows($pool, $driver, $bucket));
        } finally {
            $this->execute(
                $pool,
                $driver,
                'DELETE FROM s3_storage_garbage WHERE bucket = $1',
                'DELETE FROM s3_storage_garbage WHERE BINARY bucket = BINARY ?',
                [$bucket],
            );
        }
    }

    private function insert(
        PostgresConnectionPool|MysqlConnectionPool $pool,
        string $driver,
        string $bucket,
        string $storageTier,
        string $operation,
        string $status,
    ): void {
        $this->execute(
            $pool,
            $driver,
            'INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, status, next_attempt_at)
             VALUES ($1, $2, $3, $4, $5, 0)',
            'INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, status, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, 0)',
            [$bucket, $storageTier, '/exact/' . bin2hex(random_bytes(4)), $operation, $status],
        );
    }

    private function countRows(PostgresConnectionPool|MysqlConnectionPool $pool, string $driver, string $bucket): int
    {
        $row = $pool->execute(
            $driver === 'postgres'
                ? 'SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE bucket = $1'
                : 'SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE BINARY bucket = BINARY ?',
            [$bucket],
        )->fetchRow();

        return (int) ($row['n'] ?? 0);
    }

    /** @param list<mixed> $args */
    private function execute(
        PostgresConnectionPool|MysqlConnectionPool $pool,
        string $driver,
        string $postgresSql,
        string $mysqlSql,
        array $args,
    ): void {
        $pool->execute($driver === 'postgres' ? $postgresSql : $mysqlSql, $args);
    }
}
