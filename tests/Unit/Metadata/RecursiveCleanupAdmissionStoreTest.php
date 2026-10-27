<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecursiveCleanupAdmissionStoreTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $store;

    private \PDO $writer;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-recursive-cleanup-admission-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
        $this->writer = new \PDO('sqlite:' . $this->path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function tearDown(): void
    {
        unset($this->writer, $this->store);
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_exact_path_work_is_admitted_and_other_tiers_are_isolated(): void
    {
        $this->insert('STANDARD', 'delete_path');
        $this->insert('STANDARD', 'part_write_intent');
        $this->insert('COLD', 'abort_multipart');

        $this->store->assertNoUnsafeRecursiveCleanup('STANDARD');

        self::assertSame(3, $this->rowCount());
    }

    /** @return iterable<string, array{string}> */
    public static function statuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'processing' => ['processing'];
        yield 'completed residue' => ['completed'];
    }

    #[DataProvider('statuses')]
    public function test_legacy_abort_blocks_without_mutating_any_status(string $status): void
    {
        $this->insert('STANDARD', 'abort_multipart', $status);

        try {
            $this->store->assertNoUnsafeRecursiveCleanup('STANDARD');
            self::fail('Legacy recursive cleanup was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Legacy recursive multipart cleanup blocks local storage admission.', $error->getMessage());
        }

        self::assertSame(1, $this->rowCount());
        self::assertSame($status, $this->scalar('SELECT status FROM s3_storage_garbage'));
    }

    public function test_unknown_operation_fails_closed_without_exposing_or_deleting_it(): void
    {
        $this->insert('STANDARD', 'DELETE_PATH');

        try {
            $this->store->assertNoUnsafeRecursiveCleanup('STANDARD');
            self::fail('Unclassified cleanup work was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Unclassified storage cleanup blocks local storage admission.', $error->getMessage());
            self::assertStringNotContainsString('DELETE_PATH', $error->getMessage());
        }

        self::assertSame(1, $this->rowCount());
    }

    public function test_null_operation_in_a_drifted_legacy_table_fails_closed(): void
    {
        $path = $this->path . '-nullable';
        $writer = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $writer->exec('CREATE TABLE s3_storage_garbage (storage_tier TEXT NOT NULL, operation TEXT NULL)');
        $writer->exec("CREATE INDEX idx_s3_storage_gc_recursive_unsafe
            ON s3_storage_garbage(storage_tier, operation)
            WHERE CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1");
        $writer->exec("INSERT INTO s3_storage_garbage (storage_tier, operation) VALUES ('STANDARD', NULL)");
        unset($writer);

        try {
            (new SqliteMetadataStore($path))->assertNoUnsafeRecursiveCleanup('STANDARD');
            self::fail('A NULL cleanup operation was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Unclassified storage cleanup blocks local storage admission.', $error->getMessage());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_large_safe_inventory_uses_the_partial_unsafe_index(): void
    {
        $this->writer->exec(<<<'SQL'
            WITH RECURSIVE sequence(n) AS (
                VALUES(1)
                UNION ALL
                SELECT n + 1 FROM sequence WHERE n < 50000
            )
            INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, status, next_attempt_at)
            SELECT 'bulk', 'STANDARD', '/bulk/' || printf('%08d', n), 'delete_path', 'pending', 0
            FROM sequence
            SQL);
        $this->insert('COLD', 'abort_multipart');
        $this->writer->exec('ANALYZE s3_storage_garbage');

        $plan = $this->writer->query(<<<'SQL'
            EXPLAIN QUERY PLAN
            SELECT operation FROM s3_storage_garbage INDEXED BY idx_s3_storage_gc_recursive_unsafe
            WHERE storage_tier = 'STANDARD'
              AND CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1
            LIMIT 1
            SQL);
        self::assertInstanceOf(\PDOStatement::class, $plan);
        $details = implode("\n", $plan->fetchAll(\PDO::FETCH_COLUMN, 3));
        self::assertStringContainsString('idx_s3_storage_gc_recursive_unsafe', $details);
        self::assertStringNotContainsString('SCAN s3_storage_garbage', $details);

        $this->store->assertNoUnsafeRecursiveCleanup('STANDARD');
        self::assertSame(50001, $this->rowCount());
    }

    public function test_v37_migration_adds_partial_index_without_mutating_queue_rows(): void
    {
        $this->insert('STANDARD', 'delete_path');
        $this->insert('COLD', 'abort_multipart', 'processing');
        $this->writer->exec('DROP INDEX idx_s3_storage_gc_recursive_unsafe');
        $this->writer->exec('DELETE FROM s3_schema_version WHERE version >= 37');
        $this->writer->exec("DELETE FROM s3_migration_checkpoints WHERE migration_name = 'object_part_layouts'");

        (new SchemaManager($this->writer))->migrate();

        self::assertSame(2, $this->rowCount());
        self::assertSame(SqliteSchema::VERSION, (int) $this->scalar('SELECT MAX(version) FROM s3_schema_version'));
        $indexSql = (string) $this->scalar("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_s3_storage_gc_recursive_unsafe'");
        self::assertStringContainsString("CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1", $indexSql);
    }

    public function test_cached_and_observed_wrappers_delegate_the_read_only_check(): void
    {
        $this->insert('STANDARD', 'abort_multipart');
        $stores = [
            new CachedMetadataStoreDecorator($this->store),
            new ObservedMetadataStore($this->store, new MetricsCollector(), 'sqlite'),
        ];

        foreach ($stores as $store) {
            self::assertInstanceOf(RecursiveCleanupAdmissionStore::class, $store);
            try {
                $store->assertNoUnsafeRecursiveCleanup('STANDARD');
                self::fail($store::class . ' admitted recursive cleanup.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Legacy recursive multipart cleanup', $error->getMessage());
            }
        }

        self::assertSame(1, $this->rowCount());
    }

    public function test_wrappers_without_the_capability_fail_closed(): void
    {
        $metadata = $this->createMock(MetadataStore::class);

        foreach ([
            new CachedMetadataStoreDecorator($metadata),
            new ObservedMetadataStore($metadata, new MetricsCollector(), 'test'),
        ] as $store) {
            try {
                $store->assertNoUnsafeRecursiveCleanup('STANDARD');
                self::fail($store::class . ' admitted an incapable inner store.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('lacks recursive cleanup admission', $error->getMessage());
            }
        }
    }

    public function test_parallel_sqlite_wrapper_delegates_the_read_only_check(): void
    {
        $this->insert('STANDARD', 'abort_multipart');
        $parallel = new ParallelSqliteMetadataStore($this->path, 1);

        try {
            self::assertInstanceOf(RecursiveCleanupAdmissionStore::class, $parallel);
            $parallel->assertNoUnsafeRecursiveCleanup('STANDARD');
            self::fail('Parallel SQLite admitted recursive cleanup.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Legacy recursive multipart cleanup', $error->getMessage());
        } finally {
            $parallel->shutdown();
        }

        self::assertSame(1, $this->rowCount());
    }

    private function insert(string $storageTier, string $operation, string $status = 'pending'): void
    {
        $statement = $this->writer->prepare(
            'INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, status, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, 0)',
        );
        $statement->execute(['bucket', $storageTier, '/exact/' . bin2hex(random_bytes(4)), $operation, $status]);
    }

    private function rowCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM s3_storage_garbage');
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->writer->query($sql);
        if (! $statement instanceof \PDOStatement) {
            throw new \RuntimeException('SQLite admission test query failed.');
        }

        return $statement->fetchColumn();
    }
}
