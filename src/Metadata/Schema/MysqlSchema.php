<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/**
 * Contains all MySQL DDL statements for the S3 metadata store.
 *
 * All tables use the `s3_` prefix to avoid collisions when sharing a database.
 * Engine: InnoDB with utf8mb4_unicode_ci collation for non-key text. S3 object
 * keys and ACL resource names use binary columns for exact byte identity.
 * The complete schema represents VERSION; incremental migrations are returned
 * by getMigrationStatements().
 */
final class MysqlSchema
{
    /** @var int Current schema version. */
    public const int VERSION = 51;

    /**
     * Get the complete schema DDL for the current version.
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
                key_name VARBINARY(1024) NOT NULL,
                version_id VARBINARY(255),
                source_tier VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                restored_storage_path TEXT,
                restore_days INT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
                owner_id VARBINARY(255) NOT NULL,
                region VARCHAR(64) NOT NULL DEFAULT 'us-east-1',
                generation CHAR(32) NOT NULL DEFAULT '',
                versioning VARCHAR(16) NOT NULL DEFAULT '',
                object_lock_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_buckets_owner (owner_id),
                KEY idx_s3_buckets_owner_name (owner_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_bucket_ownership_controls (
                bucket VARCHAR(255) NOT NULL PRIMARY KEY,
                object_ownership VARCHAR(32) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Account quotas
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id VARBINARY(255) NOT NULL PRIMARY KEY,
                max_buckets_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_objects_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_bytes_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_multipart_uploads_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_multipart_uploads_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_multipart_bytes_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0,
                max_multipart_bytes_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Transactional owner write locks for quotas and replacement serialization.
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_owner_write_locks (
                owner_id VARBINARY(255) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Account policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_policies (
                owner_id VARBINARY(255) NOT NULL PRIMARY KEY,
                policy_json JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Named policies
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_named_policies (
                policy_name VARBINARY(255) NOT NULL PRIMARY KEY,
                policy_json JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Distributed locks
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name VARBINARY(255) NOT NULL PRIMARY KEY,
                owner_id VARBINARY(255) NOT NULL,
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
                rule_id VARBINARY(1020) NOT NULL,
                action VARCHAR(64) NOT NULL,
                cursor_key TEXT,
                cursor_version_id VARBINARY(255),
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
                key_name VARBINARY(1024) NOT NULL,
                version_id VARBINARY(255),
                source_tier VARCHAR(64) NOT NULL,
                target_tier VARCHAR(64) NOT NULL,
                target_storage_class VARCHAR(64) NOT NULL,
                source_storage_path TEXT NOT NULL,
                origin VARCHAR(16) NOT NULL DEFAULT 'legacy',
                lifecycle_rule_id VARBINARY(1020) NULL,
                expected_is_latest TINYINT NULL,
                target_storage_path TEXT,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
                key_name VARBINARY(1024) NOT NULL,
                key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED,
                version_id VARBINARY(255) NOT NULL DEFAULT 'null',
                is_latest TINYINT(1) NOT NULL DEFAULT 1,
                is_delete_marker TINYINT(1) NOT NULL DEFAULT 0,
                owner_id VARBINARY(255) NOT NULL,
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
                restored_storage_tier VARBINARY(64) NULL,
                restore_expires_at DATETIME NULL,
                storage_path TEXT NOT NULL,
                user_metadata JSON NOT NULL,
                checksum_crc32 VARCHAR(64),
                checksum_crc32c VARCHAR(64),
                checksum_sha1 VARCHAR(64),
                checksum_sha256 VARCHAR(128),
                checksum_crc64nvme VARCHAR(64),
                part_layout_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
                part_layout_count INT NULL,
                part_layout_compat_count INT NULL,
                part_layout_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                noncurrent_at TIMESTAMP NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_objects_exact (bucket, key_identity, version_id),
                KEY idx_s3_objects_bucket_key (bucket, key_name(255)),
                KEY idx_s3_objects_bucket_latest (bucket, is_latest, is_delete_marker),
                KEY idx_s3_objects_owner (owner_id, is_delete_marker),
                KEY idx_s3_objects_tier_state (storage_tier, transition_status, restore_status),
                KEY idx_s3_objects_transition_target_tier (transition_target_tier),
                KEY idx_s3_objects_noncurrent_at (bucket, is_latest, is_delete_marker, noncurrent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_parts (
                object_id BIGINT NOT NULL,
                ordinal INT NOT NULL,
                upload_part_number INT NULL,
                plaintext_offset BIGINT UNSIGNED NOT NULL,
                plaintext_size BIGINT UNSIGNED NOT NULL,
                checksum_algorithm VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                checksum_value VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
                PRIMARY KEY (object_id, ordinal),
                FOREIGN KEY (object_id) REFERENCES s3_objects(id) ON DELETE CASCADE,
                CONSTRAINT chk_s3_object_parts_ordinal CHECK (ordinal BETWEEN 1 AND 10000),
                CONSTRAINT chk_s3_object_parts_upload_number CHECK (upload_part_number IS NULL OR upload_part_number BETWEEN 1 AND 10000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (
                migration_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                last_object_id BIGINT UNSIGNED NOT NULL,
                target_object_id BIGINT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Multipart uploads
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_uploads (
                upload_id VARCHAR(255) NOT NULL PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                bucket_generation CHAR(32) NOT NULL DEFAULT '',
                key_name VARBINARY(1024) NOT NULL,
                owner_id VARBINARY(255) NOT NULL,
                storage_tier VARBINARY(64) NULL,
                content_type VARCHAR(255) DEFAULT 'application/octet-stream',
                user_metadata JSON NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_multipart_uploads_bucket (bucket),
                KEY idx_s3_multipart_uploads_owner (owner_id)
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
                checksum_algorithm VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                checksum_value VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
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
                resource_name VARBINARY(2048) NOT NULL,
                grantee_type VARCHAR(64) NOT NULL,
                grantee_id VARBINARY(255) NOT NULL,
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
                key_name VARBINARY(1024),
                version_id VARBINARY(255) NOT NULL DEFAULT 'null',
                tag_key VARCHAR(128) NOT NULL,
                tag_value VARCHAR(256) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                tag_identity BINARY(32) GENERATED ALWAYS AS (
                    UNHEX(SHA2(CONCAT(
                        LENGTH(resource_type), ':', resource_type,
                        LENGTH(bucket), ':', bucket,
                        IF(key_name IS NULL, '-1:', CONCAT(LENGTH(key_name), ':', key_name)),
                        LENGTH(version_id), ':', version_id,
                        LENGTH(tag_key), ':', tag_key
                    ), 256))
                ) STORED,
                UNIQUE KEY uq_s3_tagging_version (tag_identity),
                KEY idx_s3_tagging_resource (resource_type, bucket(63), key_name(191), version_id(64))
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
                rule_id VARBINARY(1020) NULL,
                allowed_origins JSON NOT NULL,
                allowed_methods JSON NOT NULL,
                allowed_headers JSON NOT NULL,
                expose_headers JSON NOT NULL,
                max_age_seconds INT NOT NULL DEFAULT -1,
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
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT chk_s3_encryption_configs_supported CHECK (
                    sse_algorithm COLLATE utf8mb4_bin = 'AES256'
                    AND kms_master_key_id IS NULL
                    AND bucket_key_enabled = 0
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Lifecycle rules
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_lifecycle_rules (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                rule_id VARBINARY(1020) NOT NULL,
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
                key_name VARBINARY(1024) NOT NULL,
                key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED,
                version_id VARBINARY(255) NOT NULL DEFAULT 'null',
                mode VARCHAR(16) NOT NULL,
                retain_until_date VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_object_retention_exact (bucket, key_identity, version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Legal holds
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_legal_holds (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                key_name VARBINARY(1024) NOT NULL,
                key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED,
                version_id VARBINARY(255) NOT NULL DEFAULT 'null',
                status VARCHAR(8) NOT NULL DEFAULT 'OFF',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_object_legal_holds_exact (bucket, key_identity, version_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Notification configs
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_notification_configs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                config_id VARBINARY(1020) NOT NULL,
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
                index_document MEDIUMTEXT NOT NULL,
                error_document MEDIUMTEXT,
                redirect_all_host MEDIUMTEXT,
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
                target_prefix MEDIUMTEXT NOT NULL,
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
                key_name VARBINARY(1024) NOT NULL,
                event_name VARCHAR(255) NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 10,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_nq_status_next (status, next_attempt_at),
                KEY idx_s3_nq_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            // Durable physical storage cleanup queue (version 15)
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_garbage (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                storage_tier VARCHAR(64) NOT NULL,
                storage_path VARCHAR(1024) NOT NULL,
                operation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'delete_path',
                recovery_admission TINYINT(1) NOT NULL DEFAULT 0 CHECK (recovery_admission IN (0, 1)),
                key_name VARBINARY(1024) NULL,
                upload_id VARCHAR(255) NULL,
                owner_id VARBINARY(255) NULL,
                lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
                dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                recursive_cleanup_unsafe TINYINT(1) GENERATED ALWAYS AS (
                    CASE WHEN operation IS NULL OR NOT (
                        BINARY operation = BINARY 'delete_path'
                        OR BINARY operation = BINARY 'part_write_intent'
                    ) THEN 1 ELSE 0 END
                ) VIRTUAL,
                CONSTRAINT chk_s3_storage_garbage_operation CHECK (
                    BINARY operation IN (
                        BINARY 'delete_path',
                        BINARY 'abort_multipart',
                        BINARY 'part_write_intent'
                    )
                ),
                KEY idx_s3_storage_gc_due (next_attempt_at, id),
                KEY idx_s3_storage_gc_operation_owner (operation, owner_id),
                KEY idx_s3_storage_gc_recursive_unsafe (recursive_cleanup_unsafe, storage_tier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_write_intents (
                attempt_id CHAR(32) NOT NULL PRIMARY KEY,
                upload_id VARCHAR(255) NOT NULL,
                active_upload_id VARCHAR(255) NULL UNIQUE,
                bucket VARCHAR(255) NOT NULL,
                bucket_generation CHAR(32) NOT NULL,
                owner_id VARBINARY(255) NOT NULL,
                storage_tier VARCHAR(64) NOT NULL,
                assembly_path TEXT NOT NULL,
                final_path TEXT NOT NULL,
                part_digest CHAR(64) NOT NULL,
                sse_mode VARCHAR(16) NULL,
                state VARCHAR(16) NOT NULL DEFAULT 'active',
                lease_until DOUBLE NOT NULL,
                next_attempt_at DOUBLE NOT NULL,
                claim_generation BIGINT NOT NULL DEFAULT 0,
                dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_multipart_intents_due (state, next_attempt_at),
                KEY idx_s3_multipart_intents_upload (upload_id),
                KEY idx_s3_multipart_intents_tier (storage_tier),
                KEY idx_s3_multipart_intents_owner (owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_write_intents (
                attempt_id CHAR(32) NOT NULL PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                bucket_generation CHAR(32) NOT NULL,
                owner_id VARBINARY(255) NOT NULL,
                storage_tier VARBINARY(64) NOT NULL,
                storage_path TEXT NOT NULL,
                path_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                state VARCHAR(16) NOT NULL DEFAULT 'active',
                lease_until DOUBLE NOT NULL,
                claim_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
                dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000',
                expected_size BIGINT UNSIGNED NULL,
                expected_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                seal_reserved TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_s3_object_write_intents_path (storage_tier, path_digest),
                KEY idx_s3_object_write_intents_owner (owner_id),
                KEY idx_s3_object_write_intents_due (state, lease_until),
                KEY idx_s3_object_write_intents_tier (storage_tier),
                KEY idx_s3_object_write_intents_owner_seal (owner_id, seal_reserved)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,

            ...self::completionCapacityStatements(),
            ...self::writeIntentCapacityStatements(),
            ...self::storageCleanupCapacityStatements(),
            ...self::recoveryCapacityTriggerInventoryFunctionStatements(false),
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
                owner_id VARBINARY(255) NOT NULL PRIMARY KEY,
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
                lock_name VARBINARY(255) NOT NULL PRIMARY KEY,
                owner_id VARBINARY(255) NOT NULL,
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
                owner_id VARBINARY(255) NOT NULL PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 13) {
            $statements[] = "ALTER TABLE s3_tagging ADD COLUMN version_id VARCHAR(255) NOT NULL DEFAULT 'null' AFTER key_name";
            $statements[] = 'ALTER TABLE s3_tagging DROP INDEX uk_s3_tagging';
            $statements[] = 'ALTER TABLE s3_tagging DROP INDEX idx_s3_tagging_resource';
            $statements[] = <<<'SQL'
            ALTER TABLE s3_tagging ADD COLUMN tag_identity BINARY(32) GENERATED ALWAYS AS (
                UNHEX(SHA2(CONCAT(
                    LENGTH(resource_type), ':', resource_type,
                    LENGTH(bucket), ':', bucket,
                    IF(key_name IS NULL, '-1:', CONCAT(LENGTH(key_name), ':', key_name)),
                    LENGTH(version_id), ':', version_id,
                    LENGTH(tag_key), ':', tag_key
                ), 256))
            ) STORED
            SQL;
            $statements[] = 'ALTER TABLE s3_tagging ADD UNIQUE KEY uq_s3_tagging_version (tag_identity)';
            $statements[] = 'ALTER TABLE s3_tagging ADD KEY idx_s3_tagging_resource (resource_type, bucket(63), key_name(191), version_id(64))';
        }

        if ($fromVersion < 14) {
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN max_multipart_uploads_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN max_multipart_uploads_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN max_multipart_bytes_per_bucket BIGINT UNSIGNED NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_account_quotas ADD COLUMN max_multipart_bytes_per_owner BIGINT UNSIGNED NOT NULL DEFAULT 0';
        }

        if ($fromVersion < 15) {
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_garbage (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(255) NOT NULL,
                storage_tier VARCHAR(64) NOT NULL,
                storage_path VARCHAR(1024) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                next_attempt_at DOUBLE NOT NULL,
                last_error TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_storage_gc_status (status, next_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 16) {
            // MySQL's default collation folds case, accents, and trailing spaces.
            // Prefix-unique indexes also identify different long S3 keys as one.
            // Install the full-key hash index before removing the old constraint;
            // every statement can be retried after a partial DDL migration.
            $statements[] = 'ALTER TABLE s3_objects MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED';
            $statements[] = 'ALTER TABLE s3_objects ADD UNIQUE KEY uk_s3_objects_exact (bucket, key_identity, version_id)';
            $statements[] = 'ALTER TABLE s3_objects DROP INDEX uk_s3_objects_bkv';

            $statements[] = 'ALTER TABLE s3_object_retention MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_object_retention ADD COLUMN key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED';
            $statements[] = 'ALTER TABLE s3_object_retention ADD UNIQUE KEY uk_s3_object_retention_exact (bucket, key_identity, version_id)';
            $statements[] = 'ALTER TABLE s3_object_retention DROP INDEX uk_s3_object_retention';

            $statements[] = 'ALTER TABLE s3_object_legal_holds MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_object_legal_holds ADD COLUMN key_identity BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(key_name, 256))) STORED';
            $statements[] = 'ALTER TABLE s3_object_legal_holds ADD UNIQUE KEY uk_s3_object_legal_holds_exact (bucket, key_identity, version_id)';
            $statements[] = 'ALTER TABLE s3_object_legal_holds DROP INDEX uk_s3_object_legal_holds';

            $statements[] = 'ALTER TABLE s3_multipart_uploads MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_tagging MODIFY key_name VARBINARY(1024) NULL';
            $statements[] = 'ALTER TABLE s3_acls MODIFY resource_name VARBINARY(2048) NOT NULL';
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_restore_jobs MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = 'ALTER TABLE s3_notification_queue MODIFY key_name VARBINARY(1024) NOT NULL';
            $statements[] = "ALTER TABLE s3_objects MODIFY version_id VARBINARY(255) NOT NULL DEFAULT 'null'";
            $statements[] = "ALTER TABLE s3_tagging MODIFY version_id VARBINARY(255) NOT NULL DEFAULT 'null'";
            $statements[] = "ALTER TABLE s3_object_retention MODIFY version_id VARBINARY(255) NOT NULL DEFAULT 'null'";
            $statements[] = "ALTER TABLE s3_object_legal_holds MODIFY version_id VARBINARY(255) NOT NULL DEFAULT 'null'";
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs MODIFY version_id VARBINARY(255) NULL';
            $statements[] = 'ALTER TABLE s3_restore_jobs MODIFY version_id VARBINARY(255) NULL';
            $statements[] = 'ALTER TABLE s3_lifecycle_checkpoints MODIFY cursor_version_id VARBINARY(255) NULL';
        }

        if ($fromVersion < 17) {
            $statements[] = 'CREATE INDEX idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)';
            $statements[] = 'CREATE INDEX idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)';
        }

        if ($fromVersion < 18) {
            $statements[] = 'CREATE INDEX idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)';
        }

        if ($fromVersion < 19) {
            $statements[] = 'CREATE TABLE IF NOT EXISTS s3_bucket_ownership_controls (bucket VARCHAR(255) NOT NULL PRIMARY KEY, object_ownership VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        if ($fromVersion < 20) {
            $statements[] = 'CREATE INDEX idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)';
            $statements[] = 'CREATE INDEX idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)';
        }

        if ($fromVersion < 21) {
            $statements[] = "ALTER TABLE s3_buckets ADD COLUMN generation CHAR(32) NOT NULL DEFAULT ''";
            $statements[] = "UPDATE s3_buckets SET generation = REPLACE(UUID(), '-', '') WHERE generation = ''";
        }

        if ($fromVersion < 22) {
            $statements[] = "ALTER TABLE s3_storage_garbage ADD COLUMN operation VARCHAR(32) NOT NULL DEFAULT 'delete_path'";
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN key_name VARBINARY(1024) NULL';
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN upload_id VARCHAR(255) NULL';
        }

        if ($fromVersion < 23) {
            // Account and credential policy identifiers are byte strings. A
            // case/accent-folding collation can expose another owner's bucket
            // listing, aggregate their bytes, or attach their quota/policy.
            $statements[] = 'ALTER TABLE s3_buckets MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_objects MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_multipart_uploads MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_account_quotas MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_account_policies MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_owner_write_locks MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_locks MODIFY lock_name VARBINARY(255) NOT NULL, MODIFY owner_id VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_named_policies MODIFY policy_name VARBINARY(255) NOT NULL';
            $statements[] = 'ALTER TABLE s3_acls MODIFY grantee_id VARBINARY(255) NOT NULL';
        }

        if ($fromVersion < 24) {
            // MySQL DDL auto-commits. Separate additions let a retry finish
            // after a crash between columns without masking missing columns.
            $statements[] = "ALTER TABLE s3_tier_transition_jobs ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT 'legacy'";
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs ADD COLUMN lifecycle_rule_id VARBINARY(255) NULL';
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs ADD COLUMN expected_is_latest TINYINT NULL';
        }

        if ($fromVersion < 25) {
            $statements[] = "ALTER TABLE s3_multipart_uploads ADD COLUMN bucket_generation CHAR(32) NOT NULL DEFAULT ''";
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_write_intents (
                attempt_id CHAR(32) NOT NULL PRIMARY KEY,
                upload_id VARCHAR(255) NOT NULL,
                active_upload_id VARCHAR(255) NULL UNIQUE,
                bucket VARCHAR(255) NOT NULL,
                bucket_generation CHAR(32) NOT NULL,
                owner_id VARBINARY(255) NOT NULL,
                storage_tier VARCHAR(64) NOT NULL,
                assembly_path TEXT NOT NULL,
                final_path TEXT NOT NULL,
                part_digest CHAR(64) NOT NULL,
                sse_mode VARCHAR(16) NULL,
                state VARCHAR(16) NOT NULL DEFAULT 'active',
                lease_until DOUBLE NOT NULL,
                next_attempt_at DOUBLE NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_s3_multipart_intents_due (state, next_attempt_at),
                KEY idx_s3_multipart_intents_upload (upload_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
        }

        if ($fromVersion < 26) {
            $statements[] = 'ALTER TABLE s3_multipart_write_intents ADD INDEX idx_s3_multipart_intents_tier (storage_tier)';
        }

        if ($fromVersion < 27) {
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN owner_id VARBINARY(255) NULL';
            $statements[] = <<<'SQL'
                UPDATE s3_storage_garbage AS garbage
                JOIN s3_multipart_uploads AS upload
                  ON garbage.upload_id = upload.upload_id AND garbage.bucket = upload.bucket
                SET garbage.owner_id = upload.owner_id
                WHERE garbage.operation = 'part_write_intent'
                  AND garbage.owner_id IS NULL
                  AND SUBSTRING(garbage.storage_path, -65, 32) = upload.bucket_generation
                SQL;
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD INDEX idx_s3_storage_gc_operation_owner (operation, owner_id)';
        }

        if ($fromVersion < 28) {
            foreach (['s3_storage_garbage', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_notification_queue'] as $table) {
                $statements[] = "ALTER TABLE {$table} ADD COLUMN lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0";
            }
        }

        if ($fromVersion < 29) {
            $statements[] = 'ALTER TABLE s3_multipart_write_intents ADD INDEX idx_s3_multipart_intents_owner (owner_id)';
        }

        if ($fromVersion < 30) {
            $statements[] = <<<'SQL'
                CREATE TABLE IF NOT EXISTS s3_object_write_intents (
                    attempt_id CHAR(32) NOT NULL PRIMARY KEY,
                    bucket VARCHAR(255) NOT NULL,
                    bucket_generation CHAR(32) NOT NULL,
                    owner_id VARBINARY(255) NOT NULL,
                    storage_tier VARBINARY(64) NOT NULL,
                    storage_path TEXT NOT NULL,
                    path_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    purpose VARCHAR(32) NOT NULL,
                    state VARCHAR(16) NOT NULL DEFAULT 'active',
                    lease_until DOUBLE NOT NULL,
                    claim_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_s3_object_write_intents_path (storage_tier, path_digest),
                    KEY idx_s3_object_write_intents_owner (owner_id),
                    KEY idx_s3_object_write_intents_due (state, lease_until),
                    KEY idx_s3_object_write_intents_tier (storage_tier)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL;
        }

        if ($fromVersion < 31) {
            // A case/accent-insensitive collation silently merges distinct
            // lifecycle and notification identifiers. Keep the prior maximum
            // storage width (255 utf8mb4 characters = 1020 bytes) so an
            // existing multibyte ID survives the conversion unchanged.
            $statements[] = 'ALTER TABLE s3_lifecycle_rules MODIFY rule_id VARBINARY(1020) NOT NULL';
            $statements[] = 'ALTER TABLE s3_lifecycle_checkpoints MODIFY rule_id VARBINARY(1020) NOT NULL';
            $statements[] = 'ALTER TABLE s3_notification_configs MODIFY config_id VARBINARY(1020) NOT NULL';
        }

        if ($fromVersion < 32) {
            $statements[] = "ALTER TABLE s3_object_write_intents ADD COLUMN dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000'";
        }
        if ($fromVersion < 33) {
            $statements[] = 'ALTER TABLE s3_multipart_uploads ADD COLUMN storage_tier VARBINARY(64) NULL';
        }
        if ($fromVersion < 34) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN restored_storage_tier VARBINARY(64) NULL';
        }
        if ($fromVersion < 35) {
            $statements[] = 'ALTER TABLE s3_multipart_write_intents ADD COLUMN claim_generation BIGINT NOT NULL DEFAULT 0';
        }
        if ($fromVersion < 36) {
            $statements[] = "ALTER TABLE s3_storage_garbage ADD COLUMN dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000'";
            $statements[] = "ALTER TABLE s3_multipart_write_intents ADD COLUMN dispatch_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT '00000000000000000000000000000000'";
        }
        if ($fromVersion < 37) {
            array_push($statements, ...self::completionCapacityStatements());
        }
        if ($fromVersion < 38) {
            array_push($statements, ...self::writeIntentCapacityStatements());
        }
        if ($fromVersion < 39) {
            $statements[] = <<<'SQL'
                ALTER TABLE s3_encryption_configs
                ADD CONSTRAINT chk_s3_encryption_configs_supported CHECK (
                    sse_algorithm COLLATE utf8mb4_bin = 'AES256'
                    AND kms_master_key_id IS NULL
                    AND bucket_key_enabled = 0
                )
                SQL;
        }
        if ($fromVersion < 40) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN noncurrent_at TIMESTAMP NULL';
            $statements[] = 'UPDATE s3_objects SET noncurrent_at = CURRENT_TIMESTAMP WHERE is_latest = 0 AND noncurrent_at IS NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD INDEX idx_s3_objects_noncurrent_at (bucket, is_latest, is_delete_marker, noncurrent_at)';
        }
        if ($fromVersion < 41) {
            $statements[] = 'ALTER TABLE s3_objects MODIFY COLUMN restore_expires_at DATETIME NULL';
        }
        if ($fromVersion < 42) {
            $statements[] = <<<'SQL'
                ALTER TABLE s3_storage_garbage ADD COLUMN recursive_cleanup_unsafe TINYINT(1)
                GENERATED ALWAYS AS (
                    CASE WHEN operation IS NULL OR NOT (
                        BINARY operation = BINARY 'delete_path'
                        OR BINARY operation = BINARY 'part_write_intent'
                    ) THEN 1 ELSE 0 END
                ) VIRTUAL
                SQL;
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD INDEX idx_s3_storage_gc_recursive_unsafe (recursive_cleanup_unsafe, storage_tier)';
        }
        if ($fromVersion < 43) {
            $statements[] = 'ALTER TABLE s3_parts ADD COLUMN checksum_algorithm VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL';
            $statements[] = 'ALTER TABLE s3_parts ADD COLUMN checksum_value VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL';
        }
        if ($fromVersion < 44) {
            $statements[] = 'ALTER TABLE s3_cors_rules ADD COLUMN rule_id VARBINARY(255) NULL AFTER rule_order';
            $statements[] = 'UPDATE s3_cors_rules SET max_age_seconds = -1 WHERE max_age_seconds = 0';
            $statements[] = 'ALTER TABLE s3_cors_rules MODIFY COLUMN max_age_seconds INT NOT NULL DEFAULT -1';
            $statements[] = 'ALTER TABLE s3_website_configs MODIFY COLUMN index_document MEDIUMTEXT NOT NULL, MODIFY COLUMN error_document MEDIUMTEXT NULL, MODIFY COLUMN redirect_all_host MEDIUMTEXT NULL';
            $statements[] = 'ALTER TABLE s3_bucket_logging MODIFY COLUMN target_prefix MEDIUMTEXT NOT NULL';
        }
        if ($fromVersion < 45) {
            $statements[] = 'ALTER TABLE s3_cors_rules MODIFY COLUMN rule_id VARBINARY(1020) NULL';
        }
        if ($fromVersion < 46) {
            $statements[] = 'ALTER TABLE s3_tier_transition_jobs MODIFY COLUMN lifecycle_rule_id VARBINARY(1020) NULL';
        }
        if ($fromVersion < 47) {
            array_push($statements, ...self::recoveryCapacityTriggerInventoryFunctionStatements(true));
        }
        if ($fromVersion < 48) {
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD COLUMN recovery_admission TINYINT(1) NOT NULL DEFAULT 0 CHECK (recovery_admission IN (0, 1))';
            $statements[] = <<<'SQL'
                UPDATE s3_storage_garbage SET operation = CASE
                    WHEN operation = 'delete_path' THEN 'delete_path'
                    WHEN operation = 'abort_multipart' THEN 'abort_multipart'
                    WHEN operation = 'part_write_intent' THEN 'part_write_intent'
                    ELSE operation
                END
                SQL;
            $statements[] = "ALTER TABLE s3_storage_garbage MODIFY COLUMN operation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'delete_path'";
            $statements[] = <<<'SQL'
                ALTER TABLE s3_storage_garbage
                ADD CONSTRAINT chk_s3_storage_garbage_operation CHECK (
                    BINARY operation IN (
                        BINARY 'delete_path',
                        BINARY 'abort_multipart',
                        BINARY 'part_write_intent'
                    )
                )
                SQL;
            $statements[] = 'ALTER TABLE s3_storage_garbage ADD INDEX idx_s3_storage_gc_due (next_attempt_at, id)';
            $statements[] = 'ALTER TABLE s3_storage_garbage DROP INDEX idx_s3_storage_gc_status';
            array_push($statements, ...self::writeIntentCapacityStatements());
            array_push($statements, ...self::storageCleanupCapacityStatements());
            array_push($statements, ...self::recoveryCapacityTriggerInventoryFunctionStatements(true));
        }
        if ($fromVersion < 49) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN part_layout_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN part_layout_count INT NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN part_layout_compat_count INT NULL';
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN part_layout_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL';
            $statements[] = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_object_parts (
                object_id BIGINT NOT NULL,
                ordinal INT NOT NULL,
                upload_part_number INT NULL,
                plaintext_offset BIGINT UNSIGNED NOT NULL,
                plaintext_size BIGINT UNSIGNED NOT NULL,
                checksum_algorithm VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                checksum_value VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
                PRIMARY KEY (object_id, ordinal),
                FOREIGN KEY (object_id) REFERENCES s3_objects(id) ON DELETE CASCADE,
                CONSTRAINT chk_s3_object_parts_ordinal CHECK (ordinal BETWEEN 1 AND 10000),
                CONSTRAINT chk_s3_object_parts_upload_number CHECK (upload_part_number IS NULL OR upload_part_number BETWEEN 1 AND 10000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
            $statements[] = 'CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (migration_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, last_object_id BIGINT UNSIGNED NOT NULL, target_object_id BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $statements[] = 'ALTER TABLE s3_migration_checkpoints ADD COLUMN target_object_id BIGINT UNSIGNED NULL';
        }
        if ($fromVersion < 50) {
            // Keep table creation/backfill first so an interrupted MySQL DDL
            // migration can be resumed before the new evidence columns exist.
            array_push($statements, ...array_slice(self::writeIntentCapacityStatements(), 0, 3));
            $statements[] = 'ALTER TABLE s3_object_write_intents ADD COLUMN expected_size BIGINT UNSIGNED NULL';
            $statements[] = 'ALTER TABLE s3_object_write_intents ADD COLUMN expected_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL';
            $statements[] = 'ALTER TABLE s3_object_write_intents ADD COLUMN seal_reserved TINYINT(1) NOT NULL DEFAULT 0';
            $statements[] = 'ALTER TABLE s3_object_write_intents ADD INDEX idx_s3_object_write_intents_owner_seal (owner_id, seal_reserved)';
            $statements[] = 'ALTER TABLE s3_write_intent_capacity DROP CHECK chk_s3_write_intent_capacity';
            $statements[] = "ALTER TABLE s3_write_intent_capacity ADD CONSTRAINT chk_s3_write_intent_capacity CHECK (kind IN ('object', 'object_seal', 'part') AND retained_count BETWEEN 0 AND 1000000)";
            array_push($statements, ...self::writeIntentCapacityStatements());
        }

        if ($fromVersion < 51) {
            $statements[] = 'ALTER TABLE s3_objects ADD COLUMN checksum_crc64nvme VARCHAR(64) NULL';
        }

        return $statements;
    }

    /** @return list<string> */
    private static function completionCapacityStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_completion_capacity (
                id TINYINT NOT NULL PRIMARY KEY,
                retained_count BIGINT NOT NULL,
                CONSTRAINT chk_s3_multipart_completion_capacity CHECK (id = 1 AND retained_count BETWEEN 0 AND 1000000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            'INSERT INTO s3_multipart_completion_capacity (id, retained_count) SELECT 1, (SELECT COUNT(*) FROM s3_multipart_write_intents) WHERE NOT EXISTS (SELECT 1 FROM s3_multipart_completion_capacity WHERE id = 1)',
            'DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_insert',
            <<<'SQL'
            CREATE TRIGGER s3_multipart_completion_capacity_insert BEFORE INSERT ON s3_multipart_write_intents
            FOR EACH ROW BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count + 1
                WHERE id = 1 AND retained_count < 1000000;
                IF ROW_COUNT() <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart completion recovery capacity is exhausted.';
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_delete',
            <<<'SQL'
            CREATE TRIGGER s3_multipart_completion_capacity_delete BEFORE DELETE ON s3_multipart_write_intents
            FOR EACH ROW BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count - 1
                WHERE id = 1 AND retained_count > 0;
                IF ROW_COUNT() <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart completion recovery capacity is inconsistent.';
                END IF;
            END
            SQL,
        ];
    }

