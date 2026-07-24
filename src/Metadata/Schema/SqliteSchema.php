<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/**
 * Contains all SQLite DDL statements for the S3 metadata store.
 *
 * All tables use the `s3_` prefix to avoid collisions when sharing a database.
 * The complete schema represents VERSION; incremental migrations remain in
 * SchemaManager for existing databases.
 */
final class SqliteSchema
{
    /** @var int Current schema version. */
    public const int VERSION = 14;

    /**
     * Get the complete schema DDL for version 1.
     *
     * This includes:
     * - Core tables: buckets, objects, multipart_uploads, parts
     * - Placeholder tables for future phases: ACLs, tagging, policies, CORS,
     *   lifecycle, encryption, object lock, retention, legal holds, notifications, website
     * - All required indexes
     */
    public static function getSchema(): string
    {
        return <<<'SQL'
        -- ============================================================
        -- Schema version tracking
        -- ============================================================
        CREATE TABLE IF NOT EXISTS s3_schema_version (
            version INTEGER NOT NULL,
            applied_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            description TEXT NOT NULL DEFAULT ''
        );

        -- ============================================================
        -- Core tables — Phase 2
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_buckets (
            name TEXT PRIMARY KEY,
            owner_id TEXT NOT NULL,
            region TEXT NOT NULL DEFAULT 'us-east-1',
            versioning TEXT NOT NULL DEFAULT '',
            object_lock_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner ON s3_buckets(owner_id);

        CREATE TABLE IF NOT EXISTS s3_account_quotas (
            owner_id TEXT PRIMARY KEY,
            max_buckets_per_owner INTEGER NOT NULL DEFAULT 0,
            max_objects_per_bucket INTEGER NOT NULL DEFAULT 0,
            max_bytes_per_bucket INTEGER NOT NULL DEFAULT 0,
            max_bytes_per_owner INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_account_policies (
            owner_id TEXT PRIMARY KEY,
            policy_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_named_policies (
            policy_name TEXT PRIMARY KEY,
            policy_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_locks (
            lock_name TEXT PRIMARY KEY,
            owner_id TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_locks_expires ON s3_locks(expires_at);

        CREATE TABLE IF NOT EXISTS s3_lifecycle_checkpoints (
            bucket TEXT NOT NULL,
            rule_id TEXT NOT NULL,
            action TEXT NOT NULL,
            cursor_key TEXT,
            cursor_version_id TEXT,
            cursor_upload_id TEXT,
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            PRIMARY KEY (bucket, rule_id, action)
        );

        CREATE TABLE IF NOT EXISTS s3_tier_transition_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            version_id TEXT,
            source_tier TEXT NOT NULL,
            target_tier TEXT NOT NULL,
            target_storage_class TEXT NOT NULL,
            source_storage_path TEXT NOT NULL,
            target_storage_path TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 10,
            next_attempt_at REAL NOT NULL,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_status_next ON s3_tier_transition_jobs(status, next_attempt_at);
        CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_object ON s3_tier_transition_jobs(bucket, key_name, version_id);

        CREATE TABLE IF NOT EXISTS s3_restore_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            version_id TEXT,
            source_tier TEXT NOT NULL,
            source_storage_path TEXT NOT NULL,
            restored_storage_path TEXT,
            restore_days INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 10,
            next_attempt_at REAL NOT NULL,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_status_next ON s3_restore_jobs(status, next_attempt_at);
        CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_object ON s3_restore_jobs(bucket, key_name, version_id);

        CREATE TABLE IF NOT EXISTS s3_objects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            version_id TEXT NOT NULL DEFAULT 'null',
            is_latest INTEGER NOT NULL DEFAULT 1,
            is_delete_marker INTEGER NOT NULL DEFAULT 0,
            owner_id TEXT NOT NULL,
            etag TEXT NOT NULL,
            size INTEGER NOT NULL,
            content_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            content_encoding TEXT,
            content_disposition TEXT,
            cache_control TEXT,
            storage_class TEXT NOT NULL DEFAULT 'STANDARD',
            storage_tier TEXT NOT NULL DEFAULT 'STANDARD',
            transition_status TEXT NOT NULL DEFAULT 'available',
            transition_target_tier TEXT,
            transition_error TEXT,
            restore_status TEXT,
            restored_storage_path TEXT,
            restore_expires_at TEXT,
            storage_path TEXT NOT NULL,
            user_metadata TEXT NOT NULL DEFAULT '{}',
            checksum_crc32 TEXT,
            checksum_crc32c TEXT,
            checksum_sha1 TEXT,
            checksum_sha256 TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, key_name, version_id)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_key ON s3_objects(bucket, key_name);
        CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_latest ON s3_objects(bucket, is_latest, is_delete_marker);
        CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_prefix ON s3_objects(bucket, key_name, is_latest, is_delete_marker);
        CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status);

        CREATE TABLE IF NOT EXISTS s3_multipart_uploads (
            upload_id TEXT PRIMARY KEY,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            owner_id TEXT NOT NULL,
            content_type TEXT DEFAULT 'application/octet-stream',
            user_metadata TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket ON s3_multipart_uploads(bucket);
        CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket_key ON s3_multipart_uploads(bucket, key_name);

        CREATE TABLE IF NOT EXISTS s3_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            upload_id TEXT NOT NULL,
            part_number INTEGER NOT NULL,
            etag TEXT NOT NULL,
            size INTEGER NOT NULL,
            storage_path TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(upload_id, part_number),
            FOREIGN KEY (upload_id) REFERENCES s3_multipart_uploads(upload_id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_s3_parts_upload ON s3_parts(upload_id, part_number);

        -- ============================================================
        -- Placeholder tables — Phase 6: ACLs, Policies, Tagging, CORS
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_acls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_type TEXT NOT NULL,
            resource_name TEXT NOT NULL,
            grantee_type TEXT NOT NULL,
            grantee_id TEXT NOT NULL,
            permission TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_acls_resource ON s3_acls(resource_type, resource_name);

        CREATE TABLE IF NOT EXISTS s3_tagging (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_type TEXT NOT NULL,
            bucket TEXT NOT NULL,
            key_name TEXT,
            version_id TEXT NOT NULL DEFAULT 'null',
            tag_key TEXT NOT NULL,
            tag_value TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(resource_type, bucket, key_name, version_id, tag_key)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_tagging_resource ON s3_tagging(resource_type, bucket, key_name, version_id);

        CREATE TABLE IF NOT EXISTS s3_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE,
            policy_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_cors_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            rule_order INTEGER NOT NULL DEFAULT 0,
            allowed_origins TEXT NOT NULL DEFAULT '[]',
            allowed_methods TEXT NOT NULL DEFAULT '[]',
            allowed_headers TEXT NOT NULL DEFAULT '[]',
            expose_headers TEXT NOT NULL DEFAULT '[]',
            max_age_seconds INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE INDEX IF NOT EXISTS idx_s3_cors_rules_bucket ON s3_cors_rules(bucket);

        -- ============================================================
        -- Placeholder tables — Phase 7: Encryption, Lifecycle
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_encryption_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE,
            sse_algorithm TEXT NOT NULL DEFAULT 'AES256',
            kms_master_key_id TEXT,
            bucket_key_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_lifecycle_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            rule_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'Enabled',
            prefix TEXT,
            filter_json TEXT,
            transitions_json TEXT,
            expiration_json TEXT,
            noncurrent_transitions_json TEXT,
            noncurrent_expiration_json TEXT,
            abort_incomplete_days INTEGER,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, rule_id)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_lifecycle_rules_bucket ON s3_lifecycle_rules(bucket);

        -- ============================================================
        -- Placeholder tables — Phase 5: Object Lock, Retention, Legal Holds
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_lock_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE,
            object_lock_enabled INTEGER NOT NULL DEFAULT 0,
            default_retention_mode TEXT,
            default_retention_days INTEGER,
            default_retention_years INTEGER,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_object_retention (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            version_id TEXT NOT NULL DEFAULT 'null',
            mode TEXT NOT NULL,
            retain_until_date TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, key_name, version_id)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_object_retention_lookup ON s3_object_retention(bucket, key_name, version_id);

        CREATE TABLE IF NOT EXISTS s3_object_legal_holds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_name TEXT NOT NULL,
            version_id TEXT NOT NULL DEFAULT 'null',
            status TEXT NOT NULL DEFAULT 'OFF',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, key_name, version_id)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_object_legal_holds_lookup ON s3_object_legal_holds(bucket, key_name, version_id);

        -- ============================================================
        -- Placeholder tables — Phase 8: Notifications, Website Hosting
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_notification_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            config_id TEXT NOT NULL,
            event_type TEXT NOT NULL,
            destination_type TEXT NOT NULL,
            destination_arn TEXT NOT NULL,
            filter_rules_json TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, config_id)
        );

        CREATE INDEX IF NOT EXISTS idx_s3_notification_configs_bucket ON s3_notification_configs(bucket);

        CREATE TABLE IF NOT EXISTS s3_website_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE,
            index_document TEXT NOT NULL DEFAULT 'index.html',
            error_document TEXT,
            redirect_all_host TEXT,
            redirect_all_protocol TEXT,
            routing_rules_json TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        -- ============================================================
        -- Phase 10: Public Access Blocks, Bucket Logging
        -- ============================================================

        CREATE TABLE IF NOT EXISTS s3_public_access_blocks (
            bucket TEXT NOT NULL UNIQUE,
            block_public_acls INTEGER NOT NULL DEFAULT 0,
            ignore_public_acls INTEGER NOT NULL DEFAULT 0,
            block_public_policy INTEGER NOT NULL DEFAULT 0,
            restrict_public_buckets INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );

        CREATE TABLE IF NOT EXISTS s3_bucket_logging (
            bucket TEXT NOT NULL UNIQUE,
            target_bucket TEXT NOT NULL,
            target_prefix TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
        );
        SQL;
    }

    /**
     * Get the PRAGMA statements for optimal SQLite performance.
     *
     * @return list<string> Individual PRAGMA statements.
     */
    public static function getPragmas(): array
    {
        return [
            'PRAGMA journal_mode = WAL',
            'PRAGMA synchronous = FULL',
            'PRAGMA foreign_keys = ON',
            'PRAGMA busy_timeout = 5000',
            'PRAGMA cache_size = -64000',
            'PRAGMA temp_store = MEMORY',
            'PRAGMA mmap_size = 268435456',
        ];
    }
}
