<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class SqliteMetadataStoreDurabilityTest extends TestCase
{
    private const int SQLITE_CACHE_SIZE = -64000;

    private const int SQLITE_MMAP_SIZE = 268435456;

    private const int SQLITE_SYNCHRONOUS_FULL = 2;

    private const int SQLITE_SYNCHRONOUS_NORMAL = 1;

    private string $path = '';

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_reopened_current_database_uses_full_synchronous_durability(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-durability-' . bin2hex(random_bytes(4)) . '.sqlite';

        $store = new SqliteMetadataStore($this->path);
        $store->initialize();
        unset($store);

        // Match a connection created by the previous runtime setting. The
        // schema is already current, so reopening it does not need a migration.
        $legacyConnection = new \PDO('sqlite:' . $this->path);
        $legacyConnection->exec('PRAGMA synchronous=NORMAL');
        self::assertSame(self::SQLITE_SYNCHRONOUS_NORMAL, $this->synchronousMode($legacyConnection));
        unset($legacyConnection);

        $reopened = new SqliteMetadataStore($this->path);
        $reopened->initialize();

        self::assertSame(self::SQLITE_SYNCHRONOUS_FULL, $this->synchronousMode($this->connection($reopened)));
        self::assertConnectionPerformancePragmas($this->connection($reopened));
    }

    public function test_read_only_connection_explicitly_uses_full_synchronous_mode(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-read-only-durability-' . bin2hex(random_bytes(4)) . '.sqlite';

        $writer = new SqliteMetadataStore($this->path);
        $writer->initialize();
        unset($writer);

        $reader = new SqliteMetadataStore($this->path, readOnly: true);
        $reader->assertSupportedBucketEncryptionInventory();
        $connection = $this->connection($reader);

        self::assertSame(self::SQLITE_SYNCHRONOUS_FULL, $this->synchronousMode($connection));
        self::assertConnectionPerformancePragmas($connection);
        $queryOnly = $connection->query('PRAGMA query_only');
        self::assertInstanceOf(\PDOStatement::class, $queryOnly);
        self::assertSame(1, (int) $queryOnly->fetchColumn());
    }

    public function test_owner_write_lock_acquires_sqlite_writer_before_metadata_reads(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-write-lock-' . bin2hex(random_bytes(4)) . '.sqlite';
        $store = new SqliteMetadataStore($this->path);
        $store->initialize();
        $contender = $this->contendingConnection();

        $store->beginTransaction();
        $store->lockOwnerForUpdate('owner');

        try {
            $contender->exec($this->bucketInsertSql('blocked-bucket'));
            self::fail('A competing SQLite writer bypassed the acquired owner write lock.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('database is locked', $error->getMessage());
        } finally {
            $store->rollback();
        }

        self::assertSame(1, $contender->exec($this->bucketInsertSql('released-bucket')));
    }

    public function test_owner_read_lock_keeps_a_deferred_read_transaction(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-read-lock-' . bin2hex(random_bytes(4)) . '.sqlite';
        $store = new SqliteMetadataStore($this->path);
        $store->initialize();
        $contender = $this->contendingConnection();

        $store->beginTransaction();
        $store->lockOwnerForRead('owner');
        try {
            self::assertSame(1, $contender->exec($this->bucketInsertSql('concurrent-bucket')));
        } finally {
            $store->rollback();
        }
    }

    private function connection(SqliteMetadataStore $store): \PDO
    {
        $property = new \ReflectionProperty($store, 'pdo');
        $connection = $property->getValue($store);
        self::assertInstanceOf(\PDO::class, $connection);

        return $connection;
    }

    private function synchronousMode(\PDO $connection): int
    {
        $statement = $connection->query('PRAGMA synchronous');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function contendingConnection(): \PDO
    {
        $connection = new \PDO('sqlite:' . $this->path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $connection->exec('PRAGMA busy_timeout=0');

        return $connection;
    }

    private function bucketInsertSql(string $bucket): string
    {
        return sprintf(
            "INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES ('%s', 'owner', 'us-east-1', '%s')",
            $bucket,
            bin2hex(random_bytes(16)),
        );
    }

    private static function assertConnectionPerformancePragmas(\PDO $connection): void
    {
        $cacheSize = $connection->query('PRAGMA cache_size');
        self::assertInstanceOf(\PDOStatement::class, $cacheSize);
        self::assertSame(self::SQLITE_CACHE_SIZE, (int) $cacheSize->fetchColumn());

        $mmapSize = $connection->query('PRAGMA mmap_size');
        self::assertInstanceOf(\PDOStatement::class, $mmapSize);
        self::assertSame(self::SQLITE_MMAP_SIZE, (int) $mmapSize->fetchColumn());
    }
}
