<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use PHPUnit\Framework\TestCase;

final class ExternalSchemaTest extends TestCase
{
    public function test_mysql_schema_contains_owner_write_lock_migration(): void
    {
        self::assertSame(44, MysqlSchema::VERSION);
        $corsAndTextMigration = implode("\n", MysqlSchema::getMigrationStatements(43));
        self::assertStringContainsString('s3_cors_rules ADD COLUMN rule_id VARBINARY(255)', $corsAndTextMigration);
        self::assertStringContainsString('SET max_age_seconds = -1 WHERE max_age_seconds = 0', $corsAndTextMigration);
        self::assertStringContainsString('max_age_seconds INT NOT NULL DEFAULT -1', $corsAndTextMigration);
        self::assertStringContainsString('index_document MEDIUMTEXT NOT NULL', $corsAndTextMigration);
        self::assertStringContainsString('target_prefix MEDIUMTEXT NOT NULL', $corsAndTextMigration);
        $partChecksumMigration = implode("\n", MysqlSchema::getMigrationStatements(42));
        self::assertStringContainsString('s3_parts ADD COLUMN checksum_algorithm', $partChecksumMigration);
        self::assertStringContainsString('s3_parts ADD COLUMN checksum_value', $partChecksumMigration);
        $recursiveCleanupMigration = implode("\n", MysqlSchema::getMigrationStatements(41));
        self::assertStringContainsString('recursive_cleanup_unsafe TINYINT(1)', $recursiveCleanupMigration);
        self::assertStringContainsString('operation IS NULL', $recursiveCleanupMigration);
        self::assertStringContainsString("BINARY operation = BINARY 'delete_path'", $recursiveCleanupMigration);
        self::assertStringContainsString(') VIRTUAL', $recursiveCleanupMigration);
        self::assertStringContainsString('idx_s3_storage_gc_recursive_unsafe', $recursiveCleanupMigration);
        $mysqlCreate = implode("\n", MysqlSchema::getCreateStatements());
        self::assertStringContainsString('recursive_cleanup_unsafe TINYINT(1)', $mysqlCreate);
        self::assertStringContainsString(') VIRTUAL', $mysqlCreate);
        self::assertStringContainsString('idx_s3_storage_gc_recursive_unsafe (recursive_cleanup_unsafe, storage_tier)', $mysqlCreate);
        self::assertContains(
            'ALTER TABLE s3_objects MODIFY COLUMN restore_expires_at DATETIME NULL',
            MysqlSchema::getMigrationStatements(40),
        );
        self::assertStringContainsString('restore_expires_at DATETIME NULL', implode("\n", MysqlSchema::getCreateStatements()));
        $noncurrentMigration = implode("\n", MysqlSchema::getMigrationStatements(39));
        self::assertStringContainsString('ADD COLUMN noncurrent_at TIMESTAMP NULL', $noncurrentMigration);
        self::assertStringContainsString('SET noncurrent_at = CURRENT_TIMESTAMP WHERE is_latest = 0', $noncurrentMigration);
        self::assertStringContainsString('idx_s3_objects_noncurrent_at', $noncurrentMigration);
        self::assertStringContainsString('noncurrent_at TIMESTAMP NULL', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertStringContainsString(
            'chk_s3_encryption_configs_supported',
            implode("\n", MysqlSchema::getMigrationStatements(38)),
        );
        self::assertContains('ALTER TABLE s3_objects ADD COLUMN restored_storage_tier VARBINARY(64) NULL', MysqlSchema::getMigrationStatements(33));
        self::assertContains('ALTER TABLE s3_multipart_uploads ADD COLUMN storage_tier VARBINARY(64) NULL', MysqlSchema::getMigrationStatements(32));
        self::assertStringContainsString('dispatch_id CHAR(32)', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertContains("ALTER TABLE s3_object_write_intents ADD COLUMN dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000'", MysqlSchema::getMigrationStatements(31));
        self::assertStringContainsString('rule_id VARBINARY(1020) NOT NULL', $mysqlCreate);
        self::assertStringContainsString('config_id VARBINARY(1020) NOT NULL', $mysqlCreate);
        self::assertContains('ALTER TABLE s3_lifecycle_rules MODIFY rule_id VARBINARY(1020) NOT NULL', MysqlSchema::getMigrationStatements(30));
        self::assertContains('ALTER TABLE s3_lifecycle_checkpoints MODIFY rule_id VARBINARY(1020) NOT NULL', MysqlSchema::getMigrationStatements(30));
        self::assertContains('ALTER TABLE s3_notification_configs MODIFY config_id VARBINARY(1020) NOT NULL', MysqlSchema::getMigrationStatements(30));
        self::assertStringContainsString('s3_multipart_write_intents', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertStringContainsString('idx_s3_multipart_intents_tier (storage_tier)', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertStringContainsString('idx_s3_multipart_intents_owner (owner_id)', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertContains('ALTER TABLE s3_multipart_write_intents ADD INDEX idx_s3_multipart_intents_owner (owner_id)', MysqlSchema::getMigrationStatements(28));
        self::assertStringContainsString('s3_multipart_write_intents', implode("\n", MysqlSchema::getMigrationStatements(24)));
        self::assertContains('ALTER TABLE s3_multipart_write_intents ADD INDEX idx_s3_multipart_intents_tier (storage_tier)', MysqlSchema::getMigrationStatements(25));
        self::assertContains('ALTER TABLE s3_storage_garbage ADD COLUMN owner_id VARBINARY(255) NULL', MysqlSchema::getMigrationStatements(26));
        self::assertContains('ALTER TABLE s3_storage_garbage ADD INDEX idx_s3_storage_gc_operation_owner (operation, owner_id)', MysqlSchema::getMigrationStatements(26));
        foreach (['s3_storage_garbage', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_notification_queue'] as $table) {
            self::assertContains("ALTER TABLE {$table} ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0", MysqlSchema::getMigrationStatements(27));
        }
        self::assertContains('ALTER TABLE s3_buckets MODIFY owner_id VARBINARY(255) NOT NULL', MysqlSchema::getMigrationStatements(22));
        self::assertContains('ALTER TABLE s3_named_policies MODIFY policy_name VARBINARY(255) NOT NULL', MysqlSchema::getMigrationStatements(22));
        self::assertStringContainsString('owner_id VARBINARY(255)', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertStringContainsString('operation', implode("\n", MysqlSchema::getMigrationStatements(21)));
        self::assertStringContainsString('generation', implode("\n", MysqlSchema::getMigrationStatements(20)));
        self::assertStringContainsString('s3_bucket_ownership_controls', implode("\n", MysqlSchema::getMigrationStatements(18)));
        self::assertStringContainsString('idx_s3_objects_owner', implode("\n", MysqlSchema::getCreateStatements()));
        self::assertContains('CREATE INDEX idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)', MysqlSchema::getMigrationStatements(16));
        self::assertContains('CREATE INDEX idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)', MysqlSchema::getMigrationStatements(16));
        self::assertContains('CREATE INDEX idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)', MysqlSchema::getMigrationStatements(17));
        self::assertContains('CREATE INDEX idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)', MysqlSchema::getMigrationStatements(19));
        self::assertContains('CREATE INDEX idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)', MysqlSchema::getMigrationStatements(19));
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getMigrationStatements(11)),
        );
        self::assertStringContainsString(
            'max_multipart_uploads_per_bucket',
            implode("\n", MysqlSchema::getMigrationStatements(13)),
        );
        self::assertStringContainsString(
            's3_storage_garbage',
            implode("\n", MysqlSchema::getMigrationStatements(14)),
        );
        self::assertStringContainsString(
            'uk_s3_objects_exact',
            implode("\n", MysqlSchema::getMigrationStatements(15)),
        );
        self::assertStringContainsString(
            'VARBINARY(1024)',
            implode("\n", MysqlSchema::getCreateStatements()),
        );
    }

    public function test_postgres_schema_contains_owner_write_lock_migration(): void
    {
        self::assertSame(41, PostgresSchema::VERSION);
        $corsMigration = implode("\n", PostgresSchema::getMigrationStatements(40));
        self::assertStringContainsString('s3_cors_rules ADD COLUMN IF NOT EXISTS rule_id TEXT', $corsMigration);
        self::assertStringContainsString('SET max_age_seconds = -1 WHERE max_age_seconds = 0', $corsMigration);
        self::assertStringContainsString('max_age_seconds SET DEFAULT -1', $corsMigration);
        $partChecksumMigration = implode("\n", PostgresSchema::getMigrationStatements(39));
        self::assertStringContainsString('s3_parts ADD COLUMN IF NOT EXISTS checksum_algorithm', $partChecksumMigration);
        self::assertStringContainsString('s3_parts ADD COLUMN IF NOT EXISTS checksum_value', $partChecksumMigration);
        $recursiveCleanupIndex = "CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_recursive_unsafe ON s3_storage_garbage(storage_tier, operation) WHERE CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1";
        self::assertContains($recursiveCleanupIndex, PostgresSchema::getMigrationStatements(38));
        self::assertContains($recursiveCleanupIndex, PostgresSchema::getCreateStatements());
        $noncurrentMigration = implode("\n", PostgresSchema::getMigrationStatements(37));
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS noncurrent_at TIMESTAMPTZ', $noncurrentMigration);
        self::assertStringContainsString('SET noncurrent_at = NOW() WHERE is_latest = FALSE', $noncurrentMigration);
        self::assertStringContainsString('idx_s3_objects_noncurrent_at', $noncurrentMigration);
        self::assertStringContainsString('noncurrent_at TIMESTAMPTZ', implode("\n", PostgresSchema::getCreateStatements()));
        $encryptionInvariant = implode("\n", PostgresSchema::getMigrationStatements(36));
        self::assertStringContainsString('LOCK TABLE s3_encryption_configs IN ACCESS EXCLUSIVE MODE', $encryptionInvariant);
        self::assertStringContainsString('DROP CONSTRAINT IF EXISTS chk_s3_encryption_configs_supported', $encryptionInvariant);
        self::assertStringContainsString('ADD CONSTRAINT chk_s3_encryption_configs_supported', $encryptionInvariant);
        self::assertContains('ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS restored_storage_tier TEXT', PostgresSchema::getMigrationStatements(31));
        self::assertContains('ALTER TABLE s3_multipart_uploads ADD COLUMN IF NOT EXISTS storage_tier TEXT', PostgresSchema::getMigrationStatements(30));
        self::assertStringContainsString('dispatch_id TEXT', implode("\n", PostgresSchema::getCreateStatements()));
        self::assertContains("ALTER TABLE s3_object_write_intents ADD COLUMN IF NOT EXISTS dispatch_id TEXT DEFAULT '00000000000000000000000000000000'", PostgresSchema::getMigrationStatements(29));
        foreach ([
            'idx_s3_objects_latest_listing_c',
            'idx_s3_objects_versions_listing_c',
            'idx_s3_objects_lifecycle_listing_c',
            'idx_s3_multipart_uploads_listing_c',
        ] as $index) {
            self::assertStringContainsString($index, implode("\n", PostgresSchema::getCreateStatements()));
            self::assertStringContainsString($index, implode("\n", PostgresSchema::getMigrationStatements(28)));
        }
        self::assertStringContainsString('s3_multipart_write_intents', implode("\n", PostgresSchema::getCreateStatements()));
        self::assertStringContainsString('idx_s3_multipart_intents_tier', implode("\n", PostgresSchema::getCreateStatements()));
        self::assertStringContainsString('idx_s3_multipart_intents_owner', implode("\n", PostgresSchema::getCreateStatements()));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_owner ON s3_multipart_write_intents(owner_id)', PostgresSchema::getMigrationStatements(26));
        self::assertStringContainsString('s3_multipart_write_intents', implode("\n", PostgresSchema::getMigrationStatements(22)));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_tier ON s3_multipart_write_intents(storage_tier)', PostgresSchema::getMigrationStatements(23));
        self::assertContains('ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS owner_id TEXT', PostgresSchema::getMigrationStatements(24));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_operation_owner ON s3_storage_garbage(operation, owner_id)', PostgresSchema::getMigrationStatements(24));
        foreach (['s3_storage_garbage', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_notification_queue'] as $table) {
            self::assertContains("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS lease_generation BIGINT NOT NULL DEFAULT 0", PostgresSchema::getMigrationStatements(25));
        }
        self::assertStringContainsString('operation', implode("\n", PostgresSchema::getMigrationStatements(20)));
        self::assertStringContainsString('generation', implode("\n", PostgresSchema::getMigrationStatements(19)));
        self::assertStringContainsString('s3_bucket_ownership_controls', implode("\n", PostgresSchema::getMigrationStatements(17)));
        self::assertStringContainsString('idx_s3_objects_owner', implode("\n", PostgresSchema::getCreateStatements()));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)', PostgresSchema::getMigrationStatements(15));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)', PostgresSchema::getMigrationStatements(15));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)', PostgresSchema::getMigrationStatements(16));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)', PostgresSchema::getMigrationStatements(18));
        self::assertContains('CREATE INDEX IF NOT EXISTS idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)', PostgresSchema::getMigrationStatements(18));
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getMigrationStatements(11)),
        );
        self::assertStringContainsString(
            'max_multipart_uploads_per_bucket',
            implode("\n", PostgresSchema::getMigrationStatements(13)),
        );
        self::assertStringContainsString(
            's3_storage_garbage',
            implode("\n", PostgresSchema::getMigrationStatements(14)),
        );
    }
}
