<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

use OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration;

/**
 * Tracks and applies schema migrations for the S3 metadata store.
 *
 * Uses the `s3_schema_version` table to track which schema version
 * has been applied.
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
        } catch (\PDOException $error) {
            if (! str_contains($error->getMessage(), 'no such table: s3_schema_version')) {
                throw $error;
            }

            // Only a missing version table denotes a fresh database.
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

        if ($currentVersion > SqliteSchema::VERSION) {
            throw new \RuntimeException("SQLite metadata schema version {$currentVersion} is newer than the supported version " . SqliteSchema::VERSION . '.');
        }

        if ($currentVersion > 0) {
            $this->assertSupportedBucketEncryptionInventory();
        }

        if ($currentVersion >= $targetVersion) {
            return;
        }

        // Apply PRAGMAs first (these cannot be inside a transaction).
        $this->applyPragmas();

        // Apply schema DDL — each version in an exclusive transaction for concurrent safety.
        for ($v = $currentVersion + 1; $v <= $targetVersion; $v++) {
            $method = "applyVersion{$v}";
            if (method_exists($this, $method)) {
                if ($v === 41) {
                    $this->{$method}();
                    continue;
                }
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
        $this->assertSupportedBucketEncryptionInventory();
    }

    public function assertSupportedBucketEncryptionInventory(): void
    {
        $statement = $this->pdo->query(StoredBucketEncryptionConfiguration::invalidRowQuery('sqlite'));
        if ($statement === false) {
            throw new \RuntimeException('Stored bucket encryption inventory cannot be verified.');
        }
        if ($statement->fetch(\PDO::FETCH_NUM) !== false) {
            throw new \RuntimeException('Stored bucket encryption configuration is unsupported; repair metadata before serving.');
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

    /**
     * Apply schema version 12 - make object tags version-aware.
     */
    private function applyVersion12(): void
    {
        $this->pdo->exec(<<<'SQL'
            ALTER TABLE s3_tagging RENAME TO s3_tagging_v11;

            CREATE TABLE s3_tagging (
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

            INSERT INTO s3_tagging (
                id, resource_type, bucket, key_name, version_id, tag_key, tag_value, created_at
            )
            SELECT id, resource_type, bucket, key_name, 'null', tag_key, tag_value, created_at
            FROM s3_tagging_v11;

            DROP TABLE s3_tagging_v11;
            CREATE INDEX idx_s3_tagging_resource
                ON s3_tagging(resource_type, bucket, key_name, version_id);
        SQL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([12, 'Make object tags version-aware']);
    }

    /**
     * Apply schema version 13 - multipart staging quotas.
     */
    private function applyVersion13(): void
    {
        foreach ([
            'max_multipart_uploads_per_bucket',
            'max_multipart_uploads_per_owner',
            'max_multipart_bytes_per_bucket',
            'max_multipart_bytes_per_owner',
        ] as $column) {
            $this->pdo->exec(
                "ALTER TABLE s3_account_quotas ADD COLUMN {$column} INTEGER NOT NULL DEFAULT 0",
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([13, 'Add multipart staging quota limits']);
    }

    private function applyVersion14(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_garbage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket TEXT NOT NULL,
                storage_tier TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at REAL NOT NULL,
                last_error TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_status
                ON s3_storage_garbage(status, next_attempt_at);
        SQL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([14, 'Add durable physical storage garbage queue']);
    }

    private function applyVersion15(): void
    {
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_s3_objects_owner ON s3_objects(owner_id, is_delete_marker)',
        );
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_s3_multipart_uploads_owner ON s3_multipart_uploads(owner_id)',
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([15, 'Index object owner for account storage quotas']);
    }

    private function applyVersion16(): void
    {
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_s3_buckets_owner_name ON s3_buckets(owner_id, name)',
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([16, 'Index owner bucket names for bounded ListBuckets pagination']);
    }

    private function applyVersion17(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_bucket_ownership_controls (
                bucket TEXT PRIMARY KEY,
                object_ownership TEXT NOT NULL
            )
        SQL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
        );
        $stmt->execute([17, 'Persist bucket Object Ownership controls']);
    }

    private function applyVersion18(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_s3_objects_transition_target_tier ON s3_objects(transition_target_tier)');

        $stmt = $this->pdo->prepare('INSERT INTO s3_schema_version (version, description) VALUES (?, ?)');
        $stmt->execute([18, 'Index pending object transition target tiers for startup validation']);
    }

    private function applyVersion19(): void
    {
        if (! $this->columnExists('s3_buckets', 'generation')) {
            $this->pdo->exec("ALTER TABLE s3_buckets ADD COLUMN generation TEXT NOT NULL DEFAULT ''");
        }
        $this->pdo->exec("UPDATE s3_buckets SET generation = lower(hex(randomblob(16))) WHERE generation = ''");

        $stmt = $this->pdo->prepare('INSERT INTO s3_schema_version (version, description) VALUES (?, ?)');
        $stmt->execute([19, 'Assign immutable bucket generations for access-log isolation']);
    }

    private function applyVersion20(): void
    {
        if (! $this->columnExists('s3_storage_garbage', 'operation')) {
            $this->pdo->exec("ALTER TABLE s3_storage_garbage ADD COLUMN operation TEXT NOT NULL DEFAULT 'delete_path'");
        }
        if (! $this->columnExists('s3_storage_garbage', 'key_name')) {
            $this->pdo->exec('ALTER TABLE s3_storage_garbage ADD COLUMN key_name TEXT');
        }
        if (! $this->columnExists('s3_storage_garbage', 'upload_id')) {
            $this->pdo->exec('ALTER TABLE s3_storage_garbage ADD COLUMN upload_id TEXT');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (20, 'Queue multipart aborts during bucket deletion')");
    }

    private function applyVersion21(): void
    {
        if (! $this->columnExists('s3_tier_transition_jobs', 'origin')) {
            $this->pdo->exec("ALTER TABLE s3_tier_transition_jobs ADD COLUMN origin TEXT NOT NULL DEFAULT 'legacy'");
        }
        if (! $this->columnExists('s3_tier_transition_jobs', 'lifecycle_rule_id')) {
            $this->pdo->exec('ALTER TABLE s3_tier_transition_jobs ADD COLUMN lifecycle_rule_id TEXT');
        }
        if (! $this->columnExists('s3_tier_transition_jobs', 'expected_is_latest')) {
            $this->pdo->exec('ALTER TABLE s3_tier_transition_jobs ADD COLUMN expected_is_latest INTEGER');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (21, 'Fence lifecycle tier jobs against stale rules and version roles')");
    }

    private function applyVersion22(): void
    {
        if (! $this->columnExists('s3_multipart_uploads', 'bucket_generation')) {
            $this->pdo->exec("ALTER TABLE s3_multipart_uploads ADD COLUMN bucket_generation TEXT NOT NULL DEFAULT ''");
        }
        $this->pdo->exec(<<<'SQL'
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
                lease_until REAL NOT NULL,
                next_attempt_at REAL NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            );
            CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_due
                ON s3_multipart_write_intents(state, next_attempt_at);
            CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_upload
                ON s3_multipart_write_intents(upload_id);
        SQL);
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (22, 'Track multipart completion paths before storage writes')");
    }

    private function applyVersion23(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_tier ON s3_multipart_write_intents(storage_tier)');
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (23, 'Index multipart intent storage tiers for startup inventory')");
    }

    private function applyVersion24(): void
    {
        $this->pdo->exec('ALTER TABLE s3_storage_garbage ADD COLUMN owner_id TEXT');
        $this->pdo->exec(<<<'SQL'
            UPDATE s3_storage_garbage AS garbage
            SET owner_id = (
                SELECT upload.owner_id FROM s3_multipart_uploads AS upload
                WHERE upload.upload_id = garbage.upload_id
                  AND upload.bucket = garbage.bucket
                  AND substr(garbage.storage_path, -65, 32) = upload.bucket_generation
            )
            WHERE garbage.operation = 'part_write_intent' AND garbage.owner_id IS NULL
            SQL);
        $this->pdo->exec('CREATE INDEX idx_s3_storage_gc_operation_owner ON s3_storage_garbage(operation, owner_id)');
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (24, 'Isolate multipart part recovery admission by owner')");
    }

    private function applyVersion25(): void
    {
        foreach (['s3_storage_garbage', 's3_tier_transition_jobs', 's3_restore_jobs', 's3_notification_queue'] as $table) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN lease_generation INTEGER NOT NULL DEFAULT 0");
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (25, 'Fence leased queue claims by generation')");
    }

    private function applyVersion26(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_s3_multipart_intents_owner ON s3_multipart_write_intents(owner_id)');
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (26, 'Bound multipart completion recovery intents by owner')");
    }

    private function applyVersion27(): void
    {
        $this->pdo->exec(<<<'SQL'
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
                lease_until REAL NOT NULL,
                claim_generation INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE(storage_tier, path_digest)
            );
            CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_owner ON s3_object_write_intents(owner_id);
            CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_due ON s3_object_write_intents(state, lease_until);
            CREATE INDEX IF NOT EXISTS idx_s3_object_write_intents_tier ON s3_object_write_intents(storage_tier);
        SQL);
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (27, 'Add durable pathwise object write intents')");
    }

    private function applyVersion28(): void
    {
        // A constant default preserves every existing row as possibly
        // dispatched. New reservations explicitly INSERT NULL.
        $this->pdo->exec("ALTER TABLE s3_object_write_intents ADD COLUMN dispatch_id TEXT DEFAULT '00000000000000000000000000000000'");
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (28, 'Fence local object write dispatches once per attempt')");
    }

    private function applyVersion29(): void
    {
        // Existing uploads cannot be assigned a physical tier safely. A NULL
        // upload must be resolved explicitly before an API node can serve it.
        if (! $this->columnExists('s3_multipart_uploads', 'storage_tier')) {
            $this->pdo->exec('ALTER TABLE s3_multipart_uploads ADD COLUMN storage_tier TEXT');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (29, 'Pin multipart uploads to physical storage tiers')");
    }

    private function applyVersion30(): void
    {
        // Existing restored paths have no provable physical tier. Keep them
        // NULL so API startup can reject the ambiguity until resolved.
        if ($this->tableExists('s3_objects') && ! $this->columnExists('s3_objects', 'restored_storage_tier')) {
            $this->pdo->exec('ALTER TABLE s3_objects ADD COLUMN restored_storage_tier TEXT');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (30, 'Pin restored object copies to physical storage tiers')");
    }

    private function applyVersion31(): void
    {
        if ($this->tableExists('s3_multipart_write_intents') && ! $this->columnExists('s3_multipart_write_intents', 'claim_generation')) {
            $this->pdo->exec('ALTER TABLE s3_multipart_write_intents ADD COLUMN claim_generation INTEGER NOT NULL DEFAULT 0');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (31, 'Fence multipart completion collector claims by generation')");
    }

    private function applyVersion32(): void
    {
        // A pre-upgrade path may already have an in-flight physical write.
        // Only new local reservations explicitly store NULL before dispatch.
        if ($this->tableExists('s3_storage_garbage') && ! $this->columnExists('s3_storage_garbage', 'dispatch_id')) {
            $this->pdo->exec("ALTER TABLE s3_storage_garbage ADD COLUMN dispatch_id TEXT DEFAULT '00000000000000000000000000000000'");
        }
        if ($this->tableExists('s3_multipart_write_intents') && ! $this->columnExists('s3_multipart_write_intents', 'dispatch_id')) {
            $this->pdo->exec("ALTER TABLE s3_multipart_write_intents ADD COLUMN dispatch_id TEXT DEFAULT '00000000000000000000000000000000'");
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (32, 'Fence local multipart part and completion dispatches once')");
    }

    private function applyVersion33(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_multipart_completion_capacity (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                retained_count INTEGER NOT NULL CHECK (retained_count BETWEEN 0 AND 1000000)
            )
            SQL);
        $this->pdo->exec('INSERT INTO s3_multipart_completion_capacity (id, retained_count) SELECT 1, (SELECT COUNT(*) FROM s3_multipart_write_intents) WHERE NOT EXISTS (SELECT 1 FROM s3_multipart_completion_capacity WHERE id = 1)');
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_multipart_completion_capacity_insert BEFORE INSERT ON s3_multipart_write_intents
            BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count + 1
                WHERE id = 1 AND retained_count < 1000000;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart completion recovery capacity is exhausted.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_multipart_completion_capacity_delete BEFORE DELETE ON s3_multipart_write_intents
            BEGIN
                UPDATE s3_multipart_completion_capacity SET retained_count = retained_count - 1
                WHERE id = 1 AND retained_count > 0;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart completion recovery capacity is inconsistent.') END;
            END
            SQL);
        $this->assertMultipartCompletionCapacityConsistent();
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (33, 'Bound retained multipart completion intents globally')");
    }

    public function assertMultipartCompletionCapacityConsistent(): void
    {
        try {
            $capacity = $this->pdo->query('SELECT retained_count, typeof(retained_count) AS count_type, (SELECT COUNT(*) FROM s3_multipart_write_intents) AS actual_count FROM s3_multipart_completion_capacity WHERE id = 1');
            if ($capacity === false) {
                throw new \RuntimeException('Multipart completion recovery capacity counter cannot be read.');
            }
            $row = $capacity->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $error) {
            throw new \RuntimeException('Multipart completion recovery capacity counter cannot be verified.', 0, $error);
        }
        if ($row === false || $row['count_type'] !== 'integer' || (int) $row['retained_count'] !== (int) $row['actual_count']) {
            throw new \RuntimeException('Multipart completion recovery capacity counter is inconsistent; stop writers and repair metadata before serving.');
        }
    }

    private function applyVersion34(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_write_intent_capacity (
                kind TEXT PRIMARY KEY CHECK (kind IN ('object', 'part')),
                retained_count INTEGER NOT NULL CHECK (retained_count BETWEEN 0 AND 1000000)
            )
            SQL);
        $this->pdo->exec("INSERT INTO s3_write_intent_capacity (kind, retained_count)
            SELECT 'object', (SELECT COUNT(*) FROM s3_object_write_intents)
            WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'object')");
        $this->pdo->exec("INSERT INTO s3_write_intent_capacity (kind, retained_count)
            SELECT 'part', (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent')
            WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'part')");
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_object_insert BEFORE INSERT ON s3_object_write_intents
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                WHERE kind = 'object' AND retained_count < 1000000;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Object write recovery capacity is exhausted.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_object_delete BEFORE DELETE ON s3_object_write_intents
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                WHERE kind = 'object' AND retained_count > 0;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Object write recovery capacity is inconsistent.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_part_insert BEFORE INSERT ON s3_storage_garbage
            WHEN NEW.operation = 'part_write_intent'
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                WHERE kind = 'part' AND retained_count < 1000000;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart part recovery capacity is exhausted.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_part_delete BEFORE DELETE ON s3_storage_garbage
            WHEN OLD.operation = 'part_write_intent'
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                WHERE kind = 'part' AND retained_count > 0;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart part recovery capacity is inconsistent.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_part_enter BEFORE UPDATE OF operation ON s3_storage_garbage
            WHEN OLD.operation != 'part_write_intent' AND NEW.operation = 'part_write_intent'
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1
                WHERE kind = 'part' AND retained_count < 1000000;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart part recovery capacity is exhausted.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_write_intent_capacity_part_leave BEFORE UPDATE OF operation ON s3_storage_garbage
            WHEN OLD.operation = 'part_write_intent' AND NEW.operation != 'part_write_intent'
            BEGIN
                UPDATE s3_write_intent_capacity SET retained_count = retained_count - 1
                WHERE kind = 'part' AND retained_count > 0;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Multipart part recovery capacity is inconsistent.') END;
            END
            SQL);
        $this->assertWriteIntentCapacityConsistent();
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (34, 'Bound object and multipart part write intents globally')");
    }

    private function applyVersion35(): void
    {
        // SQLite cannot add a table CHECK in place. This entire replacement
        // and its version row commit in the surrounding migration transaction.
        $this->assertSupportedBucketEncryptionInventory();
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE s3_encryption_configs_v35 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket TEXT NOT NULL UNIQUE,
                sse_algorithm TEXT NOT NULL DEFAULT 'AES256',
                kms_master_key_id TEXT,
                bucket_key_enabled INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                CONSTRAINT chk_s3_encryption_configs_supported CHECK (
                    sse_algorithm COLLATE BINARY = 'AES256'
                    AND kms_master_key_id IS NULL
                    AND bucket_key_enabled = 0
                )
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            INSERT INTO s3_encryption_configs_v35
                (id, bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled, created_at, updated_at)
            SELECT id, bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled, created_at, updated_at
            FROM s3_encryption_configs
            SQL);
        $this->pdo->exec('DROP TABLE s3_encryption_configs');
        $this->pdo->exec('ALTER TABLE s3_encryption_configs_v35 RENAME TO s3_encryption_configs');
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (35, 'Enforce supported bucket encryption configuration')");
    }

    private function applyVersion36(): void
    {
        if ($this->tableExists('s3_objects')) {
            if (! $this->columnExists('s3_objects', 'noncurrent_at')) {
                $this->pdo->exec('ALTER TABLE s3_objects ADD COLUMN noncurrent_at TEXT');
            }
            // A legacy row has no trustworthy demotion timestamp. Starting its
            // NoncurrentDays clock at migration time prevents an immediate,
            // potentially destructive expiration based on the older created_at.
            $this->pdo->exec("UPDATE s3_objects SET noncurrent_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE is_latest = 0 AND noncurrent_at IS NULL");
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_s3_objects_noncurrent_at ON s3_objects(bucket, is_latest, is_delete_marker, noncurrent_at)');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (36, 'Track object noncurrent transition time')");
    }

    private function applyVersion37(): void
    {
        // Keep the normal admission path proportional to unsafe work rather
        // than to every retained exact-path cleanup row. The predicate is
        // deliberately identical to the fail-closed admission query and
        // retains NULL operations if a legacy/drifted schema allowed them.
        $this->pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_s3_storage_gc_recursive_unsafe
                ON s3_storage_garbage(storage_tier, operation)
                WHERE CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1
            SQL);
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (37, 'Index unsafe recursive storage cleanup admission')");
    }

    private function applyVersion38(): void
    {
        if (! $this->columnExists('s3_parts', 'checksum_algorithm')) {
            $this->pdo->exec('ALTER TABLE s3_parts ADD COLUMN checksum_algorithm TEXT');
        }
        if (! $this->columnExists('s3_parts', 'checksum_value')) {
            $this->pdo->exec('ALTER TABLE s3_parts ADD COLUMN checksum_value TEXT');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (38, 'Persist verified multipart part checksums')");
    }

    private function applyVersion39(): void
    {
        if ($this->tableExists('s3_cors_rules')) {
            if (! $this->columnExists('s3_cors_rules', 'rule_id')) {
                $this->pdo->exec('ALTER TABLE s3_cors_rules ADD COLUMN rule_id TEXT');
            }
            // Before this version both an omitted MaxAgeSeconds and an explicit
            // zero were stored as 0 and read as absent. Preserve that established
            // meaning for legacy rows; new writes use -1 for absence.
            $this->pdo->exec('UPDATE s3_cors_rules SET max_age_seconds = -1 WHERE max_age_seconds = 0');
        }
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (39, 'Preserve CORS rule IDs and explicit zero maximum age')");
    }

    private function applyVersion40(): void
    {
        if (! $this->columnExists('s3_storage_garbage', 'recovery_admission')) {
            $this->pdo->exec('ALTER TABLE s3_storage_garbage ADD COLUMN recovery_admission INTEGER NOT NULL DEFAULT 0 CHECK (recovery_admission IN (0, 1))');
        }
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_storage_cleanup_capacity (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                retained_count INTEGER NOT NULL CHECK (retained_count >= 0)
            )
            SQL);
        $this->pdo->exec("INSERT INTO s3_storage_cleanup_capacity (id, retained_count)
            SELECT 1, (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation != 'part_write_intent')
            WHERE NOT EXISTS (SELECT 1 FROM s3_storage_cleanup_capacity WHERE id = 1)");
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_storage_cleanup_capacity_insert BEFORE INSERT ON s3_storage_garbage
            WHEN NEW.operation != 'part_write_intent'
            BEGIN
                UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count + 1
                WHERE id = 1 AND retained_count < CASE WHEN NEW.recovery_admission = 1 THEN 1000000 ELSE 900000 END;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Storage cleanup capacity is exhausted.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_storage_cleanup_capacity_delete BEFORE DELETE ON s3_storage_garbage
            WHEN OLD.operation != 'part_write_intent'
            BEGIN
                UPDATE s3_storage_cleanup_capacity SET retained_count = retained_count - 1
                WHERE id = 1 AND retained_count > 0;
                SELECT CASE WHEN changes() != 1 THEN RAISE(ABORT, 'Storage cleanup capacity is inconsistent.') END;
            END
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS s3_storage_cleanup_capacity_update BEFORE UPDATE OF operation, recovery_admission ON s3_storage_garbage
            BEGIN
                SELECT CASE WHEN OLD.recovery_admission != NEW.recovery_admission
                    THEN RAISE(ABORT, 'Storage cleanup recovery admission is immutable.') END;
                UPDATE s3_storage_cleanup_capacity
                SET retained_count = retained_count + CASE
                    WHEN OLD.operation = 'part_write_intent' AND NEW.operation != 'part_write_intent' THEN 1
                    ELSE -1 END
                WHERE id = 1
                  AND ((OLD.operation = 'part_write_intent' AND NEW.operation != 'part_write_intent'
                        AND retained_count < CASE WHEN NEW.recovery_admission = 1 THEN 1000000 ELSE 900000 END)
                    OR (OLD.operation != 'part_write_intent' AND NEW.operation = 'part_write_intent'
                        AND retained_count > 0));
                SELECT CASE WHEN ((OLD.operation = 'part_write_intent' AND NEW.operation != 'part_write_intent')
                    OR (OLD.operation != 'part_write_intent' AND NEW.operation = 'part_write_intent')) AND changes() != 1
                    THEN RAISE(ABORT, 'Storage cleanup capacity is exhausted or inconsistent.') END;
            END
            SQL);
        $this->assertStorageCleanupCapacityConsistent();
        $this->assertStorageCleanupTriggers();
        $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (40, 'Bound durable storage cleanup admission')");
    }

    private function applyVersion41(): void
    {
        if (! $this->tableExists('s3_objects')) {
            throw new \RuntimeException('SQLite metadata schema is corrupted: s3_objects is missing before object part layout migration.');
        }

        $this->pdo->beginTransaction();
        try {
            foreach ([
                'part_layout_kind' => "TEXT CHECK (part_layout_kind IN ('known_single', 'known_multipart', 'unknown_multipart', 'unknown'))",
                'part_layout_count' => 'INTEGER CHECK (part_layout_count BETWEEN 0 AND 10000)',
                'part_layout_compat_count' => 'INTEGER CHECK (part_layout_compat_count BETWEEN 1 AND 10000)',
                'part_layout_digest' => 'TEXT',
            ] as $column => $definition) {
                if (! $this->columnExists('s3_objects', $column)) {
                    $this->pdo->exec("ALTER TABLE s3_objects ADD COLUMN {$column} {$definition}");
                }
            }
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS s3_object_parts (
                    object_id INTEGER NOT NULL,
                    ordinal INTEGER NOT NULL CHECK (ordinal BETWEEN 1 AND 10000),
                    upload_part_number INTEGER CHECK (upload_part_number BETWEEN 1 AND 10000),
                    plaintext_offset INTEGER NOT NULL CHECK (plaintext_offset >= 0),
                    plaintext_size INTEGER NOT NULL CHECK (plaintext_size >= 0),
                    checksum_algorithm TEXT,
                    checksum_value TEXT,
                    PRIMARY KEY (object_id, ordinal),
                    FOREIGN KEY (object_id) REFERENCES s3_objects(id) ON DELETE CASCADE
                )
                SQL);
            $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (
                    migration_name TEXT PRIMARY KEY,
                    last_object_id INTEGER NOT NULL,
                    target_object_id INTEGER
                )
                SQL);
            if (! $this->columnExists('s3_migration_checkpoints', 'target_object_id')) {
                $this->pdo->exec('ALTER TABLE s3_migration_checkpoints ADD COLUMN target_object_id INTEGER');
            }
            $this->pdo->exec("INSERT OR IGNORE INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) SELECT 'object_part_layouts', 0, COALESCE(MAX(id), 0) FROM s3_objects");
            $this->pdo->exec("UPDATE s3_migration_checkpoints SET target_object_id = (SELECT COALESCE(MAX(id), 0) FROM s3_objects) WHERE migration_name = 'object_part_layouts' AND target_object_id IS NULL");
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }

        while (true) {
            $this->pdo->beginTransaction();
            try {
                $checkpointQuery = $this->pdo->query("SELECT last_object_id, target_object_id FROM s3_migration_checkpoints WHERE migration_name = 'object_part_layouts'");
                if ($checkpointQuery === false) {
                    throw new \RuntimeException('Object part layout migration checkpoint cannot be read.');
                }
                $checkpointRow = $checkpointQuery->fetch(\PDO::FETCH_ASSOC);
                if (! is_array($checkpointRow) || $checkpointRow['target_object_id'] === null) {
                    throw new \RuntimeException('Object part layout migration target cannot be read.');
                }
                $lastId = (int) $checkpointRow['last_object_id'];
                $targetId = (int) $checkpointRow['target_object_id'];
                if ($lastId > $targetId) {
                    throw new \RuntimeException('Object part layout migration checkpoint exceeds its fixed target.');
                }
                $statement = $this->pdo->prepare('SELECT id, etag, size, is_delete_marker FROM s3_objects WHERE id > ? AND id <= ? ORDER BY id LIMIT 10000');
                $statement->execute([$lastId, $targetId]);
                $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows === []) {
                    $beyondTarget = $this->pdo->prepare('SELECT 1 FROM s3_objects WHERE id > ? LIMIT 1');
                    $beyondTarget->execute([$targetId]);
                    if ($beyondTarget->fetchColumn() !== false) {
                        throw new \RuntimeException('Object rows were created beyond the fixed object-layout migration target.');
                    }
                    $this->pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES (41, 'Persist authoritative object part layouts')");
                    $this->pdo->commit();
                    break;
                }

                $update = $this->pdo->prepare('UPDATE s3_objects SET part_layout_kind = ?, part_layout_count = ?, part_layout_compat_count = ?, part_layout_digest = ? WHERE id = ?');
                $insertPart = $this->pdo->prepare('INSERT OR REPLACE INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES (?, 1, NULL, 0, ?, NULL, NULL)');
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    if ((int) $row['is_delete_marker'] === 1) {
                        $update->execute([null, null, null, null, $lastId]);
                        continue;
                    }
                    $layout = \OpsFour\S3Server\Metadata\ObjectPartLayout::legacy((string) $row['etag'], (int) $row['size']);
                    $summary = $layout->summary($lastId);
                    $update->execute([$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $lastId]);
                    if ($summary->kind === \OpsFour\S3Server\Metadata\ObjectPartLayout::KNOWN_SINGLE) {
                        $insertPart->execute([$lastId, (int) $row['size']]);
                    }
                }
                $checkpoint = $this->pdo->prepare("UPDATE s3_migration_checkpoints SET last_object_id = ? WHERE migration_name = 'object_part_layouts'");
                $checkpoint->execute([$lastId]);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                $this->pdo->rollBack();
                throw $error;
            }
        }
    }

    public function assertWriteIntentCapacityConsistent(): void
    {
        foreach ([
            'object' => 'SELECT COUNT(*) FROM s3_object_write_intents',
            'part' => "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'",
        ] as $kind => $countSql) {
            try {
                $statement = $this->pdo->prepare("SELECT retained_count, typeof(retained_count) AS count_type, ({$countSql}) AS actual_count FROM s3_write_intent_capacity WHERE kind = ?");
                $statement->execute([$kind]);
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
            } catch (\PDOException $error) {
                throw new \RuntimeException('Write intent recovery capacity counter cannot be verified.', 0, $error);
            }
            if ($row === false || $row['count_type'] !== 'integer' || (int) $row['retained_count'] !== (int) $row['actual_count']) {
                throw new \RuntimeException('Write intent recovery capacity counter is inconsistent; stop writers and repair metadata before serving.');
            }
        }
    }

    public function assertStorageCleanupCapacityConsistent(): void
    {
        try {
            $capacity = $this->pdo->query("SELECT retained_count, typeof(retained_count) AS count_type,
                (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation != 'part_write_intent') AS actual_count
                FROM s3_storage_cleanup_capacity WHERE id = 1");
            $row = $capacity === false ? false : $capacity->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $error) {
            throw new \RuntimeException('Storage cleanup capacity counter cannot be verified.', 0, $error);
        }
        if ($row === false || $row['count_type'] !== 'integer' || (int) $row['retained_count'] !== (int) $row['actual_count']) {
            throw new \RuntimeException('Storage cleanup capacity counter is inconsistent; stop writers and repair metadata before serving.');
        }
    }

    public function assertStorageCleanupTriggers(): void
    {
        $statement = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 's3_storage_cleanup_capacity_%'");
        $names = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        sort($names);
        if ($names !== [
            's3_storage_cleanup_capacity_delete',
            's3_storage_cleanup_capacity_insert',
            's3_storage_cleanup_capacity_update',
        ]) {
            throw new \RuntimeException('Storage cleanup capacity triggers are missing; repair metadata before serving.');
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
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
            's3_object_write_intents',
            's3_lifecycle_checkpoints',
            's3_tier_transition_jobs',
            's3_restore_jobs',
            's3_locks',
            's3_named_policies',
            's3_account_policies',
            's3_account_quotas',
            's3_notification_queue',
            's3_storage_garbage',
            's3_storage_cleanup_capacity',
            's3_write_intent_capacity',
            's3_multipart_completion_capacity',
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
