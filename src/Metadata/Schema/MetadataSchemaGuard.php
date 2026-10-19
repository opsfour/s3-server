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
            if ($pool instanceof PostgresConnectionPool) {
                self::assertPostgresWriteIntentTriggers($pool);
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
                   OR trigger.tgname LIKE 's3_multipart_completion_capacity_%')
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

    /** Check the MySQL trigger inventory only with the DDL-capable migration role. */
    public static function assertMysqlWriteIntentTriggersForMigration(MysqlConnectionPool $pool): void
    {
        $expected = self::recoveryCapacityTriggerInventory();
        $rows = $pool->query(<<<'SQL'
            SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS table_name,
                   EVENT_MANIPULATION AS event_name, ACTION_TIMING AS timing
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND (TRIGGER_NAME LIKE 's3_write_intent_capacity_%'
                   OR TRIGGER_NAME LIKE 's3_multipart_completion_capacity_%')
            SQL);
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
        ];
    }
}
