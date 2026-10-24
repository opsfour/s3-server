<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class StorageCleanupCapacitySchemaTest extends TestCase
{
    public function test_migration_backfills_all_cleanup_rows_without_a_hard_maximum(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(39);
        $pdo->exec("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, next_attempt_at)
            VALUES ('bucket', 'STANDARD', 'delete', 'delete_path', 0),
                   ('bucket', 'STANDARD', 'abort', 'abort_multipart', 0),
                   ('bucket', 'STANDARD', 'part', 'part_write_intent', 0)");
        $schema->migrate();

        self::assertSame(2, $this->sqlInt($pdo, 'SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1'));
        self::assertSame(0, $this->sqlInt($pdo, 'SELECT SUM(recovery_admission) FROM s3_storage_garbage'));
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 1000001 WHERE id = 1');
        self::assertSame(1_000_001, $this->sqlInt($pdo, 'SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1'));
    }

    public function test_normal_and_recovery_boundaries_and_drain_are_enforced_by_triggers(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        (new SchemaManager($pdo))->migrate();
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 900000 WHERE id = 1');
        $this->rejects(fn() => $this->insert($pdo, 'normal', false));
        $this->insert($pdo, 'recovery', true);
        self::assertSame(900_001, $this->cleanupCount($pdo));

        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 1000000 WHERE id = 1');
        $this->rejects(fn() => $this->insert($pdo, 'hard-cap', true));
        self::assertSame(1, $this->sqlInt($pdo, "SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = 'recovery'"));
        $pdo->exec("DELETE FROM s3_storage_garbage WHERE storage_path = 'recovery'");
        self::assertSame(999_999, $this->cleanupCount($pdo));
    }

    public function test_multirow_insert_and_operation_transitions_are_atomic_and_admission_is_immutable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        (new SchemaManager($pdo))->migrate();
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 899999 WHERE id = 1');
        $this->rejects(fn() => $pdo->exec("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, next_attempt_at) VALUES
            ('bucket', 'STANDARD', 'one', 'delete_path', 0),
            ('bucket', 'STANDARD', 'two', 'delete_path', 0)"));
        self::assertSame(0, $this->sqlInt($pdo, 'SELECT COUNT(*) FROM s3_storage_garbage'));
        self::assertSame(899_999, $this->cleanupCount($pdo));

        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 0 WHERE id = 1');
        $pdo->exec("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, next_attempt_at)
            VALUES ('bucket', 'STANDARD', 'part', 'part_write_intent', 0)");
        $pdo->exec("UPDATE s3_storage_garbage SET operation = 'delete_path' WHERE storage_path = 'part'");
        self::assertSame(1, $this->cleanupCount($pdo));
        $pdo->exec("UPDATE s3_storage_garbage SET operation = 'part_write_intent' WHERE storage_path = 'part'");
        self::assertSame(0, $this->cleanupCount($pdo));
        $this->rejects(fn() => $pdo->exec("UPDATE s3_storage_garbage SET recovery_admission = 1 WHERE storage_path = 'part'"));
    }

    public function test_store_translates_only_cleanup_exhaustion_to_typed_slowdown(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $pdo = $this->pdo($store);
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 900000 WHERE id = 1');
        try {
            $store->enqueueStorageGarbage('bucket', 'STANDARD', 'normal');
            self::fail('Normal cleanup admission exceeded its boundary.');
        } catch (StorageCleanupCapacityException $error) {
            self::assertSame('SlowDown', $error->getErrorCode());
        }
        $store->enqueueRecoveryStorageGarbage('bucket', 'STANDARD', 'recovery');
        self::assertSame(900_001, $store->getStorageCleanupCount());
    }

    public function test_two_path_owning_transaction_rolls_back_when_only_one_normal_slot_remains(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata('bucket', 'key', 'owner', 4, 'etag', 'text/plain', 'primary');
        $pdo = $this->pdo($store);
        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 899999 WHERE id = 1');

        try {
            $store->transaction(function () use ($store): void {
                $store->enqueueStorageGarbage('bucket', 'STANDARD', 'primary');
                $store->enqueueStorageGarbage('bucket', 'STANDARD', 'restored');
                $store->deleteObjectMetadata('bucket', 'key');
            });
            self::fail('Two-path cleanup exceeded the one remaining normal slot.');
        } catch (StorageCleanupCapacityException) {
        }

        self::assertTrue($store->objectExists('bucket', 'key'));
        self::assertSame(0, $this->sqlInt($pdo, 'SELECT COUNT(*) FROM s3_storage_garbage'));
        self::assertSame(899_999, $store->getStorageCleanupCount());
    }

    public function test_startup_fails_on_counter_or_trigger_drift(): void
    {
        $counterDrift = new SqliteMetadataStore(':memory:');
        $counterDrift->initialize();
        $this->pdo($counterDrift)->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 1 WHERE id = 1');
        try {
            $counterDrift->initialize();
            self::fail('Counter drift was accepted at startup.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('counter is inconsistent', $error->getMessage());
        }

        $triggerDrift = new SqliteMetadataStore(':memory:');
        $triggerDrift->initialize();
        $this->pdo($triggerDrift)->exec('DROP TRIGGER s3_storage_cleanup_capacity_insert');
        try {
            $triggerDrift->initialize();
            self::fail('Missing cleanup trigger was accepted at startup.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('triggers are missing', $error->getMessage());
        }
    }

    public function test_drop_all_removes_capacity_tables(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate();
        $schema->dropAll();

        foreach ([
            's3_storage_cleanup_capacity',
            's3_write_intent_capacity',
            's3_multipart_completion_capacity',
        ] as $table) {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);
            self::assertSame(0, (int) $statement->fetchColumn(), $table);
        }
    }

    private function insert(\PDO $pdo, string $path, bool $recovery): void
    {
        $statement = $pdo->prepare('INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, recovery_admission, next_attempt_at)
            VALUES (?, ?, ?, ?, ?, 0)');
        $statement->execute(['bucket', 'STANDARD', $path, 'delete_path', $recovery ? 1 : 0]);
    }

    private function cleanupCount(\PDO $pdo): int
    {
        return $this->sqlInt($pdo, 'SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1');
    }

    /** @param \Closure(): mixed $write */
    private function rejects(\Closure $write): void
    {
        try {
            $write();
            self::fail('Cleanup capacity trigger accepted a prohibited mutation.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('Storage cleanup', $error->getMessage());
        }
    }

    private function pdo(SqliteMetadataStore $store): \PDO
    {
        $property = new \ReflectionProperty($store, 'pdo');
        $pdo = $property->getValue($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        return $pdo;
    }

    private function sqlInt(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        return (int) $statement->fetchColumn();
    }
}