    /** @return list<string> */
    private static function writeIntentCapacityStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_write_intent_capacity (
                kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
                retained_count BIGINT NOT NULL,
                CONSTRAINT chk_s3_write_intent_capacity CHECK (kind IN ('object', 'object_seal', 'part') AND retained_count BETWEEN 0 AND 1000000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            "INSERT INTO s3_write_intent_capacity (kind, retained_count)
             SELECT 'object', (SELECT COUNT(*) FROM s3_object_write_intents)
             WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'object')",
            "INSERT INTO s3_write_intent_capacity (kind, retained_count)
             SELECT 'part', (SELECT COUNT(*) FROM s3_storage_garbage WHERE BINARY operation = BINARY 'part_write_intent')
             WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'part')",
            "INSERT INTO s3_write_intent_capacity (kind, retained_count)
             SELECT 'object_seal', (SELECT COUNT(*) FROM s3_object_write_intents WHERE seal_reserved = 1)
             WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'object_seal')",
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_object_insert',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_object_insert BEFORE INSERT ON s3_object_write_intents
            FOR EACH ROW BEGIN
                IF NEW.seal_reserved = 1 AND BINARY NEW.state <> BINARY 'active' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object seal state transition is invalid.';
                END IF;
                UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                WHERE kind = 'object' AND retained_count < 1000000;
                IF ROW_COUNT() <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object write recovery capacity is exhausted or unavailable.';
                END IF;
                IF NEW.seal_reserved = 1 THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                    WHERE kind = 'object_seal' AND retained_count < 1000000;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object seal capacity is exhausted or unavailable.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_object_delete',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_object_delete BEFORE DELETE ON s3_object_write_intents
            FOR EACH ROW BEGIN
                IF OLD.seal_reserved = 0 OR BINARY OLD.state <> BINARY 'sealed' THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                    WHERE kind = 'object' AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object write recovery capacity is inconsistent.';
                    END IF;
                END IF;
                IF OLD.seal_reserved = 1 THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                    WHERE kind = 'object_seal' AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object seal capacity is inconsistent.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_object_update',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_object_update BEFORE UPDATE ON s3_object_write_intents
            FOR EACH ROW BEGIN
                IF OLD.seal_reserved <> NEW.seal_reserved
                   OR (OLD.expected_size IS NOT NULL AND NOT (OLD.expected_size <=> NEW.expected_size))
                   OR (OLD.expected_sha256 IS NOT NULL AND NOT (BINARY OLD.expected_sha256 <=> BINARY NEW.expected_sha256)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object write evidence is immutable.';
                END IF;
                IF OLD.seal_reserved = 1 AND NOT (
                    BINARY OLD.state = BINARY NEW.state
                    OR (BINARY OLD.state = BINARY 'active' AND BINARY NEW.state = BINARY 'reconciling')
                    OR (BINARY OLD.state = BINARY 'reconciling' AND BINARY NEW.state = BINARY 'sealed')
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object seal state transition is invalid.';
                END IF;
                IF OLD.seal_reserved = 1 AND BINARY OLD.state = BINARY 'reconciling' AND BINARY NEW.state = BINARY 'sealed' THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                    WHERE kind = 'object' AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Object write recovery capacity is inconsistent.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_insert',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_part_insert BEFORE INSERT ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF BINARY NEW.operation = BINARY 'part_write_intent' THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                    WHERE kind = 'part' AND retained_count < 1000000;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart part recovery capacity is exhausted or unavailable.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_delete',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_part_delete BEFORE DELETE ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF BINARY OLD.operation = BINARY 'part_write_intent' THEN
                    UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                    WHERE kind = 'part' AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart part recovery capacity is inconsistent.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_write_intent_capacity_part_update',
            <<<'SQL'
            CREATE TRIGGER s3_write_intent_capacity_part_update BEFORE UPDATE ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF BINARY OLD.operation <> BINARY NEW.operation THEN
                    IF BINARY NEW.operation = BINARY 'part_write_intent' THEN
                        UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                        WHERE kind = 'part' AND retained_count < 1000000;
                        IF ROW_COUNT() <> 1 THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart part recovery capacity is exhausted or unavailable.';
                        END IF;
                    ELSEIF BINARY OLD.operation = BINARY 'part_write_intent' THEN
                        UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                        WHERE kind = 'part' AND retained_count > 0;
                        IF ROW_COUNT() <> 1 THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Multipart part recovery capacity is inconsistent.';
                        END IF;
                    END IF;
                END IF;
            END
            SQL,
        ];
    }

    /** @return list<string> */
    private static function storageCleanupCapacityStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_cleanup_capacity (
                id TINYINT NOT NULL PRIMARY KEY,
                retained_count BIGINT NOT NULL,
                CONSTRAINT chk_s3_storage_cleanup_capacity CHECK (id = 1 AND retained_count >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            "INSERT INTO s3_storage_cleanup_capacity (id, retained_count)
             SELECT 1, (SELECT COUNT(*) FROM s3_storage_garbage WHERE BINARY operation <> BINARY 'part_write_intent')
             WHERE NOT EXISTS (SELECT 1 FROM s3_storage_cleanup_capacity WHERE id = 1)",
            'DROP TRIGGER IF EXISTS s3_storage_cleanup_capacity_insert',
            <<<'SQL'
            CREATE TRIGGER s3_storage_cleanup_capacity_insert BEFORE INSERT ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF BINARY NEW.operation <> BINARY 'part_write_intent' THEN
                    UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count + 1
                    WHERE id = 1 AND retained_count < IF(NEW.recovery_admission = 1, 1000000, 900000);
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Storage cleanup capacity is exhausted.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_storage_cleanup_capacity_delete',
            <<<'SQL'
            CREATE TRIGGER s3_storage_cleanup_capacity_delete BEFORE DELETE ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF BINARY OLD.operation <> BINARY 'part_write_intent' THEN
                    UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count - 1
                    WHERE id = 1 AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Storage cleanup capacity is inconsistent.';
                    END IF;
                END IF;
            END
            SQL,
            'DROP TRIGGER IF EXISTS s3_storage_cleanup_capacity_update',
            <<<'SQL'
            CREATE TRIGGER s3_storage_cleanup_capacity_update BEFORE UPDATE ON s3_storage_garbage
            FOR EACH ROW BEGIN
                IF OLD.recovery_admission <> NEW.recovery_admission THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Storage cleanup recovery admission is immutable.';
                END IF;
                IF BINARY OLD.operation = BINARY 'part_write_intent'
                   AND BINARY NEW.operation <> BINARY 'part_write_intent' THEN
                    UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count + 1
                    WHERE id = 1 AND retained_count < IF(NEW.recovery_admission = 1, 1000000, 900000);
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Storage cleanup capacity is exhausted.';
                    END IF;
                ELSEIF BINARY OLD.operation <> BINARY 'part_write_intent'
                   AND BINARY NEW.operation = BINARY 'part_write_intent' THEN
                    UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count - 1
                    WHERE id = 1 AND retained_count > 0;
                    IF ROW_COUNT() <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Storage cleanup capacity is inconsistent.';
                    END IF;
                END IF;
            END
            SQL,
        ];
    }

    /** @return list<string> */
    private static function recoveryCapacityTriggerInventoryFunctionStatements(bool $replace): array
    {
        $create = <<<'SQL'
            CREATE FUNCTION %ss3_recovery_capacity_trigger_count() RETURNS INTEGER
            NOT DETERMINISTIC READS SQL DATA SQL SECURITY DEFINER
            RETURN (
                SELECT COUNT(*)
                FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = DATABASE()
                  AND (
                      (TRIGGER_NAME = 's3_write_intent_capacity_object_insert'
                       AND EVENT_OBJECT_TABLE = 's3_object_write_intents'
                       AND EVENT_MANIPULATION = 'INSERT' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_write_intent_capacity_object_delete'
                          AND EVENT_OBJECT_TABLE = 's3_object_write_intents'
                          AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_write_intent_capacity_object_update'
                          AND EVENT_OBJECT_TABLE = 's3_object_write_intents'
                          AND EVENT_MANIPULATION = 'UPDATE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_write_intent_capacity_part_insert'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'INSERT' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_write_intent_capacity_part_delete'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_write_intent_capacity_part_update'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'UPDATE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_multipart_completion_capacity_insert'
                          AND EVENT_OBJECT_TABLE = 's3_multipart_write_intents'
                          AND EVENT_MANIPULATION = 'INSERT' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_multipart_completion_capacity_delete'
                          AND EVENT_OBJECT_TABLE = 's3_multipart_write_intents'
                          AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_storage_cleanup_capacity_insert'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'INSERT' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_storage_cleanup_capacity_delete'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'BEFORE')
                      OR (TRIGGER_NAME = 's3_storage_cleanup_capacity_update'
                          AND EVENT_OBJECT_TABLE = 's3_storage_garbage'
                          AND EVENT_MANIPULATION = 'UPDATE' AND ACTION_TIMING = 'BEFORE')
                  )
            )
            SQL;
        $create = sprintf($create, $replace ? '' : 'IF NOT EXISTS ');

        return $replace
            ? ['DROP FUNCTION IF EXISTS s3_recovery_capacity_trigger_count', $create]
            : [$create];
    }
}
