<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/**
 * Contains all MySQL DDL statements for the S3 metadata store.
 *
 * All tables use the `s3_` prefix to avoid collisions when sharing a database.
 * Engine: InnoDB with utf8mb4_unicode_ci collation.
 * The complete schema represents VERSION; incremental migrations are returned
 * by getMigrationStatements().
 */
final class MysqlSchema
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
                version INT NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                description TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Restore jobs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_restore_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                version_id VARCHAR(255),
                source_tier VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                restored_storage_path TEXT,
                restore_days INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_restore_jobs_status_next (status, next_attempt_at),
                KEY idx_s3_restore_jobs_object (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Buckets
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_buckets (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                owner_id VARCHAR(255) NOT NULL,
                region VARCHAR(64) NOT NULL DEFAULT 'us-east-1',
                versioning VARCHAR(16) NOT NULL DEFAULT '',
                object_lock_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_buckets_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Account quotas
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id VARCHAR(255) NOT NULL PRIMARY KEY,
                max_buckets_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Transactional owner write locks for quotas and replacement serialization.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_owner_write_locks (
                owner_id VARCHAR(255) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Account policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_policies (
                owner_id VARCHAR(255) NOT NULL PRIMARY KEY,
                policy_json JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Named policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_named_policies (
                policy_name VARCHAR(255) NOT NULL PRIMARY KEY,
                policy_json JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Distributed locks
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name VARCHAR(255) NOT NULL PRIMARY KEY,
                owner_id VARCHAR(255) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_locks_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Lifecycle checkpoints
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_checkpoints (
                bucket VARCHAR(255) NOT NULL,
                rule_id VARCHAR(255) NOT NULL,
                action VARCHAR(64) NOT NULL,
                cursor_key TEXT,
                cursor_version_id VARCHAR(255),
                cursor_upload_id VARCHAR(255),
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket, rule_id, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Physical tier transition jobs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tier_transition_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                version_id VARCHAR(255),
                source_tier VARCHAR(64) NOT NULL,
                target_tier VARCHAR(64) NOT NULL,
                target_storage_class VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                target_storage_path TEXT,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_tier_jobs_status_next (status, next_attempt_at),
                KEY idx_s3_tier_jobs_object (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Objects
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_objects (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                version_id VARCHAR(255) NOT NULL DEFAULT 'null',
                is_latest TINYINT(1) NOT NULL DEFAULT 1,
                is_delete_marker TINYINT(1) NOT NULL DEFAULT 0,
                owner_id VARCHAR(255) NOT NULL,
                etag VARCHAR(255) NOT NULL,
                size BIGINT UNSIGNED NOT NULL,
                content_type VARCHAR(255) NOT NULL DEFAULT 'application/octet-stream',
                content_encoding VARCHAR(255),
                content_disposition VARCHAR(1024),
                cache_control VARCHAR(255),
                storage_class VARCHAR(64) NOT NULL DEFAULT 'STANDARD',
                storage_tier VARCHAR(64) NOT NULL DEFAULT 'STANDARD',
                transition_status VARCHAR(32) NOT NULL DEFAULT 'available',
                transition_target_tier VARCHAR(64),
                transition_error TEXT,
                restore_status VARCHAR(32),
                restored_storage_path TEXT,
                restore_expires_at TIMESTAMP NULL,
                storage_path TEXT NOT NULL,
                user_metadata JSON NOT NULL,
                checksum_crc32 VARCHAR(64),
                checksum_crc32c VARCHAR(64),
                checksum_sha1 VARCHAR(64),
                checksum_sha256 VARCHAR(128),
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_objects_bkv (bucket, key_name(255), version_id),
                KEY idx_s3_objects_bucket_key (bucket, key_name(255)),
                KEY idx_s3_objects_bucket_latest (bucket, is_latest, is_delete_marker)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Multipart uploads
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_uploads (
                upload_id VARCHAR(255) NOT NULL PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                owner_id VARCHAR(255) NOT NULL,
                content_type VARCHAR(255) DEFAULT 'application/octet-stream',
                user_metadata JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_multipart_uploads_bucket (bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Parts
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_parts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                upload_id VARCHAR(255) NOT NULL,
                part_number INT NOT NULL,
                etag VARCHAR(255) NOT NULL,
                size BIGINT UNSIGNED NOT NULL,
                storage_path TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_parts_upload_part (upload_id, part_number),
                FOREIGN KEY (upload_id) REFERENCES s3_multipart_uploads(upload_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // ACLs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_acls (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                resource_type VARCHAR(64) NOT NULL,
                resource_name VARCHAR(512) NOT NULL,
                grantee_type VARCHAR(64) NOT NULL,
                grantee_id VARCHAR(255) NOT NULL,
                permission VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_acls_resource (resource_type, resource_name(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Tagging
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tagging (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                resource_type VARCHAR(64) NOT NULL,
                bucket VARCHAR(255) NOT NULL,
                key_name VARCHAR(1024),
                tag_key VARCHAR(128) NOT NULL,
                tag_value VARCHAR(256) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_tagging (resource_type, bucket, key_name(255), tag_key),
                KEY idx_s3_tagging_resource (resource_type, bucket, key_name(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_policies (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL UNIQUE,
                policy_json LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // CORS rules
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_cors_rules (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                rule_order INT NOT NULL DEFAULT 0,
                allowed_origins JSON NOT NULL,
                allowed_methods JSON NOT NULL,
                allowed_headers JSON NOT NULL,
                expose_headers JSON NOT NULL,
                max_age_seconds INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_cors_rules_bucket (bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Encryption configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_encryption_configs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL UNIQUE,
                sse_algorithm VARCHAR(64) NOT NULL DEFAULT 'AES256',
                kms_master_key_id VARCHAR(255),
                bucket_key_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Lifecycle rules
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_rules (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                rule_id VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'Enabled',
                prefix TEXT,
                filter_json JSON,
                transitions_json JSON,
                expiration_json JSON,
                noncurrent_transitions_json JSON,
                noncurrent_expiration_json JSON,
                abort_incomplete_days INT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_lifecycle_rules (bucket, rule_id),
                KEY idx_s3_lifecycle_rules_bucket (bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Object lock configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lock_configs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL UNIQUE,
                object_lock_enabled TINYINT(1) NOT NULL DEFAULT 0,
                default_retention_mode VARCHAR(16),
                default_retention_days INT,
                default_retention_years INT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Object retention
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_retention (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name VARCHAR(1024) NOT NULL,
                version_id VARCHAR(255) NOT NULL DEFAULT 'null',
                mode VARCHAR(16) NOT NULL,
                retain_until_date VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_object_retention (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Legal holds
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_legal_holds (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name VARCHAR(1024) NOT NULL,
                version_id VARCHAR(255) NOT NULL DEFAULT 'null',
                status VARCHAR(8) NOT NULL DEFAULT 'OFF',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_object_legal_holds (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Notification configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_configs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                config_id VARCHAR(255) NOT NULL,
                event_type TEXT NOT NULL,
                destination_type VARCHAR(64) NOT NULL,
                destination_arn VARCHAR(512) NOT NULL,
                filter_rules_json JSON,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_notification_configs (bucket, config_id),
                KEY idx_s3_notification_configs_bucket (bucket)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Website configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_website_configs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL UNIQUE,
                index_document VARCHAR(255) NOT NULL DEFAULT 'index.html',
                error_document VARCHAR(255),
                redirect_all_host VARCHAR(255),
                redirect_all_protocol VARCHAR(16),
                routing_rules_json JSON,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Public access blocks
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_public_access_blocks (
                bucket VARCHAR(255) NOT NULL UNIQUE,
                block_public_acls TINYINT(1) NOT NULL DEFAULT 0,
                ignore_public_acls TINYINT(1) NOT NULL DEFAULT 0,
                block_public_policy TINYINT(1) NOT NULL DEFAULT 0,
                restrict_public_buckets TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Bucket logging
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_bucket_logging (
                bucket VARCHAR(255) NOT NULL UNIQUE,
                target_bucket VARCHAR(255) NOT NULL,
                target_prefix VARCHAR(1024) NOT NULL DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Lifecycle indexes (version 3) are applied via getMigrationStatements()
            // because MySQL lacks CREATE INDEX IF NOT EXISTS.

            // Rate limit buckets (version 4)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_rate_limit_buckets (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                tokens DOUBLE NOT NULL,
                last_refill_at DOUBLE NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Notification queue (version 4)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_queue (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                event_name VARCHAR(255) NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_nq_status_next (status, next_attempt_at),
                KEY idx_s3_nq_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

        ];
    }

    /**
     * Get migration DDL statements for upgrading from an older version.
     *
     * These statements may fail if indexes already exist (duplicate key name).
     * Callers should execute them with error tolerance.
     *
     * @return list<string>
     */
    public static function getMigrationStatements(int $fromVersion): array
    {
        $statements = [];

        if ($fromVersion < 3) {
            $statements[] = 'CREATE INDEX idx_s3_objects_lifecycle ON s3_objects(bucket, is_latest, is_delete_marker, created_at)';
            $statements[] = 'CREATE INDEX idx_s3_objects_noncurrent ON s3_objects(bucket, is_latest, created_at)';
            $statements[] = 'CREATE INDEX idx_s3_mpu_lifecycle ON s3_multipart_uploads(bucket, created_at)';
        }

        if ($fromVersion < 4) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_rate_limit_buckets (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                tokens DOUBLE NOT NULL,
                last_refill_at DOUBLE NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_queue (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                event_name VARCHAR(255) NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_nq_status_next (status, next_attempt_at),
                KEY idx_s3_nq_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 5) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id VARCHAR(255) NOT NULL PRIMARY KEY,
                max_buckets_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 6) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name VARCHAR(255) NOT NULL PRIMARY KEY,
                owner_id VARCHAR(255) NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_locks_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 7) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_checkpoints (
                bucket VARCHAR(255) NOT NULL,
                rule_id VARCHAR(255) NOT NULL,
                action VARCHAR(64) NOT NULL,
                cursor_key TEXT,
                cursor_version_id VARCHAR(255),
                cursor_upload_id VARCHAR(255),
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket, rule_id, action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 9) {
            $statements[] = "ALTER TABLE s3_objects ADD COLUMN storage_tier VARCHAR(64) NOT NULL DEFAULT 'STANDARD'";
            $statements[] = "ALTER TABLE s3_objects ADD COLUMN transition_status VARCHAR(32) NOT NULL DEFAULT 'available'";
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN transition_target_tier VARCHAR(64) NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN transition_error TEXT NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN restore_status VARCHAR(32) NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN restored_storage_path TEXT NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN restore_expires_at TIMESTAMP NULL';
            $statements[] = 'CREATE INDEX idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)';
        }

        if ($fromVersion < 10) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_tier_transition_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                version_id VARCHAR(255),
                source_tier VARCHAR(64) NOT NULL,
                target_tier VARCHAR(64) NOT NULL,
                target_storage_class VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                target_storage_path TEXT,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_tier_jobs_status_next (status, next_attempt_at),
                KEY idx_s3_tier_jobs_object (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 11) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_restore_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name TEXT NOT NULL,
                version_id VARCHAR(255),
                source_tier VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                restored_storage_path TEXT,
                restore_days INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_s3_restore_jobs_status_next (status, next_attempt_at),
                KEY idx_s3_restore_jobs_object (bucket, key_name(255), version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 12) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_owner_write_locks (
                owner_id VARCHAR(255) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        return $statements;
    }
}
