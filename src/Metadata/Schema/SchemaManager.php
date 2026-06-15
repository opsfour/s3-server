<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/**
 * Tracks and applies schema migrations for the S3 metadata store.
 *
 * Uses the `s3_schema_version` table to track which schema version
 * has been applied. Currently supports only version 1 (initial schema).
 */
final class SchemaManager
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    /**
     * Get the currently applied schema version.
     *
     * Returns 0 if no schema has been applied yet (fresh database).
     */
    public function getCurrentVersion(): int
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT MAX(version) as version FROM s3_schema_version',
            );

            if ($stmt === false) {
                return 0;
            }

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row !== false && $row['version'] !== null
                ? (int) $row['version']
                : 0;
        } catch (\PDOException) {
            // Table doesn't exist yet — version is 0.
            return 0;
        }
    }

    /**
     * Apply all pending schema migrations up to the target version.
     *
     * @param  int  $targetVersion  The version to migrate to (default: latest).
     */
    public function migrate(int $targetVersion = SqliteSchema::VERSION): void
    {
        $currentVersion = $this->getCurrentVersion();

        if ($currentVersion >= $targetVersion) {
            return;
        }

        // Apply PRAGMAs first (these cannot be inside a transaction).
        $this->applyPragmas();

        // Apply schema DDL — each version in an exclusive transaction for concurrent safety.
        for ($v = $currentVersion + 1; $v <= $targetVersion; $v++) {
            $method = "applyVersion{$v}";
            if (method_exists($this, $method)) {
                $this->pdo->beginTransaction();
                try {
                    $this->{$method}();
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
        }
    }

    /**
     * Apply SQLite PRAGMA settings for optimal performance.
     *
     * PRAGMAs must be executed outside of transactions.
     */
    private function applyPragmas(): void
    {
        foreach (SqliteSchema::getPragmas() as $pragma) {
            $this->pdo->exec($pragma);
        }
    }

    /**
     * Apply schema version 1 — initial table creation.
     */
    private function applyVersion1(): void
    {
        $schema = SqliteSchema::getSchema();

        // Execute the full schema DDL.
        // SQLite supports multiple statements in a single exec() call.
        $this->pdo->exec($schema);

        // Record the version.
        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );

        $stmt->execute([1, 'Initial schema: core tables, placeholders for future phases']);
    }

    /**
     * Apply schema version 2 — lifecycle/noncurrent indexes.
     */
    private function applyVersion2(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_s3_objects_lifecycle
                ON s3_objects(bucket, is_latest, is_delete_marker, updated_at);
            CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent
                ON s3_objects(bucket, is_latest, updated_at);
        SQL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([2, 'Add lifecycle and noncurrent indexes']);
    }

    /**
     * Apply schema version 3 — foreign key constraint on s3_objects.
     */
    private function applyVersion3(): void
    {
        // SQLite doesn't support ALTER TABLE ADD FOREIGN KEY. The FK was defined
        // in the original CREATE TABLE, but as an inline constraint it only takes
        // effect when foreign_keys PRAGMA is ON. Record the version.
        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([3, 'Record FK constraint intent for s3_objects->s3_buckets']);
    }

    /**
     * Apply schema version 4 — rate limit buckets + notification queue.
     */
    private function applyVersion4(): void
    {
        $ddl = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_rate_limit_buckets (
                ip TEXT PRIMARY KEY,
                tokens REAL NOT NULL,
                last_refill_at REAL NOT NULL
            );

            CREATE TABLE IF NOT EXISTS s3_notification_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket TEXT NOT NULL,
                key_name TEXT NOT NULL,
                event_name TEXT NOT NULL,
                destination_url TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 10,
                next_attempt_at REAL NOT NULL,
                last_error TEXT,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            );
            CREATE INDEX IF NOT EXISTS idx_s3_nq_status_next ON s3_notification_queue(status, next_attempt_at);
            CREATE INDEX IF NOT EXISTS idx_s3_nq_created ON s3_notification_queue(created_at);
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([4, 'Add rate limit buckets and notification queue tables']);
    }

    /**
     * Apply schema version 5 — per-account quotas.
     */
    private function applyVersion5(): void
    {
        $ddl = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_account_quotas (
                owner_id TEXT PRIMARY KEY,
                max_buckets_per_owner INTEGER NOT NULL DEFAULT 0,
                max_objects_per_bucket INTEGER NOT NULL DEFAULT 0,
                max_bytes_per_bucket INTEGER NOT NULL DEFAULT 0,
                max_bytes_per_owner INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            );
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([5, 'Add per-account quota table']);
    }

    /**
     * Apply schema version 6 — distributed lease locks.
     */
    private function applyVersion6(): void
    {
        $ddl = <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_locks (
                lock_name TEXT PRIMARY KEY,
                owner_id TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            );

            CREATE INDEX IF NOT EXISTS idx_s3_locks_expires ON s3_locks(expires_at);
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([6, 'Add distributed lease lock table']);
    }

    /**
     * Apply schema version 7 — lifecycle checkpoints.
     */
    private function applyVersion7(): void
    {
        $ddl = <<<'SQL'
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
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([7, 'Add lifecycle checkpoint table']);
    }

    /**
     * Apply schema version 8 — account and named IAM policies.
     */
    private function applyVersion8(): void
    {
        $ddl = <<<'SQL'
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
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([8, 'Add account and named IAM policy tables']);
    }

    /**
     * Apply schema version 9 — physical tiering and restore metadata.
     */
    private function applyVersion9(): void
    {
        $columns = [
            "storage_tier TEXT NOT NULL DEFAULT 'STANDARD'",
            "transition_status TEXT NOT NULL DEFAULT 'available'",
            'transition_target_tier TEXT',
            'transition_error TEXT',
            'restore_status TEXT',
            'restored_storage_path TEXT',
            'restore_expires_at TEXT',
        ];

        foreach ($columns as $definition) {
            $column = strtok($definition, ' ');
            if ($this->columnExists('s3_objects', $column)) {
                continue;
            }

            $this->pdo->exec("ALTER TABLE s3_objects ADD COLUMN {$definition}");
        }

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_tier_state ON s3_objects(storage_tier, transition_status, restore_status)',
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([9, 'Add physical tiering and restore metadata columns']);
    }

    /**
     * Apply schema version 10 — durable physical tier transition jobs.
     */
    private function applyVersion10(): void
    {
        $ddl = <<<'SQL'
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
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([10, 'Add durable physical tier transition job queue']);
    }

    /**
     * Apply schema version 11 — durable restore jobs.
     */
    private function applyVersion11(): void
    {
        $ddl = <<<'SQL'
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
        SQL;

        $this->pdo->exec($ddl);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([11, 'Add durable restore job queue']);
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return false;
        }

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the schema is up to date.
     */
    public function isUpToDate(): bool
    {
        return $this->getCurrentVersion() >= SqliteSchema::VERSION;
    }

    /**
     * Drop all S3 tables (for testing purposes only).
     *
     * WARNING: This will permanently delete all data.
     */
    public function dropAll(): void
    {
        $tables = [
            's3_lifecycle_checkpoints',
            's3_tier_transition_jobs',
            's3_restore_jobs',
            's3_locks',
            's3_named_policies',
            's3_account_policies',
            's3_account_quotas',
            's3_notification_queue',
            's3_rate_limit_buckets',
            's3_bucket_logging',
            's3_public_access_blocks',
            's3_website_configs',
            's3_notification_configs',
            's3_object_legal_holds',
            's3_object_retention',
            's3_lock_configs',
            's3_lifecycle_rules',
            's3_encryption_configs',
            's3_cors_rules',
            's3_policies',
            's3_tagging',
            's3_acls',
            's3_parts',
            's3_multipart_uploads',
            's3_objects',
            's3_buckets',
            's3_schema_version',
        ];

        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}
