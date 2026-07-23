<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/**
 * Contains all PostgreSQL DDL statements for the S3 metadata store.
 *
 * All tables use the `s3_` prefix to avoid collisions when sharing a database.
 * The complete schema represents VERSION; incremental migrations are returned
 * by getMigrationStatements().
 */
final class PostgresSchema
{
    /** @var int Current schema version. */
    public const int VERSION = 12;

    /**
     * Get the complete schema DDL for version 1.
     *
     * @return list<string> Individual CREATE TABLE statements.
     */
    public static function getCreateStatements(): array
    {
        return [
            // Schema version tracking
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_schema_version (
                version INTEGER NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                description TEXT NOT NULL DEFAULT ''
            )
            SQL,

            // Buckets
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_buckets (
                name TEXT PRIMARY KEY,
                owner_id TEXT NOT NULL,
                region TEXT NOT NULL DEFAULT 'us-east-1',
                versioning TEXT NOT NULL DEFAULT '',
                object_lock_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner ON s3_buckets(owner_id)',

            // Account quotas
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id TEXT PRIMARY KEY,
                max_buckets_per_owner BIGINT NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Transactional owner write locks for quotas and replacement serialization.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_owner_write_locks (
                owner_id TEXT PRIMARY KEY
            )
            SQL,

            // Account policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_policies (
                owner_id TEXT PRIMARY KEY,
                policy_json JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Named policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_named_policies (
                policy_name TEXT PRIMARY KEY,
                policy_json JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Distributed locks
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name TEXT PRIMARY KEY,
                owner_id TEXT NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_locks_expires ON s3_locks(expires_at)',

            // Lifecycle checkpoints
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_checkpoints (
                bucket TEXT NOT NULL,
                rule_id TEXT NOT NULL,
                action TEXT NOT NULL,
                cursor_key TEXT,
                cursor_version_id TEXT,
                cursor_upload_id TEXT,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (bucket, rule_id, action)
            )
            SQL,

            // Physical tier transition jobs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tier_transition_jobs (
                id BIGSERIAL PRIMARY KEY,
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
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_status_next ON s3_tier_transition_jobs(status, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_object ON s3_tier_transition_jobs(bucket, key_name, version_id)',

            // Restore jobs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_restore_jobs (
                id BIGSERIAL PRIMARY KEY,
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
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_status_next ON s3_restore_jobs(status, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_object ON s3_restore_jobs(bucket, key_name, version_id)',

            // Objects
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_objects (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                version_id TEXT NOT NULL DEFAULT 'null',
                is_latest BOOLEAN NOT NULL DEFAULT TRUE,
                is_delete_marker BOOLEAN NOT NULL DEFAULT FALSE,
                owner_id TEXT NOT NULL,
                etag TEXT NOT NULL,
                size BIGINT NOT NULL,
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
                restore_expires_at TIMESTAMPTZ,
                storage_path TEXT NOT NULL,
                user_metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                checksum_crc32 TEXT,
                checksum_crc32c TEXT,
                checksum_sha1 TEXT,
                checksum_sha256 TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, key_name, version_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_key ON s3_objects(bucket, key_name)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_latest ON s3_objects(bucket, is_latest, is_delete_marker)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_prefix ON s3_objects(bucket, key_name, is_latest, is_delete_marker)',

            // Multipart uploads
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_uploads (
                upload_id TEXT PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                owner_id TEXT NOT NULL,
                content_type TEXT DEFAULT 'application/octet-stream',
                user_metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket ON s3_multipart_uploads(bucket)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket_key ON s3_multipart_uploads(bucket, key_name)',

            // Parts
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_parts (
                id BIGSERIAL PRIMARY KEY,
                upload_id TEXT NOT NULL REFERENCES s3_multipart_uploads(upload_id) ON DELETE CASCADE,
                part_number INTEGER NOT NULL,
                etag TEXT NOT NULL,
                size BIGINT NOT NULL,
                storage_path TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(upload_id, part_number)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_parts_upload ON s3_parts(upload_id, part_number)',

            // ACLs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_acls (
                id BIGSERIAL PRIMARY KEY,
                resource_type TEXT NOT NULL,
                resource_name TEXT NOT NULL,
                grantee_type TEXT NOT NULL,
                grantee_id TEXT NOT NULL,
                permission TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_acls_resource ON s3_acls(resource_type, resource_name)',

            // Tagging
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tagging (
                id BIGSERIAL PRIMARY KEY,
                resource_type TEXT NOT NULL,
                bucket TEXT NOT NULL,
                key_name TEXT,
                tag_key TEXT NOT NULL,
                tag_value TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(resource_type, bucket, key_name, tag_key)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_tagging_resource ON s3_tagging(resource_type, bucket, key_name)',

            // Policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_policies (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL UNIQUE,
                policy_json TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // CORS rules
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_cors_rules (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                rule_order INTEGER NOT NULL DEFAULT 0,
                allowed_origins JSONB NOT NULL DEFAULT '[]'::jsonb,
                allowed_methods JSONB NOT NULL DEFAULT '[]'::jsonb,
                allowed_headers JSONB NOT NULL DEFAULT '[]'::jsonb,
                expose_headers JSONB NOT NULL DEFAULT '[]'::jsonb,
                max_age_seconds INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_cors_rules_bucket ON s3_cors_rules(bucket)',

            // Encryption configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_encryption_configs (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL UNIQUE,
                sse_algorithm TEXT NOT NULL DEFAULT 'AES256',
                kms_master_key_id TEXT,
                bucket_key_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Lifecycle rules
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_rules (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                rule_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Enabled',
                prefix TEXT,
                filter_json JSONB,
                transitions_json JSONB,
                expiration_json JSONB,
                noncurrent_transitions_json JSONB,
                noncurrent_expiration_json JSONB,
                abort_incomplete_days INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, rule_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_lifecycle_rules_bucket ON s3_lifecycle_rules(bucket)',

            // Object lock configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lock_configs (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL UNIQUE,
                object_lock_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                default_retention_mode TEXT,
                default_retention_days INTEGER,
                default_retention_years INTEGER,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Object retention
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_retention (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                version_id TEXT NOT NULL DEFAULT 'null',
                mode TEXT NOT NULL,
                retain_until_date TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, key_name, version_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_object_retention_lookup ON s3_object_retention(bucket, key_name, version_id)',

            // Legal holds
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_legal_holds (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                version_id TEXT NOT NULL DEFAULT 'null',
                status TEXT NOT NULL DEFAULT 'OFF',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, key_name, version_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_object_legal_holds_lookup ON s3_object_legal_holds(bucket, key_name, version_id)',

            // Notification configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_configs (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                config_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                destination_type TEXT NOT NULL,
                destination_arn TEXT NOT NULL,
                filter_rules_json JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, config_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_notification_configs_bucket ON s3_notification_configs(bucket)',

            // Website configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_website_configs (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL UNIQUE,
                index_document TEXT NOT NULL DEFAULT 'index.html',
                error_document TEXT,
                redirect_all_host TEXT,
                redirect_all_protocol TEXT,
                routing_rules_json JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Public access blocks
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_public_access_blocks (
                bucket TEXT NOT NULL UNIQUE,
                block_public_acls BOOLEAN NOT NULL DEFAULT FALSE,
                ignore_public_acls BOOLEAN NOT NULL DEFAULT FALSE,
                block_public_policy BOOLEAN NOT NULL DEFAULT FALSE,
                restrict_public_buckets BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Bucket logging
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_bucket_logging (
                bucket TEXT NOT NULL UNIQUE,
                target_bucket TEXT NOT NULL,
                target_prefix TEXT NOT NULL DEFAULT '',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,

            // Lifecycle indexes (version 3)
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_lifecycle ON s3_objects(bucket, is_latest, is_delete_marker, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent ON s3_objects(bucket, is_latest, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_mpu_lifecycle ON s3_multipart_uploads(bucket, created_at)',

            // Rate limit buckets (version 4)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_rate_limit_buckets (
                ip TEXT PRIMARY KEY,
                tokens DOUBLE PRECISION NOT NULL,
                last_refill_at DOUBLE PRECISION NOT NULL
            )
            SQL,

            // Notification queue (version 4)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_queue (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                event_name TEXT NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_nq_status_next ON s3_notification_queue(status, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_nq_created ON s3_notification_queue(created_at)',

        ];
    }

    /**
     * Get migration statements for upgrading from a previous schema version.
     *
     * All statements use IF NOT EXISTS, so they are safe to re-run.
     *
     * @param  int  $fromVersion  The current schema version to migrate from.
     * @return list<string> SQL statements to execute.
     */
    public static function getMigrationStatements(int $fromVersion): array
    {
        $statements = [];

        if ($fromVersion < 3) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_lifecycle ON s3_objects(bucket, is_latest, is_delete_marker, created_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent ON s3_objects(bucket, is_latest, created_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_mpu_lifecycle ON s3_multipart_uploads(bucket, created_at)';
        }

        if ($fromVersion < 4) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_rate_limit_buckets (
                ip TEXT PRIMARY KEY,
                tokens DOUBLE PRECISION NOT NULL,
                last_refill_at DOUBLE PRECISION NOT NULL
            )
            SQL;
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_queue (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                event_name TEXT NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_nq_status_next ON s3_notification_queue(status, next_attempt_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_nq_created ON s3_notification_queue(created_at)';
        }

        if ($fromVersion < 5) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id TEXT PRIMARY KEY,
                max_buckets_per_owner BIGINT NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
        }

        if ($fromVersion < 6) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name TEXT PRIMARY KEY,
                owner_id TEXT NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_locks_expires ON s3_locks(expires_at)';
        }

        if ($fromVersion < 7) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_checkpoints (
                bucket TEXT NOT NULL,
                rule_id TEXT NOT NULL,
                action TEXT NOT NULL,
                cursor_key TEXT,
                cursor_version_id TEXT,
                cursor_upload_id TEXT,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (bucket, rule_id, action)
            )
            SQL;
        }

        if ($fromVersion < 9) {
            $statements[] = "ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS storage_tier TEXT NOT NULL DEFAULT 'STANDARD'";
            $statements[] = "ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS transition_status TEXT NOT NULL DEFAULT 'available'";
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS transition_target_tier TEXT';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS transition_error TEXT';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS restore_status TEXT';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS restored_storage_path TEXT';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS restore_expires_at TIMESTAMPTZ';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)';
        }

        if ($fromVersion < 10) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tier_transition_jobs (
                id BIGSERIAL PRIMARY KEY,
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
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_status_next ON s3_tier_transition_jobs(status, next_attempt_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_tier_jobs_object ON s3_tier_transition_jobs(bucket, key_name, version_id)';
        }

        if ($fromVersion < 11) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_restore_jobs (
                id BIGSERIAL PRIMARY KEY,
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
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_status_next ON s3_restore_jobs(status, next_attempt_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_restore_jobs_object ON s3_restore_jobs(bucket, key_name, version_id)';
        }

        if ($fromVersion < 12) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_owner_write_locks (
                owner_id TEXT PRIMARY KEY
            )
            SQL;
        }

        return $statements;
    }
}
