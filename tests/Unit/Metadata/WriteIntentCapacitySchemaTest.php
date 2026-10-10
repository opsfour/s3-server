<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use PHPUnit\Framework\TestCase;

final class WriteIntentCapacitySchemaTest extends TestCase
{
    public function test_upgrade_backfills_both_kinds_and_tracks_direct_operation_changes(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(33);
        self::insertObject($pdo, 'old');
        self::insertGarbage($pdo, 'part_write_intent', 'old');
        self::insertGarbage($pdo, 'delete', 'ordinary');

        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        self::assertSame(1, self::capacityCount($pdo, 'object'));
        self::assertSame(1, self::capacityCount($pdo, 'part'));

        self::insertObject($pdo, 'new');
        self::assertSame(2, self::capacityCount($pdo, 'object'));
        $pdo->exec("UPDATE s3_storage_garbage SET operation = 'part_write_intent' WHERE storage_path = 'ordinary'");
        self::assertSame(2, self::capacityCount($pdo, 'part'));
        $pdo->exec("UPDATE s3_storage_garbage SET operation = 'delete' WHERE storage_path = 'ordinary'");
        self::assertSame(1, self::capacityCount($pdo, 'part'));
        $pdo->exec("DELETE FROM s3_object_write_intents WHERE attempt_id = 'new'");
        $pdo->exec("DELETE FROM s3_storage_garbage WHERE storage_path = 'old'");
        self::assertSame(1, self::capacityCount($pdo, 'object'));
        self::assertSame(0, self::capacityCount($pdo, 'part'));
    }

    public function test_direct_writes_and_deletes_fail_closed_at_limit_or_missing_counter_and_rollback(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        (new SchemaManager($pdo))->migrate();
        $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 1000000 WHERE kind IN ('object', 'part')");
        self::rejects(static fn() => self::insertObject($pdo, 'blocked'));
        self::rejects(static fn() => self::insertGarbage($pdo, 'part_write_intent', 'blocked'));
        self::assertSame(0, self::sqlCount($pdo, 'SELECT COUNT(*) FROM s3_object_write_intents'));
        self::assertSame(0, self::sqlCount($pdo, 'SELECT COUNT(*) FROM s3_storage_garbage'));

        $pdo->exec('DELETE FROM s3_write_intent_capacity');
        self::rejects(static fn() => self::insertObject($pdo, 'missing'));
        self::rejects(static fn() => self::insertGarbage($pdo, 'part_write_intent', 'missing'));
        $pdo->exec("INSERT INTO s3_write_intent_capacity (kind, retained_count) VALUES ('object', 0), ('part', 0)");
        self::insertObject($pdo, 'kept');
        self::insertGarbage($pdo, 'part_write_intent', 'kept');

        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM s3_object_write_intents WHERE attempt_id = 'kept'");
        $pdo->exec("UPDATE s3_storage_garbage SET operation = 'delete' WHERE storage_path = 'kept'");
        self::assertSame(0, self::capacityCount($pdo, 'object'));
        self::assertSame(0, self::capacityCount($pdo, 'part'));
        $pdo->rollBack();
        self::assertSame(1, self::capacityCount($pdo, 'object'));
        self::assertSame(1, self::capacityCount($pdo, 'part'));

        $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 0 WHERE kind IN ('object', 'part')");
        self::rejects(static fn() => $pdo->exec("DELETE FROM s3_object_write_intents WHERE attempt_id = 'kept'"));
        self::rejects(static fn() => $pdo->exec("UPDATE s3_storage_garbage SET operation = 'delete' WHERE storage_path = 'kept'"));
        self::assertSame(1, self::sqlCount($pdo, 'SELECT COUNT(*) FROM s3_object_write_intents'));
        self::assertSame(1, self::sqlCount($pdo, "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'"));
    }

    public function test_migration_failure_keeps_previous_version_and_startup_rejects_drift(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(33);
        $pdo->exec('DROP TABLE s3_object_write_intents');
        try {
            $schema->migrate();
            self::fail('A missing intent table must stop migration.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('no such table', $error->getMessage());
        }
        self::assertSame(33, $schema->getCurrentVersion());

        $healthy = new \PDO('sqlite::memory:');
        $current = new SchemaManager($healthy);
        $current->migrate();
        $healthy->exec("UPDATE s3_write_intent_capacity SET retained_count = 1 WHERE kind = 'part'");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('capacity counter is inconsistent');
        $current->assertWriteIntentCapacityConsistent();
    }

    private static function insertObject(\PDO $pdo, string $id): void
    {
        $statement = $pdo->prepare("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until)
            VALUES (?, 'bucket', 'generation', 'owner', 'STANDARD', ?, ?, 'put', 0)");
        $statement->execute([$id, $id, hash('sha256', $id)]);
    }

    private static function insertGarbage(\PDO $pdo, string $operation, string $path): void
    {
        $statement = $pdo->prepare('INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, next_attempt_at) VALUES (?, ?, ?, ?, 0)');
        $statement->execute(['bucket', 'STANDARD', $path, $operation]);
    }

    private static function capacityCount(\PDO $pdo, string $kind): int
    {
        $statement = $pdo->prepare('SELECT retained_count FROM s3_write_intent_capacity WHERE kind = ?');
        $statement->execute([$kind]);

        return (int) $statement->fetchColumn();
    }

    private static function sqlCount(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @param \Closure(): mixed $write */
    private static function rejects(\Closure $write): void
    {
        try {
            $write();
            self::fail('The database accepted a write with unavailable recovery capacity.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('capacity', $error->getMessage());
        }
    }
}
