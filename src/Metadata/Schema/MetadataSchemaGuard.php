<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration;

/** Read-only schema admission check for serving nodes. */
final class MetadataSchemaGuard
{
    private const int POSTGRES_MANAGED_SEQUENCE_COUNT = 17;

    public static function assertCurrent(string $driver, string $dsn): void
    {
        if (! in_array($driver, ['postgres', 'mysql'], true)) {
            throw new \InvalidArgumentException('Schema guard requires postgres or mysql.');
        }
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));

        try {
            $row = $pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            $actual = $row !== null && $row['version'] !== null ? (int) $row['version'] : 0;
            self::assertCurrentVersion($driver, $actual);
            if ($pool->query(StoredBucketEncryptionConfiguration::invalidRowQuery($driver))->fetchRow() !== null) {
                throw new \RuntimeException('Stored bucket encryption configuration is unsupported; repair metadata before serving.');
            }
            self::assertMultipartCompletionCapacityConsistent($pool);
            self::assertStorageCleanupCapacityConsistent($pool);
            if ($pool instanceof PostgresConnectionPool) {
                self::assertPostgresWriteIntentTriggers($pool);
                self::assertPostgresSequenceUsage($pool);
            } else {
                self::assertMysqlWriteIntentTriggers($pool);
                self::assertMysqlStorageCleanupTriggerBehavior($pool);
            }
            foreach ([
                'object' => 'SELECT COUNT(*) FROM s3_object_write_intents',
                'part' => "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'",
            ] as $kind => $countSql) {
                $sql = "SELECT retained_count, ({$countSql}) AS actual_count FROM s3_write_intent_capacity WHERE kind = "
                    . ($driver === 'postgres' ? '$1' : '?');
                $capacity = $pool->execute($sql, [$kind])->fetchRow();
                if ($capacity === null || (int) $capacity['retained_count'] !== (int) $capacity['actual_count']) {
                    throw new \RuntimeException('Write intent recovery capacity counter is inconsistent; stop writers and repair metadata before serving.');
                }
            }
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException) {
                throw $error;
            }
            throw new \RuntimeException('Metadata schema cannot be verified. Run metadata:migrate before starting API nodes.', 0, $error);
        } finally {
            $pool->close();
        }
    }

    public static function assertMultipartCompletionCapacityConsistent(PostgresConnectionPool|MysqlConnectionPool $pool): void
    {
        try {
            $capacity = $pool->query('SELECT retained_count, (SELECT COUNT(*) FROM s3_multipart_write_intents) AS actual_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow();
        } catch (\Throwable $error) {
            throw new \RuntimeException('Multipart completion recovery capacity counter cannot be verified.', 0, $error);
        }
        if ($capacity === null || (int) $capacity['retained_count'] !== (int) $capacity['actual_count']) {
            throw new \RuntimeException('Multipart completion recovery capacity counter is inconsistent; stop writers and repair metadata before serving.');
        }
    }

    public static function assertCurrentVersion(string $driver, int $actual): void
    {
        $expected = match ($driver) {
            'postgres' => PostgresSchema::VERSION,
            'mysql' => MysqlSchema::VERSION,
            default => throw new \InvalidArgumentException('Schema guard requires postgres or mysql.'),
        };
        if ($actual !== $expected) {
            throw new \RuntimeException("Metadata schema version {$actual} is not supported by this API node (requires {$expected}); run metadata:migrate with the matching package version.");
        }
    }

    public static function assertPostgresWriteIntentTriggers(PostgresConnectionPool $pool): void
    {
        $expected = self::recoveryCapacityTriggerInventory();
        $rows = $pool->query(<<<'SQL'
            SELECT trigger.tgname AS name, table_class.relname AS table_name,
                   trigger.tgenabled AS enabled, trigger.tgtype AS trigger_type,
                   function_proc.proname AS function_name
            FROM pg_trigger AS trigger
            JOIN pg_class AS table_class ON table_class.oid = trigger.tgrelid
            JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
            JOIN pg_proc AS function_proc ON function_proc.oid = trigger.tgfoid
            JOIN pg_namespace AS function_schema ON function_schema.oid = function_proc.pronamespace
            WHERE trigger.tgrelid IN (
                to_regclass('s3_object_write_intents'),
                to_regclass('s3_storage_garbage'),
                to_regclass('s3_multipart_write_intents')
            )
              AND function_schema.oid = table_schema.oid
              AND (trigger.tgname LIKE 's3_write_intent_capacity_%'
                   OR trigger.tgname LIKE 's3_multipart_completion_capacity_%'
                   OR trigger.tgname LIKE 's3_storage_cleanup_capacity_%')
            SQL);
        while (($row = $rows->fetchRow()) !== null) {
            if (! is_string($row['name'] ?? null)) {
                throw new \RuntimeException('Write intent recovery trigger inventory cannot be verified.');
            }
            $name = $row['name'];
            if (! isset($expected[$name])) {
                continue;
            }
            [$table, $event, $function] = $expected[$name];
            $triggerType = match ($event) {
                'INSERT' => 7,
                'DELETE' => 11,
                'UPDATE' => 19,
            };
            if ($row['table_name'] === $table
                && in_array($row['enabled'], ['O', 'A'], true)
                && (int) $row['trigger_type'] === $triggerType
                && $row['function_name'] === $function) {
                unset($expected[$name]);
            }
        }
        if ($expected !== []) {
            throw new \RuntimeException('Write intent recovery triggers are missing or disabled; repair metadata before serving.');
        }
    }

    public static function assertPostgresSequenceUsage(PostgresConnectionPool $pool): void
    {
        try {
            $row = $pool->query(<<<'SQL'
                SELECT COUNT(*) AS sequence_count,
                       COUNT(*) FILTER (
                           WHERE has_sequence_privilege(current_user, sequence_class.oid, 'USAGE') IS NOT TRUE
                       ) AS missing_usage_count
                FROM pg_class AS sequence_class
                JOIN pg_depend AS dependency
                  ON dependency.classid = 'pg_class'::regclass
                 AND dependency.objid = sequence_class.oid
                 AND dependency.refclassid = 'pg_class'::regclass
                 AND dependency.deptype IN ('a', 'i')
                JOIN pg_class AS table_class ON table_class.oid = dependency.refobjid
                JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
                WHERE sequence_class.relkind = 'S'
                  AND table_class.relname IN (
                      's3_tier_transition_jobs',
                      's3_restore_jobs',
                      's3_objects',
                      's3_parts',
                      's3_acls',
                      's3_tagging',
                      's3_policies',
                      's3_cors_rules',
                      's3_encryption_configs',
                      's3_lifecycle_rules',
                      's3_lock_configs',
                      's3_object_retention',
                      's3_object_legal_holds',
                      's3_notification_configs',
                      's3_website_configs',
                      's3_notification_queue',
                      's3_storage_garbage'
                  )
                  AND table_schema.nspname = current_schema()
                SQL)->fetchRow();
        } catch (\Throwable) {
            throw new \RuntimeException('PostgreSQL metadata sequence privileges cannot be verified.');
        }

        $sequenceCount = filter_var($row['sequence_count'] ?? null, FILTER_VALIDATE_INT);
        $missingUsageCount = filter_var($row['missing_usage_count'] ?? null, FILTER_VALIDATE_INT);
        if ($sequenceCount !== self::POSTGRES_MANAGED_SEQUENCE_COUNT || $missingUsageCount !== 0) {
            throw new \RuntimeException('PostgreSQL metadata sequence USAGE privileges are incomplete.');
        }
    }

    /** Check the MySQL trigger inventory only with the DDL-capable migration role. */
    public static function assertMysqlWriteIntentTriggersForMigration(MysqlConnectionPool $pool): void
    {
        $rows = $pool->query(<<<'SQL'
            SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS table_name,
                   EVENT_MANIPULATION AS event_name, ACTION_TIMING AS timing
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND (TRIGGER_NAME LIKE 's3_write_intent_capacity_%'
                   OR TRIGGER_NAME LIKE 's3_multipart_completion_capacity_%'
                   OR TRIGGER_NAME LIKE 's3_storage_cleanup_capacity_%')
            SQL);
        self::assertMysqlWriteIntentTriggerRows($rows);
    }

    /** Check through a narrow DEFINER function without granting TRIGGER to the serving role. */
    public static function assertMysqlWriteIntentTriggers(MysqlConnectionPool $pool): void
    {
        try {
            $row = $pool->query(
                'SELECT s3_recovery_capacity_trigger_count() AS trigger_count',
            )->fetchRow();
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'Write intent recovery trigger inventory cannot be verified; repair metadata before serving.',
                0,
                $error,
            );
        }
        if ($row === null
            || filter_var($row['trigger_count'] ?? null, FILTER_VALIDATE_INT) !== count(self::recoveryCapacityTriggerInventory())) {
            throw new \RuntimeException('Write intent recovery triggers are missing; repair metadata before serving.');
        }
    }

    private static function assertMysqlWriteIntentTriggerRows(\Amp\Mysql\MysqlResult $rows): void
    {
        $expected = self::recoveryCapacityTriggerInventory();
        while (($row = $rows->fetchRow()) !== null) {
            if (! is_string($row['name'] ?? null)) {
                throw new \RuntimeException('Write intent recovery trigger inventory cannot be verified.');
            }
            $name = $row['name'];
            if (! isset($expected[$name])) {
                continue;
            }
            [$table, $event] = $expected[$name];
            if ($row['table_name'] === $table && $row['event_name'] === $event && $row['timing'] === 'BEFORE') {
                unset($expected[$name]);
            }
        }
        if ($expected !== []) {
            throw new \RuntimeException('Write intent recovery triggers are missing; do not publish the metadata schema version.');
        }
    }

    /** @return array<string, array{string, 'INSERT'|'DELETE'|'UPDATE', string}> */
    private static function recoveryCapacityTriggerInventory(): array
    {
        return [
            's3_write_intent_capacity_object_insert' => ['s3_object_write_intents', 'INSERT', 's3_write_intent_capacity_object_fn'],
            's3_write_intent_capacity_object_delete' => ['s3_object_write_intents', 'DELETE', 's3_write_intent_capacity_object_fn'],
            's3_write_intent_capacity_part_insert' => ['s3_storage_garbage', 'INSERT', 's3_write_intent_capacity_part_fn'],
            's3_write_intent_capacity_part_delete' => ['s3_storage_garbage', 'DELETE', 's3_write_intent_capacity_part_fn'],
            's3_write_intent_capacity_part_update' => ['s3_storage_garbage', 'UPDATE', 's3_write_intent_capacity_part_fn'],
            's3_multipart_completion_capacity_insert' => ['s3_multipart_write_intents', 'INSERT', 's3_multipart_completion_capacity_insert_fn'],
            's3_multipart_completion_capacity_delete' => ['s3_multipart_write_intents', 'DELETE', 's3_multipart_completion_capacity_delete_fn'],
            's3_storage_cleanup_capacity_insert' => ['s3_storage_garbage', 'INSERT', 's3_storage_cleanup_capacity_fn'],
            's3_storage_cleanup_capacity_delete' => ['s3_storage_garbage', 'DELETE', 's3_storage_cleanup_capacity_fn'],
            's3_storage_cleanup_capacity_update' => ['s3_storage_garbage', 'UPDATE', 's3_storage_cleanup_capacity_fn'],
        ];
    }

    public static function assertStorageCleanupCapacityConsistent(PostgresConnectionPool|MysqlConnectionPool $pool): void
    {
        $actualCount = $pool instanceof MysqlConnectionPool
            ? "SELECT COUNT(*) FROM s3_storage_garbage WHERE BINARY operation <> BINARY 'part_write_intent'"
            : "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation <> 'part_write_intent'";
        try {
            $capacity = $pool->query("SELECT retained_count,
                ({$actualCount}) AS actual_count
                FROM s3_storage_cleanup_capacity WHERE id = 1")->fetchRow();
        } catch (\Throwable $error) {
            throw new \RuntimeException('Storage cleanup capacity counter cannot be verified.', 0, $error);
        }
        if ($capacity === null || (int) $capacity['retained_count'] !== (int) $capacity['actual_count']) {
            throw new \RuntimeException('Storage cleanup capacity counter is inconsistent; stop writers and repair metadata before serving.');
        }
    }

    /** Behaviorally verify the MySQL cleanup and interacting part triggers using only DML. */
    public static function assertMysqlStorageCleanupTriggerBehavior(MysqlConnectionPool $pool): void
    {
        if (\Fiber::getCurrent() === null) {
            \Amp\async(static fn() => self::assertMysqlStorageCleanupTriggerBehavior($pool))->await();
            return;
        }
        $transaction = $pool->beginTransaction();
        $probe = 's3-cleanup-trigger-probe-' . bin2hex(random_bytes(12));
        try {
            $transaction->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part' FOR UPDATE")->fetchRow();
            $transaction->query('SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1 FOR UPDATE')->fetchRow();
            $transaction->execute("UPDATE s3_write_intent_capacity SET retained_count = 0 WHERE kind = 'part'");
            $transaction->execute('UPDATE s3_storage_cleanup_capacity SET retained_count = 0 WHERE id = 1');
            $transaction->execute(
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, recovery_admission, next_attempt_at)
                 VALUES (?, 'STANDARD', ?, 'delete_path', 0, 0)",
                [$probe, $probe],
            );
            self::assertMysqlProbeCounts($transaction, 1, 0);
            $transaction->execute("UPDATE s3_storage_garbage SET operation = 'part_write_intent' WHERE bucket = ? AND BINARY bucket = BINARY ?", [$probe, $probe]);
            self::assertMysqlProbeCounts($transaction, 0, 1);
            $transaction->execute("UPDATE s3_storage_garbage SET operation = 'delete_path' WHERE bucket = ? AND BINARY bucket = BINARY ?", [$probe, $probe]);
            self::assertMysqlProbeCounts($transaction, 1, 0);
            $transaction->execute('DELETE FROM s3_storage_garbage WHERE bucket = ? AND BINARY bucket = BINARY ?', [$probe, $probe]);
            self::assertMysqlProbeCounts($transaction, 0, 0);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Storage cleanup capacity triggers are behaviorally ineffective; repair metadata before serving.', 0, $error);
        } finally {
            try {
                if ($transaction->isActive()) {
                    $transaction->rollback();
                }
            } catch (\Throwable) {
                // Preserve the behavioral probe failure.
            }
        }
    }

    private static function assertMysqlProbeCounts(\Amp\Mysql\MysqlTransaction $transaction, int $cleanup, int $part): void
    {
        $row = $transaction->query("SELECT
            (SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1) AS cleanup_count,
            (SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part') AS part_count")->fetchRow();
        if ($row === null || (int) $row['cleanup_count'] !== $cleanup || (int) $row['part_count'] !== $part) {
            throw new \RuntimeException('MySQL storage cleanup trigger probe produced an invalid counter delta.');
        }
    }
}
