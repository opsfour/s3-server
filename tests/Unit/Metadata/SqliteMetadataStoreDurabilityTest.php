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
