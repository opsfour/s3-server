<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MultipartWriteIntentSchemaTest extends TestCase
{
    public function test_fractional_sqlite_completion_counter_fails_reader_and_schema_admission(): void
    {
        $path = sys_get_temp_dir() . '/s3-fractional-completion-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $database = new \PDO('sqlite:' . $path);
            $schema = new SchemaManager($database);
            $database->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 0.5 WHERE id = 1');
            $type = $database->query('SELECT typeof(retained_count) FROM s3_multipart_completion_capacity WHERE id = 1');
            self::assertInstanceOf(\PDOStatement::class, $type);
            self::assertSame('real', $type->fetchColumn());

            try {
                $store->getMultipartCompletionIntentCount();
                self::fail('The completion reader accepted a fractional counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is unavailable', $error->getMessage());
            }
            try {
                $schema->assertMultipartCompletionCapacityConsistent();
                self::fail('The completion schema guard accepted a fractional counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is inconsistent', $error->getMessage());
            }
            try {
                $store->initialize();
                self::fail('The completion serving initializer accepted a fractional counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is inconsistent', $error->getMessage());
            }

            $database->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 0 WHERE id = 1');
            self::assertSame(0, $store->getMultipartCompletionIntentCount());
            $store->initialize();
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_fractional_sqlite_write_intent_counter_fails_reader_and_schema_admission(): void
    {
        $path = sys_get_temp_dir() . '/s3-fractional-intent-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $database = new \PDO('sqlite:' . $path);
            $schema = new SchemaManager($database);

            foreach (['object', 'part'] as $kind) {
                $update = $database->prepare('UPDATE s3_write_intent_capacity SET retained_count = 0.5 WHERE kind = ?');
                $update->execute([$kind]);
                $type = $database->prepare('SELECT typeof(retained_count) FROM s3_write_intent_capacity WHERE kind = ?');
                $type->execute([$kind]);
                self::assertSame('real', $type->fetchColumn());

                try {
                    $store->getWriteIntentCapacityCounts();
                    self::fail("The {$kind} reader accepted a fractional counter.");
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('counter is unavailable', $error->getMessage());
                }
                try {
                    $schema->assertWriteIntentCapacityConsistent();
                    self::fail("The {$kind} schema guard accepted a fractional counter.");
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('counter is inconsistent', $error->getMessage());
                }
                try {
                    $store->initialize();
                    self::fail("The {$kind} serving initializer accepted a fractional counter.");
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('counter is inconsistent', $error->getMessage());
                }

                $reset = $database->prepare('UPDATE s3_write_intent_capacity SET retained_count = 0 WHERE kind = ?');
                $reset->execute([$kind]);
                self::assertSame(['object' => 0, 'part' => 0], $store->getWriteIntentCapacityCounts());
            }
            $store->initialize();
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_previous_sqlite_multipart_rows_keep_unknown_dispatch_history(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(31);
        $pdo->exec("INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, owner_id, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '/old-part', 'part_write_intent', 'owner', 0)");
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('old-attempt', 'old-upload', 'bucket', 'generation', 'owner', 'STANDARD',
             '/assembly', '/final', 'digest', 'abandoned', 0, 0)");
        $schema->migrate();
        $part = $pdo->query("SELECT dispatch_id FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        $complete = $pdo->query("SELECT dispatch_id FROM s3_multipart_write_intents WHERE attempt_id = 'old-attempt'");
        self::assertInstanceOf(\PDOStatement::class, $part);
        self::assertInstanceOf(\PDOStatement::class, $complete);
        self::assertSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $part->fetchColumn());
        self::assertSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $complete->fetchColumn());
    }

    public function test_global_capacity_migration_backfills_retained_rows_and_tracks_direct_sql(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(32);
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('old-attempt', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
             'assembly', 'final', 'digest', 'abandoned', 0, 0)");
        $schema->migrate();
        self::assertSame(1, self::sqliteCount($pdo));
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('new-attempt', 'upload', 'bucket', 'generation', 'other', 'STANDARD',
             'assembly', 'final', 'digest', 'abandoned', 0, 0)");
        self::assertSame(2, self::sqliteCount($pdo));
        $pdo->exec("DELETE FROM s3_multipart_write_intents WHERE attempt_id = 'old-attempt'");
        self::assertSame(1, self::sqliteCount($pdo));
    }

    public function test_global_capacity_trigger_fails_closed_without_counter_and_rolls_back_direct_deletion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        (new SchemaManager($pdo))->migrate();
        $insert = "INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('attempt', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
             'assembly', 'final', 'digest', 'abandoned', 0, 0)";
        $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 1000000 WHERE id = 1');
        try {
            $pdo->exec($insert);
            self::fail('The saturated counter accepted a direct insert.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('capacity is exhausted', $error->getMessage());
        }
        $pdo->exec('DELETE FROM s3_multipart_completion_capacity WHERE id = 1');
        try {
            $pdo->exec($insert);
            self::fail('A missing counter row accepted a direct insert.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('capacity is exhausted', $error->getMessage());
        }
        self::assertSame(0, self::sqliteCount($pdo, 'SELECT COUNT(*) FROM s3_multipart_write_intents'));

        $pdo->exec('INSERT INTO s3_multipart_completion_capacity (id, retained_count) VALUES (1, 0)');
        $pdo->exec($insert);
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM s3_multipart_write_intents WHERE attempt_id = 'attempt'");
        self::assertSame(0, self::sqliteCount($pdo));
        $pdo->rollBack();
        self::assertSame(1, self::sqliteCount($pdo));
        self::assertSame(1, self::sqliteCount($pdo, 'SELECT COUNT(*) FROM s3_multipart_write_intents'));
    }

    public function test_global_capacity_migration_rejects_more_than_one_million_retained_rows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(32);
        $pdo->exec('DROP TABLE s3_multipart_write_intents');
        $pdo->exec(<<<'SQL'
            CREATE VIEW s3_multipart_write_intents AS
            WITH RECURSIVE sequence(n) AS (
                SELECT 1 UNION ALL SELECT n + 1 FROM sequence WHERE n < 1000001
            ) SELECT n AS attempt_id FROM sequence
            SQL);
        try {
            $schema->migrate();
            self::fail('The migration accepted more retained rows than the global limit.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('CHECK constraint failed', $error->getMessage());
        }
        self::assertSame(32, $schema->getCurrentVersion());
    }

    public function test_global_capacity_migration_rejects_missing_essential_intent_table(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(32);
        $pdo->exec('DROP TABLE s3_multipart_write_intents');

        try {
            $schema->migrate();
            self::fail('A missing multipart-intent table must block the schema upgrade.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('no such table: s3_multipart_write_intents', $error->getMessage());
        }
        self::assertSame(32, $schema->getCurrentVersion());
    }

    public function test_existing_completion_tombstone_gets_zero_generation_on_upgrade(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(30);
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('old-attempt', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
             'assembly', 'final', 'digest', 'abandoned', 0, 0)");

        $schema->migrate();

        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $statement = $pdo->query("SELECT state, claim_generation FROM s3_multipart_write_intents WHERE attempt_id = 'old-attempt'");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        self::assertSame(['state' => 'abandoned', 'claim_generation' => 0], $statement->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_existing_intent_schema_adds_tier_inventory_index_without_losing_rows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(22);
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, active_upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, lease_until, next_attempt_at)
            VALUES ('attempt', 'upload', 'upload', 'bucket', 'generation', 'owner', 'GLACIER',
             'assembly', 'final', 'digest', 1, 1)");

        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $indexes = $pdo->query('PRAGMA index_list(s3_multipart_write_intents)');
        self::assertInstanceOf(\PDOStatement::class, $indexes);
        self::assertContains('idx_s3_multipart_intents_tier', array_column($indexes->fetchAll(\PDO::FETCH_ASSOC), 'name'));
        $tier = $pdo->query('SELECT storage_tier FROM s3_multipart_write_intents');
        self::assertInstanceOf(\PDOStatement::class, $tier);
        self::assertSame('GLACIER', $tier->fetchColumn());
    }

    public function test_previous_sqlite_schema_adds_completion_owner_index_without_losing_tombstones(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(25);
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
            VALUES ('old-attempt', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
             'assembly', 'final', 'digest', 'abandoned', 0, 0)");

        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $indexes = $pdo->query('PRAGMA index_list(s3_multipart_write_intents)');
        self::assertInstanceOf(\PDOStatement::class, $indexes);
        self::assertContains('idx_s3_multipart_intents_owner', array_column($indexes->fetchAll(\PDO::FETCH_ASSOC), 'name'));
        $retained = $pdo->query("SELECT state FROM s3_multipart_write_intents WHERE attempt_id = 'old-attempt'");
        self::assertInstanceOf(\PDOStatement::class, $retained);
        self::assertSame('abandoned', $retained->fetchColumn());
    }

    public function test_sqlite_migration_adds_durable_attempts_without_cascading_upload_deletion(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $schema = new SchemaManager($pdo);
        $schema->migrate(21);
        $pdo->exec("INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, user_metadata)
            VALUES ('legacy-upload', 'bucket', 'key', 'owner', '{}')");
        $schema->migrate();

        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $columnQuery = $pdo->query('PRAGMA table_info(s3_multipart_write_intents)');
        self::assertInstanceOf(\PDOStatement::class, $columnQuery);
        $columns = $columnQuery->fetchAll(\PDO::FETCH_ASSOC);
        self::assertContains('bucket_generation', array_column($columns, 'name'));
        self::assertContains('assembly_path', array_column($columns, 'name'));
        self::assertContains('final_path', array_column($columns, 'name'));
        $legacyQuery = $pdo->query("SELECT bucket_generation FROM s3_multipart_uploads WHERE upload_id = 'legacy-upload'");
        self::assertInstanceOf(\PDOStatement::class, $legacyQuery);
        self::assertSame('', $legacyQuery->fetchColumn());

        $insert = $pdo->prepare('INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, active_upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, lease_until, next_attempt_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $values = [str_repeat('a', 32), 'deleted-upload', 'deleted-upload', 'bucket', str_repeat('b', 32),
            'owner', 'STANDARD', '/assembly', '/final', str_repeat('c', 64), 1.0, 1.0];
        self::assertTrue($insert->execute($values));
        $countQuery = $pdo->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
        self::assertInstanceOf(\PDOStatement::class, $countQuery);
        self::assertSame(1, (int) $countQuery->fetchColumn());

        $values[0] = str_repeat('d', 32);
        self::expectException(\PDOException::class);
        $insert->execute($values);
    }

    private static function sqliteCount(\PDO $database, string $sql = 'SELECT retained_count FROM s3_multipart_completion_capacity'): int
    {
        $statement = $database->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }
}
