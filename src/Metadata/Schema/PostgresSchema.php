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
    public const int VERSION = 42;

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
                generation TEXT NOT NULL DEFAULT '',
                versioning TEXT NOT NULL DEFAULT '',
                object_lock_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner ON s3_buckets(owner_id)',
            'CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)',
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_bucket_ownership_controls (
                bucket TEXT PRIMARY KEY,
                object_ownership TEXT NOT NULL
            )
            SQL,

            // Account quotas
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id TEXT PRIMARY KEY,
                max_buckets_per_owner BIGINT NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT NOT NULL DEFAULT 0,
                max_multipart_uploads_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_multipart_uploads_per_owner BIGINT NOT NULL DEFAULT 0,
                max_multipart_bytes_per_bucket BIGINT NOT NULL DEFAULT 0,
                max_multipart_bytes_per_owner BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_account_quotas_owner_c ON s3_account_quotas(owner_id COLLATE "C")',

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
                origin TEXT NOT NULL DEFAULT 'legacy',
                lifecycle_rule_id TEXT,
                expected_is_latest SMALLINT,
                target_storage_path TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                lease_generation BIGINT NOT NULL DEFAULT 0,
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
                lease_generation BIGINT NOT NULL DEFAULT 0,
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
                restored_storage_tier TEXT,
                restore_expires_at TIMESTAMPTZ,
                storage_path TEXT NOT NULL,
                user_metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                checksum_crc32 TEXT,
                checksum_crc32c TEXT,
                checksum_sha1 TEXT,
                checksum_sha256 TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                noncurrent_at TIMESTAMPTZ,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(bucket, key_name, version_id)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_key ON s3_objects(bucket, key_name)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_latest ON s3_objects(bucket, is_latest, is_delete_marker)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_bucket_prefix ON s3_objects(bucket, key_name, is_latest, is_delete_marker)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_latest_listing_c ON s3_objects(bucket, key_name COLLATE "C") WHERE is_latest = TRUE AND is_delete_marker = FALSE',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_versions_listing_c ON s3_objects(bucket, key_name COLLATE "C", is_latest DESC, created_at DESC, id DESC)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_lifecycle_listing_c ON s3_objects(bucket, key_name COLLATE "C", version_id COLLATE "C")',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)',
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent_at ON s3_objects(bucket, is_latest, is_delete_marker, noncurrent_at)',

            // Multipart uploads
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_uploads (
                upload_id TEXT PRIMARY KEY,
                bucket TEXT NOT NULL,
                bucket_generation TEXT NOT NULL DEFAULT '',
                key_name TEXT NOT NULL,
                owner_id TEXT NOT NULL,
                storage_tier TEXT,
                content_type TEXT DEFAULT 'application/octet-stream',
                user_metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket ON s3_multipart_uploads(bucket)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_bucket_key ON s3_multipart_uploads(bucket, key_name)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_listing_c ON s3_multipart_uploads(bucket, key_name COLLATE "C", upload_id COLLATE "C")',

            // Parts
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_parts (
                id BIGSERIAL PRIMARY KEY,
                upload_id TEXT NOT NULL REFERENCES s3_multipart_uploads(upload_id) ON DELETE CASCADE,
                part_number INTEGER NOT NULL,
                etag TEXT NOT NULL,
                size BIGINT NOT NULL,
                storage_path TEXT NOT NULL,
                checksum_algorithm TEXT,
                checksum_value TEXT,
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
                version_id TEXT NOT NULL DEFAULT 'null',
                tag_key TEXT NOT NULL,
                tag_value TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_s3_tagging_version UNIQUE(resource_type, bucket, key_name, version_id, tag_key)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_tagging_resource ON s3_tagging(resource_type, bucket, key_name, version_id)',

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
                rule_id TEXT,
                allowed_origins JSONB NOT NULL DEFAULT '[]'::jsonb,
                allowed_methods JSONB NOT NULL DEFAULT '[]'::jsonb,
                allowed_headers JSONB NOT NULL DEFAULT '[]'::jsonb,
                expose_headers JSONB NOT NULL DEFAULT '[]'::jsonb,
                max_age_seconds INTEGER NOT NULL DEFAULT -1,
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
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_s3_encryption_configs_supported CHECK (
                    sse_algorithm COLLATE "C" = 'AES256'
                    AND kms_master_key_id IS NULL
                    AND bucket_key_enabled = FALSE
                )
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
                lease_generation BIGINT NOT NULL DEFAULT 0,
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_nq_status_next ON s3_notification_queue(status, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_nq_created ON s3_notification_queue(created_at)',

            // Durable physical storage cleanup queue (version 15)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_garbage (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                operation TEXT NOT NULL DEFAULT 'delete_path',
                key_name TEXT,
                upload_id TEXT,
                owner_id TEXT,
                lease_generation BIGINT NOT NULL DEFAULT 0,
                dispatch_id TEXT DEFAULT '00000000000000000000000000000000',
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_status ON s3_storage_garbage(status, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_operation_owner ON s3_storage_garbage(operation, owner_id)',
            "CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_recursive_unsafe ON s3_storage_garbage(storage_tier, operation) WHERE CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1",

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_write_intents (
                attempt_id TEXT PRIMARY KEY,
                upload_id TEXT NOT NULL,
                active_upload_id TEXT UNIQUE,
                bucket TEXT NOT NULL,
                bucket_generation TEXT NOT NULL,
                owner_id TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                assembly_path TEXT NOT NULL,
                final_path TEXT NOT NULL,
                part_digest TEXT NOT NULL,
                sse_mode TEXT,
                state TEXT NOT NULL DEFAULT 'active',
                lease_until DOUBLE PRECISION NOT NULL,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                claim_generation BIGINT NOT NULL DEFAULT 0,
                dispatch_id TEXT DEFAULT '00000000000000000000000000000000',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_due ON s3_multipart_write_intents(state, next_attempt_at)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_upload ON s3_multipart_write_intents(upload_id)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_tier ON s3_multipart_write_intents(storage_tier)',
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_owner ON s3_multipart_write_intents(owner_id)',

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_write_intents (
                attempt_id TEXT PRIMARY KEY,
                bucket TEXT NOT NULL,
                bucket_generation TEXT NOT NULL,
                owner_id TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                path_digest TEXT NOT NULL,
                purpose TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'active',
                lease_until DOUBLE PRECISION NOT NULL,
                claim_generation BIGINT NOT NULL DEFAULT 0,
                dispatch_id TEXT DEFAULT '00000000000000000000000000000000',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(storage_tier, path_digest)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_owner ON s3_object_write_intents(owner_id)',
            'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_due ON s3_object_write_intents(state, lease_until)',
            'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_tier ON s3_object_write_intents(storage_tier)',

            ...self::completionCapacityStatements(),
            ...self::writeIntentCapacityStatements(),
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

        if ($fromVersion < 13) {
            $statements[] = "ALTER TABLE s3_tagging ADD COLUMN IF NOT EXISTS version_id TEXT NOT NULL DEFAULT 'null'";
            $statements[] = 'ALTER TABLE s3_tagging DROP CONSTRAINT IF EXISTS s3_tagging_resource_type_bucket_key_name_tag_key_key';
            $statements[] = 'ALTER TABLE s3_tagging DROP CONSTRAINT IF EXISTS uq_s3_tagging_version';
            $statements[] = 'ALTER TABLE s3_tagging ADD CONSTRAINT uq_s3_tagging_version UNIQUE (resource_type, bucket, key_name, version_id, tag_key)';
            $statements[] = 'DROP INDEX IF EXISTS idx_s3_tagging_resource';
            $statements[] = 'CREATE INDEX idx_s3_tagging_resource ON s3_tagging(resource_type, bucket, key_name, version_id)';
        }

        if ($fromVersion < 14) {
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN IF NOT EXISTS max_multipart_uploads_per_bucket BIGINT NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN IF NOT EXISTS max_multipart_uploads_per_owner BIGINT NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN IF NOT EXISTS max_multipart_bytes_per_bucket BIGINT NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN IF NOT EXISTS max_multipart_bytes_per_owner BIGINT NOT NULL DEFAULT 0';
        }

        if ($fromVersion < 15) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_garbage (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_status ON s3_storage_garbage(status, next_attempt_at)';
        }

        if ($fromVersion < 16) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)';
        }

        if ($fromVersion < 17) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)';
        }

        if ($fromVersion < 18) {
            $statements[] = 'CREATE TABLE IF NOT EXISTS s3_bucket_ownership_controls (bucket TEXT PRIMARY KEY, object_ownership TEXT NOT NULL)';
        }

        if ($fromVersion < 19) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)';
        }

        if ($fromVersion < 20) {
            $statements[] = "ALTER TABLE s3_buckets ADD COLUMN IF NOT EXISTS generation TEXT NOT NULL DEFAULT ''";
            $statements[] = "UPDATE s3_buckets SET generation = md5(name || random()::text || clock_timestamp()::text) WHERE generation = ''";
        }

        if ($fromVersion < 21) {
            $statements[] = "ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS operation TEXT NOT NULL DEFAULT 'delete_path'";
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS key_name TEXT';
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS upload_id TEXT';
        }

        if ($fromVersion < 22) {
            $statements[] = "ALTER TABLE s3_tier_transition_jobs ADD COLUMN IF NOT EXISTS origin TEXT NOT NULL DEFAULT 'legacy'";
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs ADD COLUMN IF NOT EXISTS lifecycle_rule_id TEXT';
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs ADD COLUMN IF NOT EXISTS expected_is_latest SMALLINT';
        }

        if ($fromVersion < 23) {
            $statements[] = "ALTER TABLE s3_multipart_uploads ADD COLUMN IF NOT EXISTS bucket_generation TEXT NOT NULL DEFAULT ''";
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_write_intents (
                attempt_id TEXT PRIMARY KEY,
                upload_id TEXT NOT NULL,
                active_upload_id TEXT UNIQUE,
                bucket TEXT NOT NULL,
                bucket_generation TEXT NOT NULL,
                owner_id TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                assembly_path TEXT NOT NULL,
                final_path TEXT NOT NULL,
                part_digest TEXT NOT NULL,
                sse_mode TEXT,
                state TEXT NOT NULL DEFAULT 'active',
                lease_until DOUBLE PRECISION NOT NULL,
                next_attempt_at DOUBLE PRECISION NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_due ON s3_multipart_write_intents(state, next_attempt_at)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_upload ON s3_multipart_write_intents(upload_id)';
        }

        if ($fromVersion < 24) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_tier ON s3_multipart_write_intents(storage_tier)';
        }

        if ($fromVersion < 25) {
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS owner_id TEXT';
            $statements[] = <<<'SQL'
                UPDATE s3_storage_garbage AS garbage
                SET owner_id = upload.owner_id
                FROM s3_multipart_uploads AS upload
                WHERE garbage.operation = 'part_write_intent'
                  AND garbage.owner_id IS NULL
                  AND garbage.upload_id = upload.upload_id
                  AND garbage.bucket = upload.bucket
                  AND substring(garbage.storage_path from char_length(garbage.storage_path) - 64 for 32) = upload.bucket_generation
                SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_operation_owner ON s3_storage_garbage(operation, owner_id)';
        }

        if ($fromVersion < 26) {
            foreach (['s3_storage_garbage', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_notification_queue'] as $table) {
                $statements[] = "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS lease_generation BIGINT NOT NULL DEFAULT 0";
            }
        }

        if ($fromVersion < 27) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_owner ON s3_multipart_write_intents(owner_id)';
        }

        if ($fromVersion < 28) {
            $statements[] = <<<'SQL'
                CREATE TABLE IF NOT EXISTS s3_object_write_intents (
                    attempt_id TEXT PRIMARY KEY,
                    bucket TEXT NOT NULL,
                    bucket_generation TEXT NOT NULL,
                    owner_id TEXT NOT NULL,
                    storage_tier TEXT NOT NULL,
                    storage_path TEXT NOT NULL,
                    path_digest TEXT NOT NULL,
                    purpose TEXT NOT NULL,
                    state TEXT NOT NULL DEFAULT 'active',
                    lease_until DOUBLE PRECISION NOT NULL,
                    claim_generation BIGINT NOT NULL DEFAULT 0,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    UNIQUE(storage_tier, path_digest)
                )
                SQL;
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_owner ON s3_object_write_intents(owner_id)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_due ON s3_object_write_intents(state, lease_until)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_tier ON s3_object_write_intents(storage_tier)';
        }

        if ($fromVersion < 29) {
            // Build bytewise keyset indexes before a v29 API node serves listings.
            // These statements run outside an explicit transaction, but regular
            // CREATE INDEX intentionally keeps retries free of invalid indexes.
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_latest_listing_c ON s3_objects(bucket, key_name COLLATE "C") WHERE is_latest = TRUE AND is_delete_marker = FALSE';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_versions_listing_c ON s3_objects(bucket, key_name COLLATE "C", is_latest DESC, created_at DESC, id DESC)';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_lifecycle_listing_c ON s3_objects(bucket, key_name COLLATE "C", version_id COLLATE "C")';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_listing_c ON s3_multipart_uploads(bucket, key_name COLLATE "C", upload_id COLLATE "C")';
        }

        if ($fromVersion < 30) {
            $statements[] = "ALTER TABLE s3_object_write_intents ADD COLUMN IF NOT EXISTS dispatch_id TEXT DEFAULT '00000000000000000000000000000000'";
        }
        if ($fromVersion < 31) {
            $statements[] = 'ALTER TABLE s3_multipart_uploads ADD COLUMN IF NOT EXISTS storage_tier TEXT';
        }
        if ($fromVersion < 32) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS restored_storage_tier TEXT';
        }
        if ($fromVersion < 33) {
            $statements[] = 'ALTER TABLE s3_multipart_write_intents ADD COLUMN IF NOT EXISTS claim_generation BIGINT NOT NULL DEFAULT 0';
        }
        if ($fromVersion < 34) {
            $statements[] = "ALTER TABLE s3_storage_garbage ADD COLUMN IF NOT EXISTS dispatch_id TEXT DEFAULT '00000000000000000000000000000000'";
            $statements[] = "ALTER TABLE s3_multipart_write_intents ADD COLUMN IF NOT EXISTS dispatch_id TEXT DEFAULT '00000000000000000000000000000000'";
        }
        if ($fromVersion < 35) {
            array_push($statements, ...self::completionCapacityStatements());
        }
        if ($fromVersion < 36) {
            array_push($statements, ...self::writeIntentCapacityStatements());
        }
        if ($fromVersion < 37) {
            $statements[] = <<<'SQL'
                DO $$
                BEGIN
                    -- One transactional table lock makes a retry or concurrent
                    -- migrator replace the exact named invariant atomically.
                    LOCK TABLE s3_encryption_configs IN ACCESS EXCLUSIVE MODE;
                    ALTER TABLE s3_encryption_configs
                        DROP CONSTRAINT IF EXISTS chk_s3_encryption_configs_supported;
                    ALTER TABLE s3_encryption_configs
                        ADD CONSTRAINT chk_s3_encryption_configs_supported CHECK (
                            sse_algorithm COLLATE "C" = 'AES256'
                            AND kms_master_key_id IS NULL
                            AND bucket_key_enabled = FALSE
                        );
                END
                $$
                SQL;
        }
        if ($fromVersion < 38) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN IF NOT EXISTS noncurrent_at TIMESTAMPTZ';
            $statements[] = 'UPDATE s3_objects SET noncurrent_at = NOW() WHERE is_latest = FALSE AND noncurrent_at IS NULL';
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent_at ON s3_objects(bucket, is_latest, is_delete_marker, noncurrent_at)';
        }
        if ($fromVersion < 39) {
            $statements[] = "CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_recursive_unsafe ON s3_storage_garbage(storage_tier, operation) WHERE CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1";
        }
        if ($fromVersion < 40) {
            $statements[] = 'ALTER TABLE s3_parts ADD COLUMN IF NOT EXISTS checksum_algorithm TEXT';
            $statements[] = 'ALTER TABLE s3_parts ADD COLUMN IF NOT EXISTS checksum_value TEXT';
        }
        if ($fromVersion < 41) {
            $statements[] = 'ALTER TABLE s3_cors_rules ADD COLUMN IF NOT EXISTS rule_id TEXT';
            $statements[] = 'UPDATE s3_cors_rules SET max_age_seconds = -1 WHERE max_age_seconds = 0';
            $statements[] = 'ALTER TABLE s3_cors_rules ALTER COLUMN max_age_seconds SET DEFAULT -1';
        }
        if ($fromVersion < 42) {
            $statements[] = 'CREATE INDEX IF NOT EXISTS idx_s3_account_quotas_owner_c ON s3_account_quotas(owner_id COLLATE "C")';
        }

        return $statements;
    }

    /** @return list<string> */
    private static function completionCapacityStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_completion_capacity (
                id SMALLINT PRIMARY KEY CHECK (id = 1),
                retained_count BIGINT NOT NULL CHECK (retained_count BETWEEN 0 AND 1000000)
            )
            SQL,
            'INSERT INTO s3_multipart_completion_capacity (id, retained_count) SELECT 1, (SELECT COUNT(*) FROM s3_multipart_write_intents) WHERE NOT EXISTS (SELECT 1 FROM s3_multipart_completion_capacity WHERE id = 1)',
            <<<'SQL'
            CREATE OR REPLACE FUNCTION s3_multipart_completion_capacity_insert_fn() RETURNS trigger AS $$
            BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count + 1
                WHERE id = 1 AND retained_count < 1000000;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Multipart completion recovery capacity is exhausted.';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
            SQL,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION s3_multipart_completion_capacity_delete_fn() RETURNS trigger AS $$
            BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count - 1
                WHERE id = 1 AND retained_count > 0;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Multipart completion recovery capacity is inconsistent.';
                END IF;
                RETURN OLD;
            END
            $$ LANGUAGE plpgsql
            SQL,
            'DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_insert ON s3_multipart_write_intents',
            'CREATE TRIGGER s3_multipart_completion_capacity_insert BEFORE INSERT ON s3_multipart_write_intents FOR EACH ROW EXECUTE FUNCTION s3_multipart_completion_capacity_insert_fn()',
            'DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_delete ON s3_multipart_write_intents',
            'CREATE TRIGGER s3_multipart_completion_capacity_delete BEFORE DELETE ON s3_multipart_write_intents FOR EACH ROW EXECUTE FUNCTION s3_multipart_completion_capacity_delete_fn()',
        ];
    }

    /** @return list<string> */
    private static function writeIntentCapacityStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_write_intent_capacity (
                kind TEXT PRIMARY KEY CHECK (kind IN ('object', 'part')),
                retained_count BIGINT NOT NULL CHECK (retained_count BETWEEN 0 AND 1000000)
            )
            SQL,
            "INSERT INTO s3_write_intent_capacity (kind, retained_count)
             SELECT 'object', (SELECT COUNT(*) FROM s3_object_write_intents)
             WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'object')",
            "INSERT INTO s3_write_intent_capacity (kind, retained_count)
             SELECT 'part', (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent')
             WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'part')",
            <<<'SQL'
            CREATE OR REPLACE FUNCTION s3_write_intent_capacity_object_fn() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                    WHERE kind = 'object' AND retained_count < 1000000;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Object write recovery capacity is exhausted or unavailable.'; END IF;
                    RETURN NEW;
                END IF;
                UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                WHERE kind = 'object' AND retained_count > 0;
                IF NOT FOUND THEN RAISE EXCEPTION 'Object write recovery capacity is inconsistent.'; END IF;
                RETURN OLD;
            END
            $$ LANGUAGE plpgsql
            SQL,
            <<<'SQL'
            CREATE OR REPLACE FUNCTION s3_write_intent_capacity_part_fn() RETURNS trigger AS $$
            DECLARE delta INTEGER := 0;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.operation = 'part_write_intent' THEN delta := 1; END IF;
                ELSIF TG_OP = 'DELETE' THEN
                    IF OLD.operation = 'part_write_intent' THEN delta := -1; END IF;
                ELSIF OLD.operation <> NEW.operation THEN
                    IF NEW.operation = 'part_write_intent' THEN delta := 1;
                    ELSIF OLD.operation = 'part_write_intent' THEN delta := -1;
                    END IF;
                END IF;
                IF delta <> 0 THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count + delta
                    WHERE kind = 'part' AND retained_count + delta BETWEEN 0 AND 1000000;
                    IF NOT FOUND THEN RAISE EXCEPTION 'Multipart part recovery capacity is exhausted or inconsistent.'; END IF;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_object_insert ON s3_object_write_intents',
            'CREATE TRIGGER s3_write_intent_capacity_object_insert BEFORE INSERT ON s3_object_write_intents FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_object_fn()',
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_object_delete ON s3_object_write_intents',
            'CREATE TRIGGER s3_write_intent_capacity_object_delete BEFORE DELETE ON s3_object_write_intents FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_object_fn()',
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_insert ON s3_storage_garbage',
            'CREATE TRIGGER s3_write_intent_capacity_part_insert BEFORE INSERT ON s3_storage_garbage FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_part_fn()',
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_delete ON s3_storage_garbage',
            'CREATE TRIGGER s3_write_intent_capacity_part_delete BEFORE DELETE ON s3_storage_garbage FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_part_fn()',
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_update ON s3_storage_garbage',
            'CREATE TRIGGER s3_write_intent_capacity_part_update BEFORE UPDATE OF operation ON s3_storage_garbage FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_part_fn()',
        ];
    }
}
