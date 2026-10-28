<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlQueryError;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaAdmission;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaGuard;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * MySQL-backed implementation of the S3 metadata store.
 *
 * Uses amphp/mysql for fully async database access. Suitable for
 * multi-node HA deployments.
 */
final class MysqlMetadataStore implements MetadataStore, MetadataWriteReadinessProbe, SharedAuthorizationLockStore, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartWriteIntentStore, FencedMultipartWriteIntentStore, LocalMultipartCompletionIntentStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, LocalMultipartFenceAdmissionStore, RecursiveCleanupAdmissionStore, MultipartPartWriteIntentInventoryStore, MultipartUploadTierStore, PagedMultipartPartsStore, MultipartPartChecksumStore, FencedStorageGarbageStore, StorageGarbageLeaseStore, FencedQueueStore, ObjectWriteIntentStore, RestoredCopyTierStore, MetadataSchemaAdmission, MultipartCompletionCapacityReader, WriteIntentCapacityReader, StorageCleanupCapacityReader, RecoveryStorageGarbageStore, ObjectPartLayoutStore
{
    /** Preserve S3 Last-Modified while changing version-list bookkeeping. */
    private const string VERSION_DEMOTION_UPDATE_SQL = <<<'SQL'
        UPDATE s3_objects
        SET is_latest = 0,
            noncurrent_at = CURRENT_TIMESTAMP,
            updated_at = updated_at
        WHERE bucket = ? AND key_name = ? AND is_latest = 1
        SQL;

    /** Preserve S3 Last-Modified while promoting the prior current version. */
    private const string VERSION_PROMOTION_UPDATE_SQL = <<<'SQL'
        UPDATE s3_objects
        SET is_latest = 1,
            noncurrent_at = NULL,
            updated_at = updated_at
        WHERE bucket = ? AND key_name = ?
          AND id = (
              SELECT id FROM (
                  SELECT id FROM s3_objects
                  WHERE bucket = ? AND key_name = ?
                  ORDER BY created_at DESC, id DESC
                  LIMIT 1
              ) AS t
          )
          AND is_latest = 0
        SQL;

    /** Explicit self-assignment suppresses MySQL's ON UPDATE timestamp for placement-only metadata. */
    private const string OBJECT_PLACEMENT_UPDATE_SQL = <<<'SQL'
        UPDATE s3_objects
        SET storage_class = ?,
            storage_tier = ?,
            storage_path = ?,
            transition_status = ?,
            transition_target_tier = ?,
            transition_error = ?,
            updated_at = updated_at
        WHERE %s
        SQL;

    /** Explicit self-assignment suppresses MySQL's ON UPDATE timestamp for restore-only metadata. */
    private const string RESTORE_STATE_UPDATE_SQL = <<<'SQL'
        UPDATE s3_objects
        SET restore_status = ?,
            restored_storage_path = ?,
            restored_storage_tier = ?,
            restore_expires_at = ?,
            updated_at = updated_at
        WHERE %s
        SQL;

    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, MysqlTransaction> */
    private \WeakMap $fiberTxMap;

    /** @var \WeakMap<object, array<int, true>> */
    private \WeakMap $objectPartLayoutPublicationIds;

    private ?string $creationDsnFingerprint = null;

    /**
     * Constructor-injected pools must configure every physical connection with
     * UtcMysqlConnector so MySQL TIMESTAMP values retain their S3 UTC meaning.
     * Prefer fromDsn(), which applies that connector automatically.
     */
    public function __construct(
        private readonly MysqlConnectionPool $pool,
    ) {
        $this->fiberTxMap = new \WeakMap();
        $this->objectPartLayoutPublicationIds = new \WeakMap();
    }

    public static function fromDsn(string $dsn): self
    {
        $pool = new MysqlConnectionPool(
            MysqlConfig::fromString($dsn),
            connector: new UtcMysqlConnector(),
        );

        $store = new self($pool);
        $store->creationDsnFingerprint = hash('sha256', $dsn);

        return $store;
    }

    /** Constructor-injected pools have no provable configured DSN binding. */
    public function matchesCreationDsn(string $dsn): bool
    {
        return $this->creationDsnFingerprint !== null
            && hash_equals($this->creationDsnFingerprint, hash('sha256', $dsn));
    }

    public function assertMetadataWritable(): void
    {
        $this->conn()->execute('UPDATE s3_buckets SET region = region WHERE 0');
        MetadataSchemaGuard::assertMysqlWriteIntentTriggers($this->pool);
    }

    public function assertCurrentSchema(): void
    {
        try {
            $row = $this->pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            $actual = $row !== null && $row['version'] !== null ? (int) $row['version'] : 0;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Metadata schema cannot be verified. Run metadata:migrate before starting API nodes.', 0, $error);
        }

        MetadataSchemaGuard::assertCurrentVersion('mysql', $actual);
        $this->assertSupportedBucketEncryptionInventory();
        MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
        $this->assertWriteIntentCapacityConsistent();
        MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
        MetadataSchemaGuard::assertMysqlWriteIntentTriggers($this->pool);
        MetadataSchemaGuard::assertMysqlStorageCleanupTriggerBehavior($this->pool);
    }

    public function initialize(): void
    {
        $currentVersion = 0;

        // Check if schema already exists.
        try {
            $result = $this->pool->execute('SELECT MAX(version) as version FROM s3_schema_version');
            $row = $result->fetchRow();
            if ($row !== null && $row['version'] !== null) {
                $currentVersion = (int) $row['version'];
            }
        } catch (SqlQueryError $error) {
            // Amp MySQL embeds the server error code in the query-error
            // message; the Throwable code itself is always zero.
            if (! str_starts_with($error->getMessage(), 'MySQL error (1146):')) {
                throw $error;
            }
            // ER_NO_SUCH_TABLE: fresh database.
        }

        if ($currentVersion > MysqlSchema::VERSION) {
            throw new \RuntimeException("MySQL metadata schema version {$currentVersion} is newer than the supported version " . MysqlSchema::VERSION . '.');
        }
        if ($currentVersion === MysqlSchema::VERSION) {
            $this->assertSupportedBucketEncryptionInventory();
            MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
            $this->assertWriteIntentCapacityConsistent();
            MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
            MetadataSchemaGuard::assertMysqlWriteIntentTriggersForMigration($this->pool);
            MetadataSchemaGuard::assertMysqlWriteIntentTriggers($this->pool);
            MetadataSchemaGuard::assertMysqlStorageCleanupTriggerBehavior($this->pool);
            return;
        }

        if ($currentVersion > 0) {
            $this->assertSupportedBucketEncryptionInventory();
            // Existing tables must be migrated before current-schema indexes
            // are created because those indexes may reference new columns.
            // MySQL DDL auto-commits. Duplicate schema objects are safe on a
            // partially applied retry; every other error must stop versioning.
            foreach (MysqlSchema::getMigrationStatements($currentVersion) as $sql) {
                try {
                    $this->pool->query($sql);
                } catch (\Throwable $e) {
                    if (! self::isIgnorableSchemaMigrationError($sql, $e)) {
                        throw $e;
                    }
                }
            }
        }

        foreach (MysqlSchema::getCreateStatements() as $sql) {
            $this->pool->query($sql);
        }
        if ($currentVersion > 0) {
            $this->backfillLegacyObjectPartLayouts();
        }
        $this->assertSupportedBucketEncryptionInventory();

        MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
        $this->assertWriteIntentCapacityConsistent();
        MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
        MetadataSchemaGuard::assertMysqlWriteIntentTriggersForMigration($this->pool);
        MetadataSchemaGuard::assertMysqlWriteIntentTriggers($this->pool);
        MetadataSchemaGuard::assertMysqlStorageCleanupTriggerBehavior($this->pool);

        $this->pool->execute(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
            [MysqlSchema::VERSION, 'Schema version ' . MysqlSchema::VERSION],
        );
    }

    private function backfillLegacyObjectPartLayouts(): void
    {
        $this->initializeObjectPartLayoutMigrationTarget();
        while (true) {
            $transaction = $this->pool->beginTransaction();
            try {
                $checkpoint = $transaction->execute("SELECT last_object_id, target_object_id FROM s3_migration_checkpoints WHERE migration_name = 'object_part_layouts' FOR UPDATE")->fetchRow();
                if ($checkpoint === null || $checkpoint['target_object_id'] === null) {
                    throw new \RuntimeException('Object part layout migration target cannot be read.');
                }
                $lastId = (int) $checkpoint['last_object_id'];
                $targetId = (int) $checkpoint['target_object_id'];
                if ($lastId > $targetId) {
                    throw new \RuntimeException('Object part layout migration checkpoint exceeds its fixed target.');
                }
                $result = $transaction->execute('SELECT id, etag, size, is_delete_marker FROM s3_objects WHERE id > ? AND id <= ? ORDER BY id LIMIT 10000', [$lastId, $targetId]);
                $rows = [];
                while (($row = $result->fetchRow()) !== null) {
                    $rows[] = $row;
                }
                if ($rows === []) {
                    if ($transaction->execute('SELECT 1 FROM s3_objects WHERE id > ? LIMIT 1', [$targetId])->fetchRow() !== null) {
                        throw new \RuntimeException('Object rows were created beyond the fixed object-layout migration target.');
                    }
                    $transaction->commit();
                    return;
                }
                // Keep the CASE statement below well inside the Amp/MySQL
                // binary-protocol parameter envelope. The contract permits
                // subchunks of at most 500 rows; 100 keeps one update bounded
                // to 900 parameters while avoiding per-row round trips.
                foreach (array_chunk($rows, 100) as $chunk) {
                    $this->writeLegacyObjectPartLayoutBatch($transaction, $chunk);
                }
                $lastRow = $rows[array_key_last($rows)];
                $lastId = (int) $lastRow['id'];
                $transaction->execute("UPDATE s3_migration_checkpoints SET last_object_id = ? WHERE migration_name = 'object_part_layouts'", [$lastId]);
                $transaction->commit();
            } catch (\Throwable $error) {
                $transaction->rollback();
                throw $error;
            }
        }
    }

    /**
     * @param list<array<string, int|float|string|null>> $rows
     */
    private function writeLegacyObjectPartLayoutBatch(MysqlTransaction $transaction, array $rows): void
    {
        $kinds = [];
        $partCounts = [];
        $compatibilityPartCounts = [];
        $digests = [];
        $objectIds = [];
        $knownSingles = [];

        foreach ($rows as $row) {
            $objectId = (int) $row['id'];
            $objectIds[] = $objectId;
            if ((bool) (int) $row['is_delete_marker']) {
                $kind = null;
                $partCount = null;
                $compatibilityPartCount = null;
                $digest = null;
            } else {
                $layout = ObjectPartLayout::legacy((string) $row['etag'], (int) $row['size']);
                $summary = $layout->summary($objectId);
                $kind = $summary->kind;
                $partCount = $summary->partCount;
                $compatibilityPartCount = $summary->compatibilityPartCount;
                $digest = $summary->digest;
                if ($summary->kind === ObjectPartLayout::KNOWN_SINGLE) {
                    $knownSingles[] = [$objectId, (int) $row['size']];
                }
            }

            array_push($kinds, $objectId, $kind);
            array_push($partCounts, $objectId, $partCount);
            array_push($compatibilityPartCounts, $objectId, $compatibilityPartCount);
            array_push($digests, $objectId, $digest);
        }

        $cases = implode(' ', array_fill(0, count($rows), 'WHEN ? THEN ?'));
        $where = implode(', ', array_fill(0, count($rows), '?'));
        $transaction->execute(
            'UPDATE s3_objects SET '
            . "part_layout_kind = CASE id {$cases} END, "
            . "part_layout_count = CASE id {$cases} END, "
            . "part_layout_compat_count = CASE id {$cases} END, "
            . "part_layout_digest = CASE id {$cases} END, "
            . 'updated_at = updated_at '
            . "WHERE id IN ({$where})",
            [...$kinds, ...$partCounts, ...$compatibilityPartCounts, ...$digests, ...$objectIds],
        );

        $transaction->execute(
            'DELETE FROM s3_object_parts WHERE object_id IN (' . implode(', ', array_fill(0, count($objectIds), '?')) . ')',
            $objectIds,
        );

        if ($knownSingles === []) {
            return;
        }

        $childValues = [];
        $childParams = [];
        foreach ($knownSingles as [$objectId, $size]) {
            $childValues[] = '(?, 1, NULL, 0, ?, NULL, NULL)';
            array_push($childParams, $objectId, $size);
        }
        $transaction->execute(
            'INSERT INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES '
            . implode(', ', $childValues),
            $childParams,
        );
    }

    private function initializeObjectPartLayoutMigrationTarget(): void
    {
        $transaction = $this->pool->beginTransaction();
        try {
            $checkpoint = $transaction->execute("SELECT target_object_id FROM s3_migration_checkpoints WHERE migration_name = 'object_part_layouts' FOR UPDATE")->fetchRow();
            if ($checkpoint === null) {
                $maximum = $transaction->execute('SELECT COALESCE(MAX(id), 0) AS target_object_id FROM s3_objects')->fetchRow();
                if ($maximum === null) {
                    throw new \RuntimeException('Object part layout migration target cannot be established.');
                }
                $transaction->execute("INSERT INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) VALUES ('object_part_layouts', 0, ?)", [(int) $maximum['target_object_id']]);
            } elseif ($checkpoint['target_object_id'] === null) {
                $transaction->execute("UPDATE s3_migration_checkpoints SET target_object_id = (SELECT COALESCE(MAX(id), 0) FROM s3_objects) WHERE migration_name = 'object_part_layouts'");
            }
            $transaction->commit();
        } catch (\Throwable $error) {
            $transaction->rollback();
            throw $error;
        }
    }

    private function assertWriteIntentCapacityConsistent(): void
    {
        foreach ([
            'object' => "SELECT COUNT(*) FROM s3_object_write_intents WHERE seal_reserved = 0 OR BINARY state <> BINARY 'sealed'",
            'object_seal' => 'SELECT COUNT(*) FROM s3_object_write_intents WHERE seal_reserved = 1',
            'part' => "SELECT COUNT(*) FROM s3_storage_garbage WHERE BINARY operation = BINARY 'part_write_intent'",
        ] as $kind => $countSql) {
            try {
                $row = $this->pool->execute(
                    "SELECT retained_count, ({$countSql}) AS actual_count FROM s3_write_intent_capacity WHERE kind = ?",
                    [$kind],
                )->fetchRow();
            } catch (\Throwable $error) {
                throw new \RuntimeException('Write intent recovery capacity counter cannot be verified.', 0, $error);
            }
            if ($row === null || (int) $row['retained_count'] !== (int) $row['actual_count']) {
                throw new \RuntimeException('Write intent recovery capacity counter is inconsistent; stop writers and repair metadata before serving.');
            }
        }
    }

    public function assertSupportedBucketEncryptionInventory(): void
    {
        if ($this->pool->query(\OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::invalidRowQuery('mysql'))->fetchRow() !== null) {
            throw new \RuntimeException('Stored bucket encryption configuration is unsupported; repair metadata before serving.');
        }
    }

    // ===============================================================
    // Bucket operations
    // ===============================================================

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES (?, ?, ?, ?)',
                [$bucket, $ownerId, $region, bin2hex(random_bytes(16))],
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'duplicate key')) {
                throw new BucketAlreadyExistsException(
                    'Your previous request to create the named bucket succeeded and you already own it.',
                );
            }
            throw $e;
        }
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $this->transaction(function () use ($ownerId, $bucket): void {
            $result = $this->conn()->execute(
                'SELECT owner_id FROM s3_buckets WHERE name = ? FOR UPDATE',
                [$bucket],
            );
            $row = $result->fetchRow();

            if ($row === null) {
                throw new NoSuchBucketException('The specified bucket does not exist.');
            }
            if ($row['owner_id'] !== $ownerId) {
                throw new AccessDeniedException('Access Denied');
            }
            if ($this->countObjects($bucket) > 0) {
                throw new BucketNotEmptyException('The bucket you tried to delete is not empty.');
            }

            $bucketTables = [
                's3_lifecycle_checkpoints',
                's3_tier_transition_jobs',
                's3_restore_jobs',
                's3_notification_configs',
                's3_lifecycle_rules',
                's3_encryption_configs',
                's3_lock_configs',
                's3_object_retention',
                's3_object_legal_holds',
                's3_website_configs',
                's3_public_access_blocks',
                's3_bucket_ownership_controls',
                's3_bucket_logging',
                's3_cors_rules',
                's3_policies',
                's3_tagging',
            ];

            $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id IN (SELECT upload_id FROM s3_multipart_uploads WHERE bucket = ?)', [$bucket]);
            $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE bucket = ?', [$bucket]);
            foreach ($bucketTables as $table) {
                $this->conn()->execute("DELETE FROM {$table} WHERE bucket = ?", [$bucket]);
            }
            $this->conn()->execute(
                "DELETE FROM s3_acls
                 WHERE (resource_type = 'bucket' AND resource_name = ?)
                    OR (resource_type = 'object' AND resource_name LIKE ? ESCAPE '\\\\')",
                [$bucket, $this->escapeLikePattern($bucket . '/') . '%'],
            );
            $this->conn()->execute('DELETE FROM s3_buckets WHERE name = ? AND owner_id = ?', [$bucket, $ownerId]);
        });
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE name = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToBucketInfo($row) : null;
    }

    public function listBuckets(string $ownerId): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE owner_id = ? ORDER BY name ASC',
            [$ownerId],
        );

        $buckets = [];
        foreach ($result as $row) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function countBuckets(string $ownerId): int
    {
        $row = $this->conn()->execute('SELECT COUNT(*) AS bucket_count FROM s3_buckets WHERE owner_id = ?', [$ownerId])->fetchRow();

        return $row !== null ? (int) $row['bucket_count'] : 0;
    }

    public function listBucketsPage(string $ownerId, ?string $after, ?string $prefix, ?string $region, int $maxBuckets): array
    {
        $pageSize = max(1, min($maxBuckets, 10_000));
        $sql = 'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE owner_id = ?';
        $params = [$ownerId];
        if ($after !== null) {
            $sql .= ' AND name > ?';
            $params[] = $after;
        }
        if ($prefix !== null) {
            $sql .= ' AND name >= ? AND BINARY LEFT(name, CHAR_LENGTH(?)) = BINARY ?';
            $params[] = $prefix;
            $params[] = $prefix;
            $params[] = $prefix;
        }
        if ($region !== null) {
            $sql .= ' AND BINARY region = ?';
            $params[] = $region;
        }
        $sql .= ' ORDER BY name ASC LIMIT ' . ($pageSize + 1);
        $result = $this->conn()->execute($sql, $params);

        $buckets = [];
        foreach ($result as $row) {
            if (count($buckets) === $pageSize) {
                return ['buckets' => $buckets, 'nextMarker' => $buckets[$pageSize - 1]->name];
            }
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return ['buckets' => $buckets, 'nextMarker' => null];
    }

    public function listAllBuckets(): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets ORDER BY name ASC',
        );

        $buckets = [];
        foreach ($result as $row) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function bucketExists(string $bucket): bool
    {
        $result = $this->conn()->execute(
            'SELECT 1 FROM s3_buckets WHERE name = ?',
            [$bucket],
        );

        return $result->fetchRow() !== null;
    }

    public function getBucketOwner(string $bucket): ?string
    {
        $result = $this->conn()->execute(
            'SELECT owner_id FROM s3_buckets WHERE name = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['owner_id'] : null;
    }

    // ===============================================================
    // Object operations
    // ===============================================================

    public function putObjectMetadata(
        string $bucket,
        string $key,
        string $ownerId,
        int $size,
        string $etag,
        string $contentType,
        string $storagePath,
        string $storageClass = 'STANDARD',
        ?string $contentEncoding = null,
        ?string $contentDisposition = null,
        ?string $cacheControl = null,
        array $userMetadata = [],
        ?string $checksumCrc32 = null,
        ?string $checksumCrc32c = null,
        ?string $checksumSha1 = null,
        ?string $checksumSha256 = null,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $userMetadataJson = json_encode($userMetadata, JSON_THROW_ON_ERROR);

        $this->transaction(function () use (
            $bucket,
            $key,
            $ownerId,
            $etag,
            $size,
            $contentType,
            $contentEncoding,
            $contentDisposition,
            $cacheControl,
            $storageClass,
            $storagePath,
            $userMetadataJson,
            $checksumCrc32,
            $checksumCrc32c,
            $checksumSha1,
            $checksumSha256,
            $now,
        ): void {
            // A suspended write replaces the null version but retains any
            // numbered version that was latest before the write.
            $this->conn()->execute(self::VERSION_DEMOTION_UPDATE_SQL, [$bucket, $key]);

            $this->clearNullVersionMetadata($bucket, $key);

            $this->conn()->execute(
                <<<'SQL'
            INSERT INTO s3_objects (
                bucket, key_name, version_id, is_latest, is_delete_marker,
                owner_id, etag, size, content_type, content_encoding,
                content_disposition, cache_control, storage_class, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
            ) VALUES (
                ?, ?, 'null', 1, 0,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                is_latest = VALUES(is_latest),
                noncurrent_at = NULL,
                is_delete_marker = VALUES(is_delete_marker),
                owner_id = VALUES(owner_id),
                etag = VALUES(etag),
                size = VALUES(size),
                content_type = VALUES(content_type),
                content_encoding = VALUES(content_encoding),
                content_disposition = VALUES(content_disposition),
                cache_control = VALUES(cache_control),
                storage_class = VALUES(storage_class),
                storage_path = VALUES(storage_path),
                storage_tier = 'STANDARD',
                transition_status = 'available',
                transition_target_tier = NULL,
                transition_error = NULL,
                restore_status = NULL,
                restored_storage_path = NULL,
                restored_storage_tier = NULL,
                restore_expires_at = NULL,
                user_metadata = VALUES(user_metadata),
                checksum_crc32 = VALUES(checksum_crc32),
                checksum_crc32c = VALUES(checksum_crc32c),
                checksum_sha1 = VALUES(checksum_sha1),
                checksum_sha256 = VALUES(checksum_sha256),
                created_at = VALUES(created_at),
                updated_at = VALUES(updated_at)
            SQL,
                [
                    $bucket, $key,
                    $ownerId, $etag, $size, $contentType, $contentEncoding,
                    $contentDisposition, $cacheControl, $storageClass, $storagePath,
                    $userMetadataJson, $checksumCrc32, $checksumCrc32c, $checksumSha1,
                    $checksumSha256, $now, $now,
                ],
            );
            $objectRow = $this->conn()->execute("SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null'", [$bucket, $key])->fetchRow();
            if ($objectRow === null) {
                throw new \RuntimeException('New object incarnation cannot be resolved for its part layout.');
            }
            $this->authorizeObjectPartLayoutPublication((int) $objectRow['id']);
            $this->putObjectPartLayout($bucket, $key, 'null', ObjectPartLayout::knownSingle($size));
        });
    }

    private function clearNullVersionMetadata(string $bucket, string $key, ?MysqlLink $link = null): void
    {
        $link ??= $this->conn();
        $link->execute("DELETE FROM s3_object_parts WHERE object_id IN (SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null')", [$bucket, $key]);
        $link->execute("UPDATE s3_objects SET part_layout_kind = NULL, part_layout_count = NULL, part_layout_compat_count = NULL, part_layout_digest = NULL WHERE bucket = ? AND key_name = ? AND version_id = 'null'", [$bucket, $key]);
        $link->execute(
            "DELETE FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            "DELETE FROM s3_object_legal_holds WHERE bucket = ? AND key_name = ? AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            'DELETE FROM s3_acls WHERE resource_type = ? AND resource_name IN (?, ?)',
            [
                'object',
                $bucket . '/' . $key,
                \OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName($bucket, $key, 'null'),
            ],
        );
        $link->execute("DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null'", [$bucket, $key]);
    }

    public function getObjectMetadata(string $bucket, string $key): ?ObjectInfo
    {
        $result = $this->conn()->execute(
            <<<'SQL'
            SELECT
                id, bucket, key_name, version_id, is_latest, is_delete_marker,
                owner_id, etag, size, content_type, content_encoding,
                content_disposition, cache_control, storage_class, storage_tier,
                transition_status, transition_target_tier, transition_error,
                restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, updated_at
            FROM s3_objects
            WHERE bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0
            SQL,
            [$bucket, $key],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToObjectInfo($row) : null;
    }

    public function getCurrentObjectVersion(string $bucket, string $key): ?ObjectInfo
    {
        $result = $this->conn()->execute(
            <<<'SQL'
            SELECT
                id, bucket, key_name, version_id, is_latest, is_delete_marker,
                owner_id, etag, size, content_type, content_encoding,
                content_disposition, cache_control, storage_class, storage_tier,
                transition_status, transition_target_tier, transition_error,
                restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, updated_at
            FROM s3_objects
            WHERE bucket = ? AND key_name = ? AND is_latest = 1
            SQL,
            [$bucket, $key],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToObjectInfo($row) : null;
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->conn()->execute(
            "DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null'",
            [$bucket, $key],
        );
    }

    public function objectExists(string $bucket, string $key): bool
    {
        $result = $this->conn()->execute(
            'SELECT 1 FROM s3_objects WHERE bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0',
            [$bucket, $key],
        );

        return $result->fetchRow() !== null;
    }

    public function supportsObjectPartLayouts(): bool
    {
        return true;
    }

    public function putObjectPartLayout(string $bucket, string $key, string $versionId, ObjectPartLayout $layout): void
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('Object part layouts must be published inside the object metadata transaction.');
        }
        $row = $this->conn()->execute('SELECT id, size, is_delete_marker FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?', [$bucket, $key, $versionId])->fetchRow();
        if ($row === null || (bool) (int) $row['is_delete_marker'] || (int) $row['size'] !== $layout->objectSize) {
            throw new \RuntimeException('Object part layout does not match a concrete object row.');
        }
        $objectId = (int) $row['id'];
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException('Object part layouts must be published inside the object metadata transaction.');
        }
        $publicationIds = $this->objectPartLayoutPublicationIds[$fiber] ?? [];
        if (! isset($publicationIds[$objectId])) {
            throw new \LogicException('Published object part layouts are immutable.');
        }
        $this->conn()->execute('DELETE FROM s3_object_parts WHERE object_id = ?', [$objectId]);
        $summary = $layout->summary($objectId);
        $this->conn()->execute('UPDATE s3_objects SET part_layout_kind = ?, part_layout_count = ?, part_layout_compat_count = ?, part_layout_digest = ?, updated_at = updated_at WHERE id = ?', [$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $objectId]);
        foreach (array_chunk($layout->parts, 500) as $parts) {
            $values = [];
            $params = [];
            foreach ($parts as $part) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $objectId, $part->ordinal, $part->uploadPartNumber, $part->offset, $part->size, $part->checksumAlgorithm, $part->checksumValue);
            }
            $this->conn()->execute(
                'INSERT INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES '
                . implode(', ', $values),
                $params,
            );
        }
    }

    public function getObjectPartLayout(ObjectInfo $object): ObjectPartLayout
    {
        $expected = $object->partLayout;
        if ($object->isDeleteMarker || $expected === null) {
            throw new \RuntimeException('Object metadata has no authoritative part layout.');
        }
        $row = $this->conn()->execute('SELECT id, size, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?', [$object->bucket, $object->key, $object->versionId ?? 'null'])->fetchRow();
        if ($row === null) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        if ($row['part_layout_count'] === null || $row['part_layout_digest'] === null) {
            throw new \RuntimeException('Stored object part layout summary is incomplete.');
        }
        if (! hash_equals($expected->digest, (string) $row['part_layout_digest'])) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        $parts = [];
        $result = $this->conn()->execute('SELECT ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value FROM s3_object_parts WHERE object_id = ? ORDER BY ordinal', [(int) $row['id']]);
        while (($part = $result->fetchRow()) !== null) {
            $parts[] = new ObjectPart((int) $part['ordinal'], $part['upload_part_number'] === null ? null : (int) $part['upload_part_number'], (int) $part['plaintext_offset'], (int) $part['plaintext_size'], $part['checksum_algorithm'] === null ? null : (string) $part['checksum_algorithm'], $part['checksum_value'] === null ? null : (string) $part['checksum_value']);
        }
        if ((int) $row['part_layout_count'] !== $expected->partCount || (int) $row['part_layout_count'] !== count($parts)) {
            throw new \RuntimeException('Stored object part layout count is inconsistent.');
        }
        $layout = new ObjectPartLayout((string) $row['part_layout_kind'], (int) $row['size'], $parts, $row['part_layout_compat_count'] === null ? null : (int) $row['part_layout_compat_count']);
        if (! hash_equals($expected->digest, $layout->digestForObject((int) $row['id']))) {
            throw new \RuntimeException('Stored object part layout digest is inconsistent.');
        }

        return $layout;
    }

    public function updateObjectPlacement(
        string $bucket,
        string $key,
        ?string $versionId,
        string $storageClass,
        string $storageTier,
        string $storagePath,
        string $transitionStatus = 'available',
        ?string $transitionTargetTier = null,
        ?string $transitionError = null,
    ): void {
        $this->assertObjectTargetExists($bucket, $key, $versionId);

        $where = $versionId === null
            ? 'bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0'
            : 'bucket = ? AND key_name = ? AND version_id = ?';
        $params = [
            $storageClass,
            $storageTier,
            $storagePath,
            $transitionStatus,
            $transitionTargetTier,
            $transitionError,
            $bucket,
            $key,
        ];
        if ($versionId !== null) {
            $params[] = $versionId;
        }

        $this->conn()->execute(sprintf(self::OBJECT_PLACEMENT_UPDATE_SQL, $where), $params);
    }

    public function updateObjectRestoreState(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
    ): void {
        if ($restoredStoragePath !== null) {
            throw new \InvalidArgumentException('Restored copy path requires an explicit physical tier.');
        }
        $this->updateObjectRestoreStateWithTier($bucket, $key, $versionId, $restoreStatus, null, $restoreExpiresAt);
    }

    public function updateObjectRestoreStateWithTier(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
        ?string $restoredStorageTier = null,
    ): void {
        self::validateRestoredCopyTier($restoredStoragePath, $restoreExpiresAt, $restoredStorageTier);
        $this->assertObjectTargetExists($bucket, $key, $versionId);

        $where = $versionId === null
            ? 'bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0'
            : 'bucket = ? AND key_name = ? AND version_id = ?';
        $params = [
            $restoreStatus,
            $restoredStoragePath,
            $restoredStorageTier,
            $restoreExpiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $bucket,
            $key,
        ];
        if ($versionId !== null) {
            $params[] = $versionId;
        }

        $this->conn()->execute(sprintf(self::RESTORE_STATE_UPDATE_SQL, $where), $params);
    }

    private static function validateRestoredCopyTier(?string $path, ?\DateTimeImmutable $expiresAt, ?string $tier): void
    {
        if ($path === null) {
            if ($tier !== null || $expiresAt !== null) {
                throw new \InvalidArgumentException('Restored copy tier and expiry require a path.');
            }

            return;
        }
        if ($path === '' || $tier === null || $tier === '' || strlen($tier) > 64 || $expiresAt === null) {
            throw new \InvalidArgumentException('Restored copy requires a path, physical tier, and expiry.');
        }
    }

    public function getBucketStats(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0',
            [$bucket],
        );
        $row = $result->fetchRow();

        return [
            'objectCount' => $row !== null ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== null ? (int) $row['total_size'] : 0,
        ];
    }

    public function getBucketStorageStats(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = ? AND is_delete_marker = 0',
            [$bucket],
        );
        $row = $result->fetchRow();

        return [
            'objectCount' => $row !== null ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== null ? (int) $row['total_size'] : 0,
        ];
    }

    public function getOwnerStorageStats(string $ownerId): array
    {
        $row = $this->conn()->execute(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(size), 0) AS total_size FROM s3_objects WHERE owner_id = ? AND is_delete_marker = 0',
            [$ownerId],
        )->fetchRow();

        return [
            'objectCount' => $row !== null ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== null ? (int) $row['total_size'] : 0,
        ];
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_account_quotas WHERE owner_id = ?',
            [$ownerId],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return new QuotaConfig(
            maxBucketsPerOwner: (int) $row['max_buckets_per_owner'],
            maxObjectsPerBucket: (int) $row['max_objects_per_bucket'],
            maxBytesPerBucket: (int) $row['max_bytes_per_bucket'],
            maxBytesPerOwner: (int) $row['max_bytes_per_owner'],
            maxMultipartUploadsPerBucket: (int) $row['max_multipart_uploads_per_bucket'],
            maxMultipartUploadsPerOwner: (int) $row['max_multipart_uploads_per_owner'],
            maxMultipartBytesPerBucket: (int) $row['max_multipart_bytes_per_bucket'],
            maxMultipartBytesPerOwner: (int) $row['max_multipart_bytes_per_owner'],
        );
    }

    public function listAccountQuotas(): array
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_account_quotas ORDER BY owner_id ASC',
        );

        $quotas = [];
        while (($row = $result->fetchRow()) !== null) {
            $quotas[(string) $row['owner_id']] = new QuotaConfig(
                maxBucketsPerOwner: (int) $row['max_buckets_per_owner'],
                maxObjectsPerBucket: (int) $row['max_objects_per_bucket'],
                maxBytesPerBucket: (int) $row['max_bytes_per_bucket'],
                maxBytesPerOwner: (int) $row['max_bytes_per_owner'],
                maxMultipartUploadsPerBucket: (int) $row['max_multipart_uploads_per_bucket'],
                maxMultipartUploadsPerOwner: (int) $row['max_multipart_uploads_per_owner'],
                maxMultipartBytesPerBucket: (int) $row['max_multipart_bytes_per_bucket'],
                maxMultipartBytesPerOwner: (int) $row['max_multipart_bytes_per_owner'],
            );
        }

        return $quotas;
    }

    public function listAccountQuotasPage(?string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Quota page limit must be positive.');
        }

        $result = $after === null
            ? $this->conn()->execute('SELECT * FROM s3_account_quotas ORDER BY owner_id ASC LIMIT ?', [$limit])
            : $this->conn()->execute('SELECT * FROM s3_account_quotas WHERE owner_id > ? ORDER BY owner_id ASC LIMIT ?', [$after, $limit]);
        $page = [];
        while (($row = $result->fetchRow()) !== null) {
            $ownerId = (string) $row['owner_id'];
            $page[] = ['ownerId' => $ownerId, 'quota' => new QuotaConfig(
                maxBucketsPerOwner: (int) $row['max_buckets_per_owner'],
                maxObjectsPerBucket: (int) $row['max_objects_per_bucket'],
                maxBytesPerBucket: (int) $row['max_bytes_per_bucket'],
                maxBytesPerOwner: (int) $row['max_bytes_per_owner'],
                maxMultipartUploadsPerBucket: (int) $row['max_multipart_uploads_per_bucket'],
                maxMultipartUploadsPerOwner: (int) $row['max_multipart_uploads_per_owner'],
                maxMultipartBytesPerBucket: (int) $row['max_multipart_bytes_per_bucket'],
                maxMultipartBytesPerOwner: (int) $row['max_multipart_bytes_per_owner'],
            )];
        }

        return $page;
    }

    public function putAccountQuota(string $ownerId, QuotaConfig $quota): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_account_quotas (owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner, max_multipart_uploads_per_bucket, max_multipart_uploads_per_owner, max_multipart_bytes_per_bucket, max_multipart_bytes_per_owner, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                max_buckets_per_owner = VALUES(max_buckets_per_owner),
                max_objects_per_bucket = VALUES(max_objects_per_bucket),
                max_bytes_per_bucket = VALUES(max_bytes_per_bucket),
                max_bytes_per_owner = VALUES(max_bytes_per_owner),
                max_multipart_uploads_per_bucket = VALUES(max_multipart_uploads_per_bucket),
                max_multipart_uploads_per_owner = VALUES(max_multipart_uploads_per_owner),
                max_multipart_bytes_per_bucket = VALUES(max_multipart_bytes_per_bucket),
                max_multipart_bytes_per_owner = VALUES(max_multipart_bytes_per_owner),
                updated_at = VALUES(updated_at)
            SQL,
            [
                $ownerId,
                $quota->maxBucketsPerOwner,
                $quota->maxObjectsPerBucket,
                $quota->maxBytesPerBucket,
                $quota->maxBytesPerOwner,
                $quota->maxMultipartUploadsPerBucket,
                $quota->maxMultipartUploadsPerOwner,
                $quota->maxMultipartBytesPerBucket,
                $quota->maxMultipartBytesPerOwner,
            ],
        );
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->conn()->execute('DELETE FROM s3_account_quotas WHERE owner_id = ?', [$ownerId]);
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json FROM s3_account_policies WHERE owner_id = ?', [$ownerId]);
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['policy_json'] : null;
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        \OpsFour\S3Server\Policy\PolicyDocumentValidator::validateIdentityPolicy($policyJson);
        $this->transaction(function () use ($ownerId, $policyJson): void {
            $this->lockOwnerForUpdate($ownerId);
            $this->conn()->execute(
                <<<'SQL'
                INSERT INTO s3_account_policies (owner_id, policy_json) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE policy_json = VALUES(policy_json), updated_at = NOW()
                SQL,
                [$ownerId, $policyJson],
            );
        });
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->transaction(function () use ($ownerId): void {
            $this->lockOwnerForUpdate($ownerId);
            $this->conn()->execute('DELETE FROM s3_account_policies WHERE owner_id = ?', [$ownerId]);
        });
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?', [$policyName]);
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['policy_json'] : null;
    }

    public function lockNamedPoliciesForUpdate(array $policyNames): array
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('lockNamedPoliciesForUpdate() requires an active transaction.');
        }
        $policyNames = array_values(array_unique(array_filter($policyNames, static fn(string $name): bool => $name !== '')));
        sort($policyNames, SORT_STRING);
        $policies = [];
        foreach ($policyNames as $name) {
            $row = $this->conn()->execute('SELECT policy_json FROM s3_named_policies WHERE policy_name = ? FOR UPDATE', [$name])->fetchRow();
            if ($row !== null) {
                $policies[$name] = (string) $row['policy_json'];
            }
        }

        return $policies;
    }

    public function lockNamedPoliciesForRead(array $policyNames): array
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('lockNamedPoliciesForRead() requires an active transaction.');
        }
        $policyNames = array_values(array_unique(array_filter($policyNames, static fn(string $name): bool => $name !== '')));
        sort($policyNames, SORT_STRING);
        $policies = [];
        foreach ($policyNames as $name) {
            $row = $this->conn()->execute('SELECT policy_json FROM s3_named_policies WHERE policy_name = ? LOCK IN SHARE MODE', [$name])->fetchRow();
            if ($row !== null) {
                $policies[$name] = (string) $row['policy_json'];
            }
        }

        return $policies;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        \OpsFour\S3Server\Policy\PolicyDocumentValidator::validateIdentityPolicy($policyJson);
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_named_policies (policy_name, policy_json) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE policy_json = VALUES(policy_json), updated_at = NOW()
            SQL,
            [$policyName, $policyJson],
        );
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->conn()->execute('DELETE FROM s3_named_policies WHERE policy_name = ?', [$policyName]);
    }

    public function countObjects(string $bucket): int
    {
        $result = $this->conn()->execute(
            'SELECT 1 FROM s3_objects WHERE bucket = ? LIMIT 1',
            [$bucket],
        );

        return $result->fetchRow() !== null ? 1 : 0;
    }

    // ===============================================================
    // Listing
    // ===============================================================

    public function listObjects(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $startAfter = null,
        ?string $continuationToken = null,
    ): ListObjectsResult {
        $effectiveStartAfter = $continuationToken === null
            ? $startAfter
            : \OpsFour\S3Server\Http\ObjectContinuationToken::decode($continuationToken);

        $maxKeys = max(0, min(1000, $maxKeys));

        if ($maxKeys === 0) {
            return new ListObjectsResult(
                name: $bucket,
                prefix: $prefix ?? '',
                delimiter: $delimiter,
                maxKeys: 0,
                isTruncated: false,
                keyCount: 0,
                objects: [],
                commonPrefixes: [],
                startAfter: $startAfter,
                continuationToken: $continuationToken,
            );
        }

        $sql = 'SELECT bucket, key_name, version_id, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_tier, transition_status, transition_target_tier, transition_error, '
            . 'restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, updated_at '
            . 'FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($delimiter === null || $delimiter === '') {
            if ($effectiveStartAfter !== null && $effectiveStartAfter !== '') {
                $sql .= ' AND key_name > ?';
                $params[] = $effectiveStartAfter;
            }

            $sql .= ' ORDER BY key_name ASC';
            $sql .= ' LIMIT ?';
            $params[] = $maxKeys + 1;

            $result = $this->conn()->execute($sql, $params);
            $rows = [];
            foreach ($result as $row) {
                $rows[] = $row;
            }

            $isTruncated = count($rows) > $maxKeys;
            if ($isTruncated) {
                $rows = array_slice($rows, 0, $maxKeys);
            }

            $objects = array_map($this->rowToObjectInfo(...), $rows);

            $nextContinuationToken = null;
            if ($isTruncated && count($objects) > 0) {
                $lastObject = $objects[count($objects) - 1];
                $nextContinuationToken = base64_encode($lastObject->key);
            }

            return new ListObjectsResult(
                name: $bucket,
                prefix: $prefix ?? '',
                delimiter: $delimiter,
                maxKeys: $maxKeys,
                isTruncated: $isTruncated,
                keyCount: count($objects),
                objects: $objects,
                commonPrefixes: [],
                startAfter: $startAfter,
                continuationToken: $continuationToken,
                nextContinuationToken: $nextContinuationToken,
            );
        }

        return DelimitedObjectListing::page(
            fetchRows: function (?string $cursor, int $limit, ?string $afterPrefix) use ($sql, $params): array {
                $pageSql = $sql;
                $pageParams = $params;
                if ($afterPrefix !== null) {
                    $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                    if ($upperBound === null) {
                        return [];
                    }
                    $pageSql .= ' AND key_name >= ?';
                    $pageParams[] = $upperBound;
                } elseif ($cursor !== null && $cursor !== '') {
                    $pageSql .= ' AND key_name > ?';
                    $pageParams[] = $cursor;
                }
                $pageSql .= ' ORDER BY key_name ASC LIMIT ?';
                $pageParams[] = $limit;

                $rows = [];
                foreach ($this->conn()->execute($pageSql, $pageParams) as $row) {
                    $rows[] = $row;
                }

                return $rows;
            },
            toObjectInfo: $this->rowToObjectInfo(...),
            bucket: $bucket,
            prefix: $prefix ?? '',
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            startAfter: $startAfter,
            continuationToken: $continuationToken,
            effectiveStartAfter: $effectiveStartAfter,
        );
    }

    // ===============================================================
    // Multipart upload operations
    // ===============================================================

    public function createMultipartUpload(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void {
        $this->createMultipartUploadWithTier($uploadId, $bucket, $key, $ownerId, 'STANDARD', $contentType, $userMetadata);
    }

    public function createMultipartUploadWithTier(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        string $storageTier,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void {
        if ($storageTier === '' || strlen($storageTier) > 64) {
            throw new \InvalidArgumentException('Multipart upload storage tier is invalid.');
        }
        $bucketInfo = $this->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }
        $this->conn()->execute(
            'INSERT INTO s3_multipart_uploads (upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$uploadId, $bucket, $bucketInfo->generation, $key, $ownerId, $storageTier, $contentType ?? 'application/octet-stream', json_encode($userMetadata, JSON_THROW_ON_ERROR)],
        );
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        self::validateObjectWriteIntent($intent);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write intent admission requires its own transaction.');
        }

        return $this->transaction(function () use ($intent, $leaseSeconds): ObjectWriteIntent {
            // A current locking read serializes all owners before the first
            // consistent-read snapshot in this transaction.
            $global = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object' FOR UPDATE")->fetchRow();
            if ($global === null || (int) $global['retained_count'] >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Physical write recovery capacity is exhausted.');
            }

            $owner = $this->conn()->execute(
                "SELECT COUNT(*) AS n FROM s3_object_write_intents WHERE owner_id = ? AND (seal_reserved = 0 OR BINARY state <> BINARY 'sealed')",
                [$intent->ownerId],
            )->fetchRow();
            if ((int) ($owner['n'] ?? 0) >= ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Physical write recovery capacity is exhausted.');
            }
            if ($intent->sealReserved) {
                $sealGlobal = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object_seal' FOR UPDATE")->fetchRow();
                if ($sealGlobal === null || (int) $sealGlobal['retained_count'] >= ObjectWriteIntentStore::MAX_SEALS_GLOBAL) {
                    throw new \OpsFour\S3Server\Exception\SlowDownException('Physical write seal capacity is exhausted.');
                }
                $sealOwner = $this->conn()->execute('SELECT COUNT(*) AS n FROM s3_object_write_intents WHERE owner_id = ? AND seal_reserved = 1', [$intent->ownerId])->fetchRow();
                if ((int) ($sealOwner['n'] ?? 0) >= ObjectWriteIntentStore::MAX_SEALS_PER_OWNER) {
                    throw new \OpsFour\S3Server\Exception\SlowDownException('Physical write seal capacity is exhausted.');
                }
            }

            $this->conn()->execute(<<<'SQL'
                INSERT INTO s3_object_write_intents
                    (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                     path_digest, purpose, state, lease_until, dispatch_id, expected_size, expected_sha256, seal_reserved)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?, NULL, NULL, NULL, ?)
                SQL, [
                $intent->attemptId, $intent->bucket, $intent->bucketGeneration, $intent->ownerId,
                $intent->storageTier, $intent->storagePath, hash('sha256', $intent->storagePath),
                $intent->purpose, $leaseSeconds, $intent->sealReserved ? 1 : 0,
            ]);

            return $this->getObjectWriteIntent($intent->attemptId)
                ?? throw new \RuntimeException('Reserved physical write intent disappeared.');
        });
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        $row = $this->conn()->execute(
            'SELECT * FROM s3_object_write_intents WHERE attempt_id = ?',
            [$attemptId],
        )->fetchRow();

        return $row === null ? null : self::objectWriteIntentFromRow($row);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        return $this->transaction(function () use ($attemptId, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $updated = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?
                WHERE attempt_id = ? AND state = 'active' AND lease_until = ?
                  AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                  AND UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ? > lease_until
                SQL, [$leaseSeconds, $attemptId, $expectedLeaseUntil, $leaseSeconds]);

            return $updated->getRowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds, ?int $expectedSize = null, ?string $expectedSha256 = null): ?ObjectWriteIntent
    {
        self::validateObjectWriteDispatchId($dispatchId);
        self::validateObjectWriteLease($leaseSeconds);
        self::validateObjectWriteEvidence($expectedSize, $expectedSha256);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write dispatch requires its own committed transaction.');
        }

        return $this->transaction(function () use ($attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds, $expectedSize, $expectedSha256): ?ObjectWriteIntent {
            $updated = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET dispatch_id = ?, expected_size = ?, expected_sha256 = ?,
                    lease_until = GREATEST(lease_until, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?)
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL
                  AND ((seal_reserved = 0 AND ? IS NULL AND ? IS NULL)
                    OR (seal_reserved = 1 AND ? IS NOT NULL AND ? IS NOT NULL))
                  AND lease_until = ? AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                SQL, [$dispatchId, $expectedSize, $expectedSha256, $leaseSeconds, $attemptId,
                $expectedSize, $expectedSha256, $expectedSize, $expectedSha256, $expectedLeaseUntil]);

            return $updated->getRowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
    }

    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteDispatchId($dispatchId);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write lease refresh requires its own committed transaction.');
        }

        return $this->transaction(function () use ($attemptId, $dispatchId, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $updated = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = GREATEST(lease_until, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?)
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
                  AND lease_until = ? AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                SQL, [$leaseSeconds, $attemptId, $dispatchId, $expectedLeaseUntil]);
            if ($updated->getRowCount() === 1) {
                return $this->getObjectWriteIntent($attemptId);
            }

            $row = $this->conn()->execute(<<<'SQL'
                SELECT * FROM s3_object_write_intents
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
                  AND lease_until = ? AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                SQL, [$attemptId, $dispatchId, $expectedLeaseUntil])->fetchRow();

            return $row === null ? null : self::objectWriteIntentFromRow($row);
        });
    }

    public function expireArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write recovery scheduling requires its own committed transaction.');
        }
        $result = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
            WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
            SQL, [$attemptId, $dispatchId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $deleted = $this->conn()->execute(
            "DELETE FROM s3_object_write_intents WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL",
            [$attemptId],
        );

        return $deleted->getRowCount() === 1;
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $deleted = $this->conn()->execute(
            "DELETE FROM s3_object_write_intents WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?",
            [$attemptId, $dispatchId],
        );

        return $deleted->getRowCount() === 1;
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        $deleted = $this->conn()->execute(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = ? AND state = 'active'
              AND (dispatch_id IS NULL OR dispatch_id = '00000000000000000000000000000000')
              AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
            SQL, [$attemptId]);

        return $deleted->getRowCount() === 1;
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        $deleted = $this->conn()->execute(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
              AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
            SQL, [$attemptId, $dispatchId]);

        return $deleted->getRowCount() === 1;
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        $updated = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
            WHERE attempt_id = ? AND state = 'active'
            SQL, [$attemptId]);

        return $updated->getRowCount() === 1;
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        self::validateObjectWriteLease($leaseSeconds);
        if ($storageTier === '' || strlen($storageTier) > 64) {
            throw new \InvalidArgumentException('Physical write claim storage tier is invalid.');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Physical write claim limit must be between 1 and 1000.');
        }
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write claims require their own transaction.');
        }

        return $this->transaction(function () use ($storageTier, $limit, $leaseSeconds): array {
            $result = $this->conn()->execute(<<<'SQL'
                SELECT attempt_id, claim_generation FROM s3_object_write_intents
                WHERE storage_tier = ? AND state IN ('active', 'reconciling')
                  AND dispatch_id IS NOT NULL
                  AND lease_until <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                ORDER BY lease_until, attempt_id LIMIT ? FOR UPDATE SKIP LOCKED
                SQL, [$storageTier, $limit]);
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $rows[] = $row;
            }

            $claimed = [];
            foreach ($rows as $row) {
                $updated = $this->conn()->execute(<<<'SQL'
                    UPDATE s3_object_write_intents
                    SET state = 'reconciling', claim_generation = claim_generation + 1,
                        lease_until = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?
                    WHERE attempt_id = ? AND storage_tier = ? AND claim_generation = ?
                      AND state IN ('active', 'reconciling') AND dispatch_id IS NOT NULL
                      AND lease_until <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                    SQL, [$leaseSeconds, $row['attempt_id'], $storageTier, $row['claim_generation']]);
                if ($updated->getRowCount() !== 1) {
                    continue;
                }
                $intent = $this->getObjectWriteIntent((string) $row['attempt_id']);
                if ($intent !== null) {
                    $claimed[] = $intent;
                }
            }

            return $claimed;
        });
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        if ($storageTier === '' || strlen($storageTier) > 64 || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Physical write retirement tier or limit is invalid.');
        }
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        return $this->transaction(function () use ($storageTier, $limit): int {
            $result = $this->conn()->execute(<<<'SQL'
                SELECT attempt_id FROM s3_object_write_intents
                WHERE storage_tier = ? AND state = 'active' AND dispatch_id IS NULL
                  AND lease_until <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                ORDER BY lease_until, attempt_id LIMIT ? FOR UPDATE SKIP LOCKED
                SQL, [$storageTier, $limit]);
            $retired = 0;
            while (($row = $result->fetchRow()) !== null) {
                $deleted = $this->conn()->execute(<<<'SQL'
                    DELETE FROM s3_object_write_intents
                    WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL
                      AND lease_until <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                    SQL, [$row['attempt_id']]);
                $retired += $deleted->getRowCount();
            }

            return $retired;
        });
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        return $this->transaction(function () use ($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $updated = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ?
                WHERE attempt_id = ? AND state = 'reconciling' AND claim_generation = ?
                  AND lease_until = ? AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
                  AND UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) + ? > lease_until
                SQL, [$leaseSeconds, $attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds]);

            return $updated->getRowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        $updated = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents SET state = 'sealed'
            WHERE attempt_id = ? AND state = 'reconciling' AND claim_generation = ?
              AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
            SQL, [$attemptId, $claimGeneration]);

        return $updated->getRowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private static function objectWriteIntentFromRow(array $row): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: (string) $row['attempt_id'],
            bucket: (string) $row['bucket'],
            bucketGeneration: (string) $row['bucket_generation'],
            ownerId: (string) $row['owner_id'],
            storageTier: (string) $row['storage_tier'],
            storagePath: (string) $row['storage_path'],
            purpose: (string) $row['purpose'],
            state: (string) $row['state'],
            leaseUntil: (float) $row['lease_until'],
            claimGeneration: (int) $row['claim_generation'],
            dispatchId: $row['dispatch_id'] === null ? null : (string) $row['dispatch_id'],
            expectedSize: $row['expected_size'] === null ? null : (int) $row['expected_size'],
            expectedSha256: $row['expected_sha256'] === null ? null : (string) $row['expected_sha256'],
            sealReserved: (int) $row['seal_reserved'] === 1,
        );
    }

    private static function validateObjectWriteEvidence(?int $expectedSize, ?string $expectedSha256): void
    {
        if (($expectedSize === null) !== ($expectedSha256 === null)
            || ($expectedSize !== null && $expectedSize < 0)
            || ($expectedSha256 !== null && preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) !== 1)) {
            throw new \InvalidArgumentException('Physical write evidence must be a complete size and SHA-256 pair.');
        }
    }

    private static function validateObjectWriteIntent(ObjectWriteIntent $intent): void
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $intent->attemptId)
            || ! preg_match('/^[0-9a-f]{32}$/D', $intent->bucketGeneration)
            || $intent->bucket === '' || strlen($intent->bucket) > 255
            || $intent->ownerId === '' || strlen($intent->ownerId) > 255
            || $intent->storageTier === '' || strlen($intent->storageTier) > 64
            || $intent->storagePath === '' || strlen($intent->storagePath) > 65535
            || str_contains($intent->storagePath, "\0") || str_contains($intent->storagePath, '\\')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|/$~', preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $intent->storagePath) ?? '')
            || $intent->purpose === '' || strlen($intent->purpose) > 32
            || $intent->state !== 'active' || $intent->leaseUntil !== 0.0 || $intent->claimGeneration !== 0
            || $intent->dispatchId !== null || $intent->expectedSize !== null || $intent->expectedSha256 !== null) {
            throw new \InvalidArgumentException('Invalid physical write intent.');
        }
    }

    private static function validateObjectWriteLease(float $leaseSeconds): void
    {
        if (! is_finite($leaseSeconds) || $leaseSeconds <= 0.0 || $leaseSeconds > 86400.0) {
            throw new \InvalidArgumentException('Physical write lease must be between 0 and 86400 seconds.');
        }
    }

    private static function validateObjectWriteDispatchId(string $dispatchId): void
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId)
            || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid physical write dispatch ID.');
        }
    }

    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void
    {
        $this->transaction(function () use ($intent): void {
            $this->lockOwnerForUpdate($intent->ownerId);
            $upload = $this->conn()->execute(
                'SELECT 1 FROM s3_multipart_uploads WHERE upload_id = ? AND BINARY upload_id = BINARY ? AND bucket = ? AND BINARY bucket = BINARY ? AND bucket_generation = ? AND BINARY owner_id = BINARY ? AND BINARY storage_tier = BINARY ? FOR UPDATE',
                [$intent->uploadId, $intent->uploadId, $intent->bucket, $intent->bucket, $intent->bucketGeneration, $intent->ownerId, $intent->storageTier],
            )->fetchRow();
            if ($upload === null) {
                throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
            }
            $count = $this->conn()->execute(
                'SELECT COUNT(*) AS intent_count FROM s3_multipart_write_intents WHERE owner_id = ?',
                [$intent->ownerId],
            )->fetchRow();
            if ((int) ($count['intent_count'] ?? 0) >= MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart completion recovery capacity is exhausted.');
            }
            try {
                $this->conn()->execute(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, active_upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, sse_mode, state, lease_until, next_attempt_at, dispatch_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NULL)
            SQL, [
                    $intent->attemptId, $intent->uploadId, $intent->uploadId, $intent->bucket,
                    $intent->bucketGeneration, $intent->ownerId, $intent->storageTier,
                    $intent->assemblyPath, $intent->finalPath, $intent->partDigest,
                    $intent->sseMode, $intent->leaseUntil, $intent->nextAttemptAt,
                ]);
            } catch (\Throwable $error) {
                if (str_contains($error->getMessage(), 'Multipart completion recovery capacity is exhausted.')) {
                    throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart completion recovery capacity is exhausted.');
                }

                throw $error;
            }
        });
    }

    public function multipartWriteIntentNow(): float
    {
        $row = $this->conn()->query('SELECT UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AS database_now')->fetchRow();
        $value = $row['database_now'] ?? null;
        if (! is_numeric($value)) {
            throw new \RuntimeException('Metadata database clock is unavailable.');
        }

        return (float) $value;
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        $row = $this->conn()->execute('SELECT * FROM s3_multipart_write_intents WHERE attempt_id = ?', [$attemptId])->fetchRow();

        return $row === null ? null : self::multipartWriteIntentFromRow($row);
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents
            SET lease_until = ?, next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'active' AND lease_until = ?
            AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AND ? > lease_until",
            [$newLeaseUntil, $newLeaseUntil, $attemptId, $expectedLeaseUntil, $newLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        $result = $this->conn()->execute("UPDATE s3_multipart_write_intents SET state = 'abandoned',
            active_upload_id = NULL, next_attempt_at = ? WHERE attempt_id = ? AND state = 'active'", [$nextAttemptAt, $attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        $result = $this->conn()->execute("UPDATE s3_multipart_write_intents SET state = 'abandoned',
            active_upload_id = NULL, next_attempt_at = ? WHERE upload_id = ? AND state = 'active'", [$nextAttemptAt, $uploadId]);

        return $result->getRowCount() ?? 0;
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        $result = $this->conn()->execute("DELETE FROM s3_multipart_write_intents
            WHERE attempt_id = ? AND state = 'active'
            AND dispatch_id IS NULL
            AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))", [$attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart completion dispatch ID.');
        }
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET dispatch_id = ?, lease_until = ?, next_attempt_at = ?
             WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL AND lease_until = ?
               AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AND ? > lease_until",
            [$dispatchId, $newLeaseUntil, $newLeaseUntil, $attemptId, $expectedLeaseUntil, $newLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart completion dispatch ID.');
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_multipart_write_intents
             WHERE attempt_id = ? AND state = 'active' AND (dispatch_id IS NULL OR dispatch_id = ?)",
            [$attemptId, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_multipart_write_intents
             WHERE storage_tier = ? AND BINARY storage_tier = BINARY ? AND dispatch_id IS NULL
               AND ((state = 'active' AND lease_until <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)))
                 OR (state = 'abandoned' AND next_attempt_at <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))))
             ORDER BY next_attempt_at, attempt_id LIMIT ?",
            [$storageTier, $storageTier, max(1, $limit)],
        );

        return $result->getRowCount() ?? 0;
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_multipart_write_intents WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
               AND lease_until > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))",
            [$attemptId, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        return $this->transaction(function () use ($limit, $now, $leaseUntil): array {
            $databaseNow = $this->multipartWriteIntentNow();
            $databaseLeaseUntil = $databaseNow + max(0.0, $leaseUntil - $now);
            $result = $this->conn()->execute(
                "SELECT * FROM s3_multipart_write_intents
                WHERE (state = 'active' AND lease_until <= ?)
                   OR (state = 'abandoned' AND next_attempt_at <= ?)
                ORDER BY next_attempt_at, attempt_id LIMIT ? FOR UPDATE SKIP LOCKED",
                [$databaseNow, $databaseNow, $limit],
            );
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $rows[] = $row;
            }
            $claimed = [];
            foreach ($rows as $row) {
                $this->conn()->execute("UPDATE s3_multipart_write_intents
                    SET state = 'abandoned', active_upload_id = NULL, next_attempt_at = ?, claim_generation = claim_generation + 1
                    WHERE attempt_id = ?", [$databaseLeaseUntil, $row['attempt_id']]);
                $row['state'] = 'abandoned';
                $row['next_attempt_at'] = $databaseLeaseUntil;
                $row['claim_generation'] = (int) $row['claim_generation'] + 1;
                $claimed[] = self::multipartWriteIntentFromRow($row);
            }

            return $claimed;
        });
    }

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ?",
            [$nextAttemptAt, $attemptId, $expectedLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = ?
             WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ? AND claim_generation = ?
               AND next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AND ? > next_attempt_at",
            [$newLeaseUntil, $attemptId, $expectedLeaseUntil, $claimGeneration, $newLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = ?
             WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ? AND claim_generation = ?
               AND next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))",
            [$nextAttemptAt, $attemptId, $expectedLeaseUntil, $claimGeneration],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function multipartWriteIntentFromRow(array $row): MultipartWriteIntent
    {
        return new MultipartWriteIntent(
            attemptId: (string) $row['attempt_id'],
            uploadId: (string) $row['upload_id'],
            bucket: (string) $row['bucket'],
            bucketGeneration: (string) $row['bucket_generation'],
            ownerId: (string) $row['owner_id'],
            storageTier: (string) $row['storage_tier'],
            assemblyPath: (string) $row['assembly_path'],
            finalPath: (string) $row['final_path'],
            partDigest: (string) $row['part_digest'],
            sseMode: $row['sse_mode'] === null ? null : (string) $row['sse_mode'],
            leaseUntil: (float) $row['lease_until'],
            nextAttemptAt: (float) $row['next_attempt_at'],
            state: (string) $row['state'],
            claimGeneration: (int) $row['claim_generation'],
            dispatchId: $row['dispatch_id'] === null ? null : (string) $row['dispatch_id'],
        );
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        $result = $this->conn()->execute(
            'SELECT upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE upload_id = ? AND BINARY upload_id = BINARY ?',
            [$uploadId, $uploadId],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        /** @var array<string, string> $userMetadata */
        $userMetadata = is_string($row['user_metadata'])
            ? json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['user_metadata'] ?? []);

        return [
            'upload_id' => (string) $row['upload_id'],
            'bucket' => (string) $row['bucket'],
            'bucket_generation' => (string) $row['bucket_generation'],
            'key_name' => (string) $row['key_name'],
            'owner_id' => (string) $row['owner_id'],
            'storage_tier' => $row['storage_tier'] === null ? null : (string) $row['storage_tier'],
            'content_type' => (string) $row['content_type'],
            'user_metadata' => $userMetadata,
            'created_at' => $this->formatTimestamp($row['created_at']),
        ];
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->deleteParts($uploadId);
        $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = ? AND BINARY upload_id = BINARY ?', [$uploadId, $uploadId]);
    }

    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        return $this->listMultipartUploadsPage($bucket, null, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker);
    }

    public function listMultipartUploadsForBucket(
        BucketInfo $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        return $this->listMultipartUploadsPage($bucket->name, $bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker);
    }

    /** @return array{uploads: list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata?: string, created_at: string}>, isTruncated: bool, nextKeyMarker: ?string, nextUploadIdMarker: ?string, commonPrefixes: list<string>} */
    private function listMultipartUploadsPage(
        string $bucket,
        ?BucketInfo $observedBucket,
        ?string $prefix,
        ?string $delimiter,
        int $maxUploads,
        ?string $keyMarker,
        ?string $uploadIdMarker,
    ): array {
        if ($maxUploads === 0) {
            return ['uploads' => [], 'isTruncated' => false, 'nextKeyMarker' => null, 'nextUploadIdMarker' => null, 'commonPrefixes' => []];
        }

        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE bucket = ?';
        $params = [$bucket];
        if ($observedBucket !== null) {
            $sql .= " AND (bucket_generation = ? OR (bucket_generation = '' AND BINARY owner_id = BINARY ?))";
            $params[] = $observedBucket->generation;
            $params[] = $observedBucket->ownerId;
        }

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        // S3 orders uploads with the same key by initiation time. The upload ID
        // is only a bytewise tie-breaker for equal timestamps.
        if ($keyMarker !== null && $keyMarker !== '') {
            if ($uploadIdMarker !== null && $uploadIdMarker !== '') {
                $markerSql = 'SELECT created_at FROM s3_multipart_uploads '
                    . 'WHERE bucket = ? AND BINARY key_name = BINARY ? AND BINARY upload_id = BINARY ?';
                $markerParams = [$bucket, $keyMarker, $uploadIdMarker];
                if ($observedBucket !== null) {
                    $markerSql .= " AND (bucket_generation = ? OR (bucket_generation = '' AND BINARY owner_id = BINARY ?))";
                    $markerParams[] = $observedBucket->generation;
                    $markerParams[] = $observedBucket->ownerId;
                }
                $markerRow = $this->conn()->execute($markerSql . ' LIMIT 1', $markerParams)->fetchRow();
                if ($markerRow !== null) {
                    $markerCreatedAt = (string) $markerRow['created_at'];
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND (created_at > ? OR (created_at = ? AND BINARY upload_id > BINARY ?))))';
                    array_push($params, $keyMarker, $keyMarker, $markerCreatedAt, $markerCreatedAt, $uploadIdMarker);
                } else {
                    // The marker may refer to an upload that was already
                    // removed. Preserve S3's lexicographic same-key fallback.
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND BINARY upload_id > BINARY ?))';
                    array_push($params, $keyMarker, $keyMarker, $uploadIdMarker);
                }
            } else {
                $sql .= ' AND key_name > ?';
                $params[] = $keyMarker;
            }
        }

        $effectiveMax = max(1, min(1000, $maxUploads));
        if ($delimiter !== null && $delimiter !== '') {
            return DelimitedMultipartListing::page(
                fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use ($sql, $params): array {
                    $query = $sql;
                    $queryParams = $params;
                    if ($afterPrefix !== null) {
                        $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                        if ($upperBound === null) {
                            return [];
                        }
                        $query .= ' AND key_name >= ?';
                        $queryParams[] = $upperBound;
                    } elseif ($cursor !== null) {
                        $query .= ' AND (key_name > ? OR (key_name = ? AND (created_at > ? OR (created_at = ? AND BINARY upload_id > BINARY ?))))';
                        array_push($queryParams, $cursor['key'], $cursor['key'], $cursor['createdAt'], $cursor['createdAt'], $cursor['uploadId']);
                    }
                    $query .= ' ORDER BY key_name ASC, created_at ASC, BINARY upload_id ASC LIMIT ?';
                    $queryParams[] = $limit;
                    $rows = [];
                    foreach ($this->conn()->execute($query, $queryParams) as $row) {
                        $rows[] = [
                            'upload_id' => (string) $row['upload_id'],
                            'bucket' => (string) $row['bucket'],
                            'key_name' => (string) $row['key_name'],
                            'owner_id' => (string) $row['owner_id'],
                            'content_type' => (string) $row['content_type'],
                            'user_metadata' => (string) $row['user_metadata'],
                            'created_at' => $this->formatTimestamp($row['created_at']),
                            '_listing_created_at' => (string) $row['created_at'],
                        ];
                    }

                    return $rows;
                },
                prefix: $prefix ?? '',
                delimiter: $delimiter,
                maxUploads: $effectiveMax,
                keyMarker: $keyMarker,
            );
        }
        $sql .= ' ORDER BY key_name ASC, created_at ASC, BINARY upload_id ASC LIMIT ?';
        $params[] = $effectiveMax + 1; // Fetch one extra to detect truncation.

        $result = $this->conn()->execute($sql, $params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata: string, created_at: string}> $rows */
        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'upload_id' => (string) $row['upload_id'],
                'bucket' => (string) $row['bucket'],
                'key_name' => (string) $row['key_name'],
                'owner_id' => (string) $row['owner_id'],
                'content_type' => (string) $row['content_type'],
                'user_metadata' => (string) $row['user_metadata'],
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

        $isTruncated = count($rows) > $effectiveMax;
        if ($isTruncated) {
            $rows = array_slice($rows, 0, $effectiveMax);
        }

        $uploads = $rows;

        $nextKeyMarker = null;
        $nextUploadIdMarker = null;
        if ($isTruncated && count($rows) > 0) {
            $lastRow = $rows[count($rows) - 1];
            $nextKeyMarker = $lastRow['key_name'];
            $nextUploadIdMarker = $lastRow['upload_id'];
        }

        return [
            'uploads' => $uploads,
            'isTruncated' => $isTruncated,
            'nextKeyMarker' => $nextKeyMarker,
            'nextUploadIdMarker' => $nextUploadIdMarker,
            'commonPrefixes' => [],
        ];
    }

    public function getMultipartStorageStats(?string $ownerId, ?string $bucket = null): array
    {
        $sql = 'SELECT COUNT(DISTINCT u.upload_id) AS upload_count, COALESCE(SUM(p.size), 0) AS bytes_used
                FROM s3_multipart_uploads u
                LEFT JOIN s3_parts p ON p.upload_id = u.upload_id AND BINARY p.upload_id = BINARY u.upload_id
                WHERE 1 = 1';
        $params = [];
        if ($ownerId !== null) {
            $sql .= ' AND u.owner_id = ?';
            $params[] = $ownerId;
        }
        if ($bucket !== null) {
            $sql .= ' AND u.bucket = ?';
            $params[] = $bucket;
        }
        $row = $this->conn()->execute($sql, $params)->fetchRow();

        return [
            'uploadCount' => $row !== null ? (int) $row['upload_count'] : 0,
            'bytesUsed' => $row !== null ? (int) $row['bytes_used'] : 0,
        ];
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->putPartWithChecksum($uploadId, $partNumber, $etag, $size, $storagePath, null, null);
    }

    public function supportsMultipartPartChecksums(): bool
    {
        return true;
    }

    public function putPartWithChecksum(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath, ?string $checksumAlgorithm, ?string $checksumValue): void
    {
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null) {
            throw new NoSuchUploadException('The specified upload does not exist.');
        }

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path, checksum_algorithm, checksum_value)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                etag = VALUES(etag),
                size = VALUES(size),
                storage_path = VALUES(storage_path),
                checksum_algorithm = VALUES(checksum_algorithm),
                checksum_value = VALUES(checksum_value),
                created_at = NOW()
            SQL,
            [$uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue],
        );
    }

    public function getParts(string $uploadId): array
    {
        $result = $this->conn()->execute(
            'SELECT part_number, etag, size, storage_path, checksum_algorithm, checksum_value, created_at FROM s3_parts WHERE upload_id = ? AND BINARY upload_id = BINARY ? ORDER BY part_number ASC',
            [$uploadId, $uploadId],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => (int) $row['part_number'],
                'etag' => (string) $row['etag'],
                'size' => (int) $row['size'],
                'storage_path' => (string) $row['storage_path'],
                'checksum_algorithm' => $row['checksum_algorithm'] === null ? null : (string) $row['checksum_algorithm'],
                'checksum_value' => $row['checksum_value'] === null ? null : (string) $row['checksum_value'],
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

        return $parts;
    }

    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array
    {
        if ($limit < 1 || $limit > 1001) {
            throw new \InvalidArgumentException('Multipart part page limit must be between 1 and 1001.');
        }

        $result = $this->conn()->execute(
            'SELECT part_number, etag, size, storage_path, checksum_algorithm, checksum_value, created_at '
            . 'FROM s3_parts WHERE upload_id = ? AND BINARY upload_id = BINARY ? AND part_number > ? '
            . 'ORDER BY part_number ASC LIMIT ?',
            [$uploadId, $uploadId, $afterPartNumber, $limit],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => (int) $row['part_number'],
                'etag' => (string) $row['etag'],
                'size' => (int) $row['size'],
                'storage_path' => (string) $row['storage_path'],
                'checksum_algorithm' => $row['checksum_algorithm'] === null ? null : (string) $row['checksum_algorithm'],
                'checksum_value' => $row['checksum_value'] === null ? null : (string) $row['checksum_value'],
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

        return $parts;
    }

    public function deleteParts(string $uploadId): void
    {
        $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id = ? AND BINARY upload_id = BINARY ?', [$uploadId, $uploadId]);
    }

    // ===============================================================
    // Versioning
    // ===============================================================

    public function getBucketVersioning(string $bucket): string
    {
        $result = $this->conn()->execute('SELECT versioning FROM s3_buckets WHERE name = ?', [$bucket]);
        $row = $result->fetchRow();

        if ($row === null) {
            throw new NoSuchBucketException('The specified bucket does not exist.');
        }

        return (string) $row['versioning'];
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        if (! in_array($status, ['Enabled', 'Suspended'], true)) {
            throw new \InvalidArgumentException("Versioning status must be 'Enabled' or 'Suspended', got '{$status}'.");
        }

        // Verify bucket exists first (rowCount=0 on no-op UPDATE is indistinguishable from missing bucket).
        $check = $this->conn()->execute('SELECT 1 FROM s3_buckets WHERE name = ?', [$bucket]);
        if ($check->fetchRow() === null) {
            throw new NoSuchBucketException('The specified bucket does not exist.');
        }

        $this->conn()->execute('UPDATE s3_buckets SET versioning = ? WHERE name = ?', [$status, $bucket]);
    }

    // ===============================================================
    // Versioning-aware object operations
    // ===============================================================

    public function putObjectVersioned(
        string $bucket,
        string $key,
        string $ownerId,
        int $size,
        string $etag,
        string $contentType,
        string $storagePath,
        string $storageClass = 'STANDARD',
        ?string $contentEncoding = null,
        ?string $contentDisposition = null,
        ?string $cacheControl = null,
        array $userMetadata = [],
        ?string $checksumCrc32 = null,
        ?string $checksumCrc32c = null,
        ?string $checksumSha1 = null,
        ?string $checksumSha256 = null,
    ): string {
        $versionId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $userMetadataJson = json_encode($userMetadata, JSON_THROW_ON_ERROR);

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute(self::VERSION_DEMOTION_UPDATE_SQL, [$bucket, $key]);

            $link->execute(
                <<<'SQL'
                INSERT INTO s3_objects (
                    bucket, key_name, version_id, is_latest, is_delete_marker,
                    owner_id, etag, size, content_type, content_encoding,
                    content_disposition, cache_control, storage_class, storage_path,
                    user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                    checksum_sha256, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, 1, 0,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?
                )
                SQL,
                [
                    $bucket, $key, $versionId,
                    $ownerId, $etag, $size, $contentType, $contentEncoding,
                    $contentDisposition, $cacheControl, $storageClass, $storagePath,
                    $userMetadataJson, $checksumCrc32, $checksumCrc32c, $checksumSha1,
                    $checksumSha256, $now, $now,
                ],
            );
            $layout = ObjectPartLayout::knownSingle($size);
            $objectRow = $link->execute('SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?', [$bucket, $key, $versionId])->fetchRow();
            if ($objectRow === null) {
                throw new \RuntimeException('New object version cannot be resolved for its part layout.');
            }
            $objectId = (int) $objectRow['id'];
            if ($existingTx !== null) {
                $this->authorizeObjectPartLayoutPublication($objectId);
            }
            $summary = $layout->summary($objectId);
            $link->execute('UPDATE s3_objects SET part_layout_kind = ?, part_layout_count = ?, part_layout_compat_count = ?, part_layout_digest = ?, updated_at = updated_at WHERE id = ?', [$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $objectId]);
            $link->execute('INSERT INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES (?, 1, NULL, 0, ?, NULL, NULL)', [$objectId, $size]);

            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }

        return $versionId;
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        $versionId = $suspended ? 'null' : bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            // Mark all previous versions as non-latest (both enabled and suspended).
            $link->execute(self::VERSION_DEMOTION_UPDATE_SQL, [$bucket, $key]);

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
                $this->clearNullVersionMetadata($bucket, $key, $link);
                $link->execute(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        user_metadata, created_at, updated_at
                    ) VALUES (
                        ?, ?, 'null', 1, 1,
                        ?, '', 0, 'application/octet-stream', 'STANDARD', '',
                        '{}', ?, ?
                    )
                    ON DUPLICATE KEY UPDATE
                        is_latest = 1,
                        noncurrent_at = NULL,
                        is_delete_marker = 1,
                        owner_id = VALUES(owner_id),
                        etag = '',
                        size = 0,
                        content_type = 'application/octet-stream',
                        content_encoding = NULL,
                        content_disposition = NULL,
                        cache_control = NULL,
                        storage_class = 'STANDARD',
                        storage_path = '',
                        storage_tier = 'STANDARD',
                        transition_status = 'available',
                        transition_target_tier = NULL,
                        transition_error = NULL,
                        restore_status = NULL,
                        restored_storage_path = NULL,
                        restored_storage_tier = NULL,
                        restore_expires_at = NULL,
                        user_metadata = '{}',
                        checksum_crc32 = NULL,
                        checksum_crc32c = NULL,
                        checksum_sha1 = NULL,
                        checksum_sha256 = NULL,
                        created_at = VALUES(created_at),
                        updated_at = VALUES(updated_at)
                    SQL,
                    [$bucket, $key, $ownerId, $now, $now],
                );
            } else {
                $link->execute(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        user_metadata, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, 1, 1,
                        ?, '', 0, 'application/octet-stream', 'STANDARD', '',
                        '{}', ?, ?
                    )
                    SQL,
                    [$bucket, $key, $versionId, $ownerId, $now, $now],
                );
            }

            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }

        return $versionId;
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        $result = $this->conn()->execute(
            <<<'SQL'
            SELECT
                id, bucket, key_name, version_id, is_latest, is_delete_marker,
                owner_id, etag, size, content_type, content_encoding,
                content_disposition, cache_control, storage_class, storage_tier,
                transition_status, transition_target_tier, transition_error,
                restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, noncurrent_at, updated_at
            FROM s3_objects
            WHERE bucket = ? AND key_name = ? AND version_id = ?
            SQL,
            [$bucket, $key, $versionId],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToObjectInfo($row) : null;
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        $objectInfo = $this->getObjectMetadataByVersion($bucket, $key, $versionId);

        if ($objectInfo === null) {
            return null;
        }

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute(
                'DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?',
                [$bucket, $key, $versionId],
            );

            if (! empty($objectInfo->systemMetadata['isLatest'])) {
                // Promote the next most recent version to is_latest.
                // MySQL does not allow referencing the same table in a subquery
                // of an UPDATE, so we use a derived table workaround.
                $link->execute(self::VERSION_PROMOTION_UPDATE_SQL, [$bucket, $key, $bucket, $key]);
            }

            $effectiveVersionId = $versionId;
            $link->execute(
                'DELETE FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = ?',
                [$bucket, $key, $effectiveVersionId],
            );
            $link->execute(
                'DELETE FROM s3_object_legal_holds WHERE bucket = ? AND key_name = ? AND version_id = ?',
                [$bucket, $key, $effectiveVersionId],
            );
            $link->execute(
                "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?",
                [$bucket, $key, $effectiveVersionId],
            );
            $link->execute(
                "DELETE FROM s3_acls WHERE resource_type = 'object' AND resource_name = ?",
                [\OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName($bucket, $key, $versionId)],
            );

            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }

        return $objectInfo;
    }

    public function listObjectVersions(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $keyMarker = null,
        ?string $versionIdMarker = null,
    ): array {
        $maxKeys = max(0, min(1000, $maxKeys));

        if ($maxKeys === 0) {
            return [
                'versions' => [],
                'deleteMarkers' => [],
                'entries' => [],
                'isTruncated' => false,
                'nextKeyMarker' => null,
                'nextVersionIdMarker' => null,
                'commonPrefixes' => [],
            ];
        }

        $sql = 'SELECT bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_tier, transition_status, transition_target_tier, transition_error, '
            . 'restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, noncurrent_at, updated_at, id '
            . 'FROM s3_objects WHERE bucket = ?';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Resolve the marker row; version_id is random and does not define listing order.
                $markerResult = $this->conn()->execute(
                    'SELECT id, is_latest, created_at FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ? LIMIT 1',
                    [$bucket, $keyMarker, $versionIdMarker],
                );
                $markerRow = null;
                foreach ($markerResult as $r) {
                    $markerRow = $r;
                    break;
                }
                if ($markerRow !== null) {
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND (is_latest < ? OR (is_latest = ? AND (created_at < ? OR (created_at = ? AND id < ?))))))';
                    $params[] = $keyMarker;
                    $params[] = $keyMarker;
                    $params[] = (int) $markerRow['is_latest'];
                    $params[] = (int) $markerRow['is_latest'];
                    $params[] = $markerRow['created_at'];
                    $params[] = $markerRow['created_at'];
                    $params[] = (int) $markerRow['id'];
                } else {
                    $sql .= ' AND key_name > ?';
                    $params[] = $keyMarker;
                }
            } else {
                $sql .= ' AND key_name > ?';
                $params[] = $keyMarker;
            }
        }

        if ($delimiter === null || $delimiter === '') {
            $sql .= ' ORDER BY key_name ASC, is_latest DESC, created_at DESC, id DESC LIMIT ?';
            $params[] = $maxKeys + 1;

            $result = $this->conn()->execute($sql, $params);
            $rows = [];
            foreach ($result as $row) {
                $rows[] = $row;
            }

            $isTruncated = count($rows) > $maxKeys;
            if ($isTruncated) {
                $rows = array_slice($rows, 0, $maxKeys);
            }

            $versions = [];
            $deleteMarkers = [];
            $entries = [];

            foreach ($rows as $row) {
                $info = $this->rowToObjectInfo($row);
                $entries[] = $info;
                if ((int) $row['is_delete_marker']) {
                    $deleteMarkers[] = $info;
                } else {
                    $versions[] = $info;
                }
            }

            $nextKeyMarker = null;
            $nextVersionIdMarker = null;
            if ($isTruncated && count($rows) > 0) {
                $lastRow = $rows[count($rows) - 1];
                $nextKeyMarker = (string) $lastRow['key_name'];
                $nextVersionIdMarker = (string) $lastRow['version_id'];
            }

            return [
                'versions' => $versions,
                'deleteMarkers' => $deleteMarkers,
                'entries' => $entries,
                'isTruncated' => $isTruncated,
                'nextKeyMarker' => $nextKeyMarker,
                'nextVersionIdMarker' => $nextVersionIdMarker,
                'commonPrefixes' => [],
            ];
        }

        return DelimitedVersionListing::page(
            fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use ($sql, $params): array {
                $query = $sql;
                $queryParams = $params;
                if ($afterPrefix !== null) {
                    $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                    if ($upperBound === null) {
                        return [];
                    }
                    $query .= ' AND key_name >= ?';
                    $queryParams[] = $upperBound;
                } elseif ($cursor !== null) {
                    $query .= ' AND (key_name > ? OR (key_name = ? AND (is_latest < ? OR (is_latest = ? AND (created_at < ? OR (created_at = ? AND id < ?))))))';
                    array_push($queryParams, $cursor['key'], $cursor['key'], $cursor['isLatest'], $cursor['isLatest'], $cursor['createdAt'], $cursor['createdAt'], $cursor['id']);
                }
                $query .= ' ORDER BY key_name ASC, is_latest DESC, created_at DESC, id DESC LIMIT ?';
                $queryParams[] = $limit;
                $rows = [];
                foreach ($this->conn()->execute($query, $queryParams) as $row) {
                    $rows[] = $row;
                }

                return $rows;
            },
            toObjectInfo: $this->rowToObjectInfo(...),
            prefix: $prefix ?? '',
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            keyMarker: $keyMarker,
        );
    }

    // ===============================================================
    // Object Lock, Retention, Legal Hold
    // ===============================================================

    public function getObjectLockConfig(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years FROM s3_lock_configs WHERE bucket = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        $config = [
            'objectLockEnabled' => ((int) $row['object_lock_enabled']) ? 'Enabled' : 'Disabled',
        ];

        if ($row['default_retention_mode'] !== null) {
            $retention = ['mode' => (string) $row['default_retention_mode']];
            if ($row['default_retention_days'] !== null) {
                $retention['days'] = (int) $row['default_retention_days'];
            }
            if ($row['default_retention_years'] !== null) {
                $retention['years'] = (int) $row['default_retention_years'];
            }
            $config['rule'] = ['defaultRetention' => $retention];
        }

        return $config;
    }

    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $objectLockEnabled = (int) ($config['objectLockEnabled'] === 'Enabled');
        $retentionMode = $config['rule']['defaultRetention']['mode'] ?? null;
        $retentionDays = $config['rule']['defaultRetention']['days'] ?? null;
        $retentionYears = $config['rule']['defaultRetention']['years'] ?? null;

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_lock_configs (bucket, object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                object_lock_enabled = VALUES(object_lock_enabled),
                default_retention_mode = VALUES(default_retention_mode),
                default_retention_days = VALUES(default_retention_days),
                default_retention_years = VALUES(default_retention_years),
                updated_at = NOW()
            SQL,
            [$bucket, $objectLockEnabled, $retentionMode, $retentionDays, $retentionYears],
        );

        $this->conn()->execute(
            'UPDATE s3_buckets SET object_lock_enabled = ? WHERE name = ?',
            [$objectLockEnabled, $bucket],
        );
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        $effectiveVersionId = $versionId ?? 'null';
        $result = $this->conn()->execute(
            'SELECT mode, retain_until_date FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$bucket, $key, $effectiveVersionId],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return ['mode' => (string) $row['mode'], 'retainUntilDate' => (string) $row['retain_until_date']];
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_object_retention (bucket, key_name, version_id, mode, retain_until_date)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                mode = VALUES(mode),
                retain_until_date = VALUES(retain_until_date),
                updated_at = NOW()
            SQL,
            [$bucket, $key, $effectiveVersionId, $mode, $retainUntilDate],
        );
    }

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$bucket, $key, $versionId ?? 'null'],
        );
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        $effectiveVersionId = $versionId ?? 'null';
        $result = $this->conn()->execute(
            'SELECT status FROM s3_object_legal_holds WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$bucket, $key, $effectiveVersionId],
        );
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['status'] : null;
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_object_legal_holds (bucket, key_name, version_id, status)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                updated_at = NOW()
            SQL,
            [$bucket, $key, $effectiveVersionId, $status],
        );
    }

    // ===============================================================
    // ACLs
    // ===============================================================

    public function getAcl(string $resourceType, string $resourceName): array
    {
        $result = $this->conn()->execute(
            'SELECT grantee_type, grantee_id, permission FROM s3_acls WHERE resource_type = ? AND resource_name = ? ORDER BY id ASC',
            [$resourceType, $resourceName],
        );

        $grants = [];
        foreach ($result as $row) {
            $grants[] = [
                'granteeType' => (string) $row['grantee_type'],
                'granteeId' => (string) $row['grantee_id'],
                'permission' => (string) $row['permission'],
            ];
        }

        return $grants;
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute(
                'DELETE FROM s3_acls WHERE resource_type = ? AND resource_name = ?',
                [$resourceType, $resourceName],
            );

            foreach ($grants as $grant) {
                $link->execute(
                    'INSERT INTO s3_acls (resource_type, resource_name, grantee_type, grantee_id, permission) VALUES (?, ?, ?, ?, ?)',
                    [$resourceType, $resourceName, $grant['granteeType'], $grant['granteeId'], $grant['permission']],
                );
            }

            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    // ===============================================================
    // Tagging
    // ===============================================================

    public function getBucketTagging(string $bucket): array
    {
        $result = $this->conn()->execute(
            "SELECT tag_key, tag_value FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL ORDER BY id ASC",
            [$bucket],
        );

        $tags = [];
        foreach ($result as $row) {
            $tags[] = ['key' => (string) $row['tag_key'], 'value' => (string) $row['tag_value']];
        }

        return $tags;
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute("DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL", [$bucket]);
            foreach ($tags as $tag) {
                $link->execute(
                    "INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) VALUES ('bucket', ?, NULL, ?, ?)",
                    [$bucket, $tag['key'], $tag['value']],
                );
            }
            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $this->conn()->execute("DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL", [$bucket]);
    }

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        $result = $this->conn()->execute(
            "SELECT tag_key, tag_value FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ? ORDER BY id ASC",
            [$bucket, $key, $versionId ?? 'null'],
        );

        $tags = [];
        foreach ($result as $row) {
            $tags[] = ['key' => (string) $row['tag_key'], 'value' => (string) $row['tag_value']];
        }

        return $tags;
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute("DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?", [$bucket, $key, $versionId ?? 'null']);
            foreach ($tags as $tag) {
                $link->execute(
                    "INSERT INTO s3_tagging (resource_type, bucket, key_name, version_id, tag_key, tag_value) VALUES ('object', ?, ?, ?, ?, ?)",
                    [$bucket, $key, $versionId ?? 'null', $tag['key'], $tag['value']],
                );
            }
            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    public function deleteObjectTagging(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->conn()->execute(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?",
            [$bucket, $key, $versionId ?? 'null'],
        );
    }

    // ===============================================================
    // Bucket Policies
    // ===============================================================

    public function getBucketPolicy(string $bucket): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json FROM s3_policies WHERE bucket = ?', [$bucket]);
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['policy_json'] : null;
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_policies (bucket, policy_json) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE policy_json = VALUES(policy_json), updated_at = NOW()
            SQL,
            [$bucket, $policyJson],
        );
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_policies WHERE bucket = ?', [$bucket]);
    }

    // ===============================================================
    // CORS
    // ===============================================================

    public function getBucketCors(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds FROM s3_cors_rules WHERE bucket = ? ORDER BY rule_order ASC',
            [$bucket],
        );

        $rules = [];
        foreach ($result as $row) {
            /** @var list<string> $allowedOrigins */
            $allowedOrigins = $this->decodeJson($row['allowed_origins']);
            /** @var list<string> $allowedMethods */
            $allowedMethods = $this->decodeJson($row['allowed_methods']);
            /** @var list<string> $allowedHeaders */
            $allowedHeaders = $this->decodeJson($row['allowed_headers']);
            /** @var list<string> $exposeHeaders */
            $exposeHeaders = $this->decodeJson($row['expose_headers']);
            $rule = [
                'allowedOrigins' => $allowedOrigins,
                'allowedMethods' => $allowedMethods,
                'allowedHeaders' => $allowedHeaders,
                'exposeHeaders' => $exposeHeaders,
                'maxAgeSeconds' => ((int) $row['max_age_seconds']) >= 0 ? (int) $row['max_age_seconds'] : null,
            ];
            if ($row['rule_id'] !== null) {
                $rule['id'] = (string) $row['rule_id'];
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute('DELETE FROM s3_cors_rules WHERE bucket = ?', [$bucket]);
            foreach ($rules as $index => $rule) {
                $link->execute(
                    'INSERT INTO s3_cors_rules (bucket, rule_order, rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $bucket, $index, $rule['id'] ?? null,
                        json_encode($rule['allowedOrigins'], JSON_THROW_ON_ERROR),
                        json_encode($rule['allowedMethods'], JSON_THROW_ON_ERROR),
                        json_encode($rule['allowedHeaders'], JSON_THROW_ON_ERROR),
                        json_encode($rule['exposeHeaders'], JSON_THROW_ON_ERROR),
                        $rule['maxAgeSeconds'] ?? -1,
                    ],
                );
            }
            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    public function deleteBucketCors(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_cors_rules WHERE bucket = ?', [$bucket]);
    }

    // ===============================================================
    // Encryption Config
    // ===============================================================

    public function getBucketEncryption(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT sse_algorithm, kms_master_key_id, bucket_key_enabled FROM s3_encryption_configs WHERE bucket = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        $config = [
            'sseAlgorithm' => (string) $row['sse_algorithm'],
            'kmsMasterKeyId' => $row['kms_master_key_id'] !== null ? (string) $row['kms_master_key_id'] : null,
            'bucketKeyEnabled' => (bool) (int) $row['bucket_key_enabled'],
        ];
        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($config);

        return $config;
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported([
            'sseAlgorithm' => $sseAlgorithm,
            'kmsMasterKeyId' => $kmsMasterKeyId,
            'bucketKeyEnabled' => $bucketKeyEnabled,
        ]);
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                sse_algorithm = VALUES(sse_algorithm),
                kms_master_key_id = VALUES(kms_master_key_id),
                bucket_key_enabled = VALUES(bucket_key_enabled),
                updated_at = NOW()
            SQL,
            [$bucket, $sseAlgorithm, $kmsMasterKeyId, (int) $bucketKeyEnabled],
        );
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_encryption_configs WHERE bucket = ?', [$bucket]);
    }

    // ===============================================================
    // Lifecycle Configuration
    // ===============================================================

    public function getBucketLifecycle(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT rule_id, status, prefix, filter_json, transitions_json, expiration_json, noncurrent_transitions_json, noncurrent_expiration_json, abort_incomplete_days FROM s3_lifecycle_rules WHERE bucket = ? ORDER BY rule_id ASC',
            [$bucket],
        );

        $rules = [];
        foreach ($result as $row) {
            $rules[] = [
                'id' => (string) $row['rule_id'],
                'status' => (string) $row['status'],
                'prefix' => $row['prefix'] !== null ? (string) $row['prefix'] : null,
                'filter' => $row['filter_json'] !== null ? $this->decodeJson($row['filter_json']) : null,
                'transitions' => $row['transitions_json'] !== null ? $this->decodeJson($row['transitions_json']) : null,
                'expiration' => $row['expiration_json'] !== null ? $this->decodeJson($row['expiration_json']) : null,
                'noncurrentTransitions' => $row['noncurrent_transitions_json'] !== null ? $this->decodeJson($row['noncurrent_transitions_json']) : null,
                'noncurrentExpiration' => $row['noncurrent_expiration_json'] !== null ? $this->decodeJson($row['noncurrent_expiration_json']) : null,
                'abortIncompleteDays' => $row['abort_incomplete_days'] !== null ? (int) $row['abort_incomplete_days'] : null,
            ];
        }

        return $rules;
    }

    public function listReferencedStorageTiers(): array
    {
        return StorageTierReferences::collect(
            $this->conn()->query(StorageTierReferences::MYSQL_TIER_SQL),
            $this->conn()->query(StorageTierReferences::LIFECYCLE_SQL),
        );
    }

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute('DELETE FROM s3_lifecycle_rules WHERE bucket = ?', [$bucket]);
            foreach ($rules as $rule) {
                $link->execute(
                    'INSERT INTO s3_lifecycle_rules (bucket, rule_id, status, prefix, filter_json, transitions_json, expiration_json, noncurrent_transitions_json, noncurrent_expiration_json, abort_incomplete_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $bucket, $rule['id'], $rule['status'], $rule['prefix'] ?? null,
                        isset($rule['filter']) ? json_encode($rule['filter'], JSON_THROW_ON_ERROR) : null,
                        isset($rule['transitions']) ? json_encode($rule['transitions'], JSON_THROW_ON_ERROR) : null,
                        isset($rule['expiration']) ? json_encode($rule['expiration'], JSON_THROW_ON_ERROR) : null,
                        isset($rule['noncurrentTransitions']) ? json_encode($rule['noncurrentTransitions'], JSON_THROW_ON_ERROR) : null,
                        isset($rule['noncurrentExpiration']) ? json_encode($rule['noncurrentExpiration'], JSON_THROW_ON_ERROR) : null,
                        $rule['abortIncompleteDays'] ?? null,
                    ],
                );
            }
            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_lifecycle_rules WHERE bucket = ?', [$bucket]);
    }

    public function enqueueTierTransitionJob(
        string $bucket,
        string $key,
        ?string $versionId,
        string $sourceTier,
        string $targetTier,
        string $targetStorageClass,
        string $sourceStoragePath,
        int $maxAttempts = 10,
        string $origin = 'direct',
        ?string $lifecycleRuleId = null,
        ?bool $expectedIsLatest = null,
    ): int {
        $link = $this->conn();
        $result = $link->execute(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at,
                origin, lifecycle_rule_id, expected_is_latest
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $bucket,
                $key,
                $versionId,
                $sourceTier,
                $targetTier,
                $targetStorageClass,
                $sourceStoragePath,
                max(1, $maxAttempts),
                $this->multipartWriteIntentNow(),
                $origin,
                $lifecycleRuleId,
                $expectedIsLatest === null ? null : (int) $expectedIsLatest,
            ],
        );

        return $result->getLastInsertId()
            ?? throw new \RuntimeException('MySQL did not return the inserted tier transition job ID.');
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_tier_transition_jobs
                 WHERE status IN ('pending', 'processing') AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $tx->execute(
                    "UPDATE s3_tier_transition_jobs
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1, updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...$ids],
                );
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }

        foreach ($rows as &$row) {
            $row['next_attempt_at'] = $leaseExpiresAt;
            $row['lease_generation'] = (int) $row['lease_generation'] + 1;
            $row['status'] = 'processing';
        }
        unset($row);

        return array_map($this->rowToTierTransitionJob(...), $rows);
    }

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
        ?float $expectedLeaseExpiresAt = null,
        ?int $expectedLeaseGeneration = null,
    ): bool {
        $sql = $incrementAttempts
            ? 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE(?, target_storage_path), updated_at = NOW() WHERE id = ?'
            : 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), target_storage_path = COALESCE(?, target_storage_path), updated_at = NOW() WHERE id = ?';
        $params = [$status, $error, $nextAttemptAt, $targetStoragePath, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > UNIX_TIMESTAMP(NOW(6))";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_tier_transition_jobs
             SET next_attempt_at = ?, updated_at = NOW()
             WHERE id = ? AND status = 'processing'";
        $params = [$leaseExpiresAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= ' AND next_attempt_at = ? AND next_attempt_at > UNIX_TIMESTAMP(NOW(6))';
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function getTierTransitionJob(int $id): ?array
    {
        $result = $this->conn()->execute('SELECT * FROM s3_tier_transition_jobs WHERE id = ?', [$id]);
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToTierTransitionJob($row) : null;
    }

    public function enqueueRestoreJob(
        string $bucket,
        string $key,
        ?string $versionId,
        string $sourceTier,
        string $sourceStoragePath,
        int $restoreDays,
        int $maxAttempts = 10,
    ): int {
        $link = $this->conn();
        $result = $link->execute(
            'INSERT INTO s3_restore_jobs (bucket, key_name, version_id, source_tier, source_storage_path, restore_days, max_attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$bucket, $key, $versionId, $sourceTier, $sourceStoragePath, max(1, $restoreDays), max(1, $maxAttempts), $this->multipartWriteIntentNow()],
        );

        return $result->getLastInsertId()
            ?? throw new \RuntimeException('MySQL did not return the inserted restore job ID.');
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_restore_jobs
                 WHERE status IN ('pending', 'processing') AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $tx->execute(
                    "UPDATE s3_restore_jobs
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1, updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...$ids],
                );
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }

        foreach ($rows as &$row) {
            $row['next_attempt_at'] = $leaseExpiresAt;
            $row['lease_generation'] = (int) $row['lease_generation'] + 1;
            $row['status'] = 'processing';
        }
        unset($row);

        return array_map($this->rowToRestoreJob(...), $rows);
    }

    public function updateRestoreJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
        ?float $expectedLeaseExpiresAt = null,
        ?int $expectedLeaseGeneration = null,
    ): bool {
        $sql = $incrementAttempts
            ? 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE(?, restored_storage_path), updated_at = NOW() WHERE id = ?'
            : 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), restored_storage_path = COALESCE(?, restored_storage_path), updated_at = NOW() WHERE id = ?';
        $params = [$status, $error, $nextAttemptAt, $restoredStoragePath, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > UNIX_TIMESTAMP(NOW(6))";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_restore_jobs
             SET next_attempt_at = ?, updated_at = NOW()
             WHERE id = ? AND status = 'processing'";
        $params = [$leaseExpiresAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= ' AND next_attempt_at = ? AND next_attempt_at > UNIX_TIMESTAMP(NOW(6))';
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function getRestoreJob(int $id): ?array
    {
        $result = $this->conn()->execute('SELECT * FROM s3_restore_jobs WHERE id = ?', [$id]);
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToRestoreJob($row) : null;
    }

    // ===============================================================
    // Notification Configuration
    // ===============================================================

    public function getBucketNotification(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT config_id, event_type, destination_type, destination_arn, filter_rules_json FROM s3_notification_configs WHERE bucket = ? ORDER BY config_id ASC',
            [$bucket],
        );

        $configs = [];
        foreach ($result as $row) {
            /** @var list<array{name: string, value: string}>|null $filterRules */
            $filterRules = $row['filter_rules_json'] !== null ? $this->decodeJson($row['filter_rules_json']) : null;
            $configs[] = [
                'id' => (string) $row['config_id'],
                'events' => self::decodeEvents((string) $row['event_type']),
                'destinationType' => (string) $row['destination_type'],
                'destinationArn' => (string) $row['destination_arn'],
                'filterRules' => $filterRules,
            ];
        }

        return $configs;
    }

    public function putBucketNotification(string $bucket, array $configs): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute('DELETE FROM s3_notification_configs WHERE bucket = ?', [$bucket]);
            foreach ($configs as $config) {
                $link->execute(
                    'INSERT INTO s3_notification_configs (bucket, config_id, event_type, destination_type, destination_arn, filter_rules_json) VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $bucket, $config['id'], json_encode($config['events'], JSON_THROW_ON_ERROR), $config['destinationType'],
                        $config['destinationArn'],
                        isset($config['filterRules']) ? json_encode($config['filterRules'], JSON_THROW_ON_ERROR) : null,
                    ],
                );
            }
            if ($ownTx) {
                $link->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $link->rollback();
            }
            throw $e;
        }
    }

    /**
     * Decode the event_type column which may be a JSON array (new format)
     * or a plain string (legacy format from before multi-event support).
     *
     * @return list<string>
     */
    private static function decodeEvents(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, is_string(...)));
        }

        return [$raw];
    }

    // ===============================================================
    // Website Configuration
    // ===============================================================

    public function getBucketWebsite(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json FROM s3_website_configs WHERE bucket = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        /** @var list<array<string, mixed>>|null $routingRules */
        $routingRules = $row['routing_rules_json'] !== null ? $this->decodeJson($row['routing_rules_json']) : null;

        return [
            'indexDocument' => (string) $row['index_document'],
            'errorDocument' => $row['error_document'] !== null ? (string) $row['error_document'] : null,
            'redirectAllHost' => $row['redirect_all_host'] !== null ? (string) $row['redirect_all_host'] : null,
            'redirectAllProtocol' => $row['redirect_all_protocol'] !== null ? (string) $row['redirect_all_protocol'] : null,
            'routingRules' => $routingRules,
        ];
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_website_configs (bucket, index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                index_document = VALUES(index_document),
                error_document = VALUES(error_document),
                redirect_all_host = VALUES(redirect_all_host),
                redirect_all_protocol = VALUES(redirect_all_protocol),
                routing_rules_json = VALUES(routing_rules_json),
                updated_at = NOW()
            SQL,
            [
                $bucket, $indexDocument, $errorDocument, $redirectAllHost, $redirectAllProtocol,
                $routingRules !== null ? json_encode($routingRules, JSON_THROW_ON_ERROR) : null,
            ],
        );
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_website_configs WHERE bucket = ?', [$bucket]);
    }

    // ===============================================================
    // Rate Limiting
    // ===============================================================

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        return $this->transaction(function () use ($ip, $maxTokens, $refillRate): bool {
            $this->conn()->execute(
                'INSERT INTO s3_rate_limit_buckets (ip, tokens, last_refill_at)
                 VALUES (?, ?, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)))
                 ON DUPLICATE KEY UPDATE ip = VALUES(ip)',
                [$ip, $maxTokens],
            );
            $row = $this->conn()->execute(
                'SELECT tokens, last_refill_at, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AS database_now
                 FROM s3_rate_limit_buckets WHERE ip = ? FOR UPDATE',
                [$ip],
            )->fetchRow();
            \assert($row !== null);

            $now = max((float) $row['database_now'], (float) $row['last_refill_at']);
            $available = min(
                $maxTokens,
                (float) $row['tokens'] + ($now - (float) $row['last_refill_at']) * $refillRate,
            );
            $allowed = $available >= 1.0;
            $this->conn()->execute(
                'UPDATE s3_rate_limit_buckets SET tokens = ?, last_refill_at = ? WHERE ip = ?',
                [$allowed ? $available - 1.0 : $available, $now, $ip],
            );

            return $allowed;
        });
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_rate_limit_buckets
             WHERE last_refill_at < UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) - ?',
            [$maxAgeSeconds],
        );
    }

    // ===============================================================
    // Notification Queue
    // ===============================================================

    public function enqueueNotification(
        string $bucket,
        string $key,
        string $eventName,
        string $destinationUrl,
        string $payloadJson,
        int $maxAttempts = 10,
    ): int {
        $result = $this->conn()->execute(
            'INSERT INTO s3_notification_queue (bucket, key_name, event_name, destination_url, payload_json, max_attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, $this->multipartWriteIntentNow()],
        );

        return (int) $result->getLastInsertId();
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->conn()->execute('UPDATE s3_notification_queue SET payload_json = ? WHERE id = ?', [$payloadJson, $id]);
    }

    public function dequeueNotifications(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        // Claim expired processing leases directly. A separate table-wide
        // reset can deadlock two MariaDB workers during simultaneous claims.
        // FOR UPDATE SKIP LOCKED requires a transaction to hold the lock.
        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts, lease_generation
                 FROM s3_notification_queue
                 WHERE status IN ('pending', 'processing') AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $tx->execute(
                    "UPDATE s3_notification_queue
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1
                     WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...array_map(fn($id) => (int) $id, $ids)],
                );
            }

            $tx->commit();

            return array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'bucket' => (string) $row['bucket'],
                    'key_name' => (string) $row['key_name'],
                    'event_name' => (string) $row['event_name'],
                    'destination_url' => (string) $row['destination_url'],
                    'payload_json' => (string) $row['payload_json'],
                    'attempts' => (int) $row['attempts'],
                    'max_attempts' => (int) $row['max_attempts'],
                    'lease_expires_at' => $leaseExpiresAt,
                    'lease_generation' => (int) $row['lease_generation'] + 1,
                ],
                $rows,
            );
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function getNotificationQueueStats(): array
    {
        $result = $this->conn()->query('SELECT status, COUNT(*) AS count FROM s3_notification_queue GROUP BY status');

        $stats = [];
        while ($row = $result->fetchRow()) {
            $stats[(string) $row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    public function getMultipartCompletionIntentCount(): int
    {
        $row = $this->conn()->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow();
        if ($row === null || ! is_numeric($row['retained_count'] ?? null)) {
            throw new \RuntimeException('Multipart completion recovery capacity counter is unavailable.');
        }

        return (int) $row['retained_count'];
    }

    /** @return array{object: int, object_seal: int, part: int} */
    public function getWriteIntentCapacityCounts(): array
    {
        $result = $this->conn()->query("SELECT kind, retained_count FROM s3_write_intent_capacity WHERE kind IN ('object', 'object_seal', 'part')");
        $counts = [];
        while (($row = $result->fetchRow()) !== null) {
            if (! is_numeric($row['retained_count'] ?? null)) {
                throw new \RuntimeException('Write intent recovery capacity counter is unavailable.');
            }
            $counts[(string) $row['kind']] = (int) $row['retained_count'];
        }
        if (! isset($counts['object'], $counts['object_seal'], $counts['part']) || count($counts) !== 3) {
            throw new \RuntimeException('Write intent recovery capacity counter is unavailable.');
        }

        return ['object' => $counts['object'], 'object_seal' => $counts['object_seal'], 'part' => $counts['part']];
    }

    public function getStorageCleanupCount(): int
    {
        $row = $this->conn()->query('SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1')->fetchRow();
        if ($row === null || ! is_numeric($row['retained_count'] ?? null)) {
            throw new \RuntimeException('Storage cleanup capacity counter is unavailable.');
        }

        return (int) $row['retained_count'];
    }

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?float $expectedLeaseExpiresAt = null,
        ?int $expectedLeaseGeneration = null,
    ): bool {
        if ($status === 'sent' || $status === 'dead_letter') {
            $nextAttemptAt = $this->multipartWriteIntentNow();
        }
        $sql = $incrementAttempts
            ? 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1 WHERE id = ?'
            : 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at) WHERE id = ?';
        $params = [$status, $error, $nextAttemptAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > UNIX_TIMESTAMP(NOW(6))";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = $this->multipartWriteIntentNow() - $maxAgeSeconds;
        $this->conn()->execute(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND next_attempt_at < ?",
            [$cutoff],
        );
    }

    public function updateNotificationStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->updateNotificationStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $leaseExpiresAt, $leaseGeneration);
    }

    public function updateTierTransitionJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $targetStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->updateTierTransitionJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $leaseExpiresAt, $leaseGeneration);
    }

    public function renewTierTransitionJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->renewTierTransitionJobLease($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration);
    }

    public function updateRestoreJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $restoredStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->updateRestoreJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $leaseExpiresAt, $leaseGeneration);
    }

    public function renewRestoreJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->renewRestoreJobLease($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration);
    }


    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath, ?string $ownerId = null): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, owner_id, next_attempt_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$bucket, $storageTier, $storagePath, $ownerId, $this->multipartWriteIntentNow()],
            );
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function enqueueRecoveryStorageGarbage(string $bucket, string $storageTier, string $storagePath, ?string $ownerId = null): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, owner_id, recovery_admission, next_attempt_at)
                 VALUES (?, ?, ?, ?, 1, ?)',
                [$bucket, $storageTier, $storagePath, $ownerId, $this->multipartWriteIntentNow()],
            );
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function listMultipartPartWriteIntents(string $ownerId, int $afterId, int $limit): array
    {
        $result = $this->conn()->execute(
            "SELECT id, bucket, storage_tier, storage_path, status, attempts, created_at, next_attempt_at
             FROM s3_storage_garbage
             WHERE operation = 'part_write_intent' AND owner_id = ? AND id > ?
             ORDER BY id LIMIT ?",
            [$ownerId, max(0, $afterId), max(1, min(1000, $limit))],
        );
        $rows = [];
        while (($row = $result->fetchRow()) !== null) {
            $rows[] = $row;
        }

        return array_values(array_map(MultipartPartWriteIntentInventoryRow::fromDatabase(...), $rows));
    }

    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
        bool $localReservation = false,
    ): void {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Multipart part write intent admission requires its own transaction.');
        }
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['bucket_generation'] !== $bucketGeneration) {
            throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
        }
        $ownerId = $upload['owner_id'];
        $this->transaction(function () use ($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath, $ownerId, $localReservation): void {
            // Part commits hold the owner lock before their DELETE trigger
            // touches this counter. Keep the same order for admission.
            $this->lockOwnerForUpdate($ownerId);
            $global = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part' FOR UPDATE")->fetchRow();
            if ($global === null || (int) $global['retained_count'] >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $countRow = $this->conn()->execute(
                "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = ?",
                [$ownerId],
            )->fetchRow();
            if ((int) ($countRow['n'] ?? 0) >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $existing = $this->conn()->execute(
                "SELECT 1 FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = ? AND BINARY owner_id = BINARY ? AND upload_id = ? AND BINARY upload_id = BINARY ? AND BINARY storage_path = BINARY ? LIMIT 1",
                [$ownerId, $ownerId, $uploadId, $uploadId, $storagePath],
            )->fetchRow();
            $committed = $this->conn()->execute(
                'SELECT 1 FROM s3_parts WHERE upload_id = ? AND BINARY upload_id = BINARY ? AND BINARY storage_path = BINARY ? LIMIT 1',
                [$uploadId, $uploadId, $storagePath],
            )->fetchRow();
            if ($existing !== null || $committed !== null) {
                throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart part path has already been used.');
            }
            $due = $this->multipartWriteIntentNow() + 43_200;
            $result = $this->conn()->execute(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
             SELECT bucket, storage_tier, ?, 'part_write_intent', key_name, upload_id, owner_id, ?, ?
             FROM s3_multipart_uploads
             WHERE upload_id = ? AND BINARY upload_id = BINARY ? AND bucket = ? AND BINARY bucket = BINARY ?
               AND bucket_generation = ? AND BINARY owner_id = BINARY ? AND BINARY storage_tier = BINARY ?",
                [$storagePath, $due, $localReservation ? null : ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $uploadId, $uploadId, $bucket, $bucket, $bucketGeneration, $ownerId, $storageTier],
            );
            if ($result->getRowCount() !== 1) {
                throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
            }
        });
    }

    public function reserveLocalMultipartPartWriteIntent(string $bucket, string $bucketGeneration, string $storageTier, string $uploadId, string $storagePath): void
    {
        $this->createMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath, true);
    }

    public function canSealLocalMultipartPartClaim(int $garbageId, string $storagePath, int $leaseGeneration): bool
    {
        return $this->conn()->execute(
            "SELECT 1 FROM s3_storage_garbage AS g
             WHERE g.id = ? AND BINARY g.storage_path = BINARY ? AND g.operation = 'part_write_intent'
               AND g.status = 'processing' AND g.lease_generation = ?
               AND g.next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))
               AND g.upload_id IS NOT NULL AND g.owner_id IS NOT NULL
               AND g.dispatch_id IS NOT NULL AND BINARY g.dispatch_id != BINARY ?
               AND NOT EXISTS (
                   SELECT 1 FROM s3_parts AS p
                   WHERE p.upload_id = g.upload_id AND BINARY p.upload_id = BINARY g.upload_id
                     AND BINARY p.storage_path = BINARY g.storage_path
               )
               AND NOT EXISTS (
                   SELECT 1 FROM s3_storage_garbage AS other
                   WHERE other.id != g.id AND other.operation = 'part_write_intent'
                     AND other.owner_id = g.owner_id AND BINARY other.owner_id = BINARY g.owner_id
                     AND other.upload_id = g.upload_id AND BINARY other.upload_id = BINARY g.upload_id
                     AND BINARY other.storage_path = BINARY g.storage_path
               ) LIMIT 1",
            [$garbageId, $storagePath, $leaseGeneration, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH],
        )->fetchRow() !== null;
    }

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart part dispatch ID.');
        }
        $result = $this->conn()->execute(
            "UPDATE s3_storage_garbage SET dispatch_id = ? WHERE bucket = ? AND BINARY bucket = BINARY ?
               AND upload_id = ? AND BINARY upload_id = BINARY ? AND BINARY storage_path = BINARY ?
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id IS NULL
               AND next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))",
            [$dispatchId, $bucket, $bucket, $uploadId, $uploadId, $storagePath],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE bucket = ? AND BINARY bucket = BINARY ?
               AND upload_id = ? AND BINARY upload_id = BINARY ? AND BINARY storage_path = BINARY ?
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id = ?
               AND next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))",
            [$bucket, $bucket, $uploadId, $uploadId, $storagePath, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE bucket = ? AND BINARY bucket = BINARY ?
               AND upload_id = ? AND BINARY upload_id = BINARY ? AND BINARY storage_path = BINARY ?
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id IS NULL",
            [$bucket, $bucket, $uploadId, $uploadId, $storagePath],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE g FROM s3_storage_garbage AS g
               WHERE g.bucket = ? AND BINARY g.bucket = BINARY ?
                 AND g.upload_id = ? AND BINARY g.upload_id = BINARY ?
                 AND BINARY g.storage_path = BINARY ?
                 AND g.operation = 'part_write_intent' AND g.status = 'pending' AND g.dispatch_id = ?
                 AND NOT EXISTS (
                     SELECT 1 FROM s3_parts AS p
                     WHERE BINARY p.upload_id = BINARY g.upload_id
                       AND BINARY p.storage_path = BINARY g.storage_path
                 )",
            [$bucket, $bucket, $uploadId, $uploadId, $storagePath, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        // A range DELETE holds an InnoDB gap lock before its trigger waits for
        // the part-capacity row. An admission holding that row then cannot
        // INSERT. Select IDs without locks and conditionally delete by PK.
        $candidates = $this->conn()->execute(
            "SELECT id FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND BINARY storage_tier = BINARY ?
               AND status = 'pending' AND dispatch_id IS NULL
               AND next_attempt_at <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) ORDER BY id LIMIT ?",
            [$storageTier, max(1, $limit)],
        );
        $ids = [];
        while (($row = $candidates->fetchRow()) !== null) {
            $ids[] = (int) $row['id'];
        }
        $retired = 0;
        foreach ($ids as $id) {
            $result = $this->conn()->execute(
                "DELETE FROM s3_storage_garbage WHERE id = ? AND operation = 'part_write_intent'
                   AND BINARY storage_tier = BINARY ? AND status = 'pending' AND dispatch_id IS NULL
                   AND next_attempt_at <= UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6))",
                [$id, $storageTier],
            );
            $retired += $result->getRowCount() ?? 0;
        }

        return $retired;
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        $legacy = ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH;
        if ($this->conn()->execute("SELECT 1 FROM s3_storage_garbage WHERE BINARY storage_tier = BINARY ? AND operation = 'part_write_intent' AND BINARY dispatch_id = BINARY ? LIMIT 1", [$storageTier, $legacy])->fetchRow() !== null) {
            throw new \RuntimeException('Legacy multipart part intent blocks local fence admission.');
        }
        if ($this->conn()->execute('SELECT 1 FROM s3_multipart_write_intents WHERE BINARY storage_tier = BINARY ? AND BINARY dispatch_id = BINARY ? LIMIT 1', [$storageTier, $legacy])->fetchRow() !== null) {
            throw new \RuntimeException('Legacy multipart completion intent blocks local fence admission.');
        }
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        $row = $this->conn()->execute(
            "SELECT operation FROM s3_storage_garbage
             WHERE recursive_cleanup_unsafe = 1
               AND storage_tier = ? AND BINARY storage_tier = BINARY ?
             LIMIT 1",
            [$storageTier, $storageTier],
        )->fetchRow();
        if ($row === null) {
            return;
        }
        if (($row['operation'] ?? null) === 'abort_multipart') {
            throw new \RuntimeException('Legacy recursive multipart cleanup blocks local storage admission.');
        }

        throw new \RuntimeException('Unclassified storage cleanup blocks local storage admission.');
    }

    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage
             WHERE bucket = ? AND BINARY bucket = BINARY ? AND upload_id = ? AND BINARY upload_id = BINARY ?
               AND BINARY storage_path = BINARY ?
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id = ? AND next_attempt_at > ?",
            [$bucket, $bucket, $uploadId, $uploadId, $storagePath, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $this->multipartWriteIntentNow()],
        );

        return $result->getRowCount() === 1;
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('Bucket multipart cleanup must be staged inside a transaction.');
        }
        $unknown = $this->conn()->execute(
            'SELECT 1 FROM s3_multipart_uploads WHERE bucket = ? AND BINARY bucket = BINARY ? AND (storage_tier IS NULL OR storage_tier = ?) LIMIT 1',
            [$bucket, $bucket, ''],
        )->fetchRow();
        if ($unknown !== null) {
            throw new \RuntimeException('Legacy multipart upload has no pinned storage tier.');
        }
        try {
            $this->conn()->execute(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at)
             SELECT u.bucket, u.storage_tier, p.storage_path, 'delete_path', u.key_name, u.upload_id, u.owner_id, ?
             FROM s3_multipart_uploads AS u
             JOIN s3_parts AS p ON p.upload_id = u.upload_id AND BINARY p.upload_id = BINARY u.upload_id
             WHERE u.bucket = ? AND BINARY u.bucket = BINARY ?",
                [$this->multipartWriteIntentNow(), $bucket, $bucket],
            );
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function stageBucketMultipartCleanupForBucket(BucketInfo $bucket, string $storageTier): void
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('Bucket multipart cleanup must be staged inside a transaction.');
        }
        $stale = $this->conn()->execute(
            "SELECT 1 FROM s3_multipart_uploads WHERE bucket = ? AND BINARY bucket = BINARY ?
               AND NOT (BINARY bucket_generation = BINARY ? OR (bucket_generation = '' AND BINARY owner_id = BINARY ?)) LIMIT 1",
            [$bucket->name, $bucket->name, $bucket->generation, $bucket->ownerId],
        )->fetchRow();
        if ($stale !== null) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }
        $this->stageBucketMultipartCleanup($bucket->name, $storageTier);
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->conn()->execute(
            "DELETE FROM s3_storage_garbage
             WHERE bucket = ? AND BINARY bucket = BINARY ?
               AND storage_tier = ? AND BINARY storage_tier = BINARY ?
               AND storage_path = ? AND BINARY storage_path = BINARY ?
               AND operation = 'delete_path' AND BINARY operation = BINARY 'delete_path'
               AND status = 'pending' AND BINARY status = BINARY 'pending'",
            [$bucket, $bucket, $storageTier, $storageTier, $storagePath, $storagePath],
        );
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->claimStorageGarbage($limit);
            } catch (\Amp\Sql\SqlQueryError $e) {
                // SKIP LOCKED skips row locks, but MariaDB can still deadlock
                // when two claims update their selected index records.
                if ($attempt >= 5 || ! str_starts_with($e->getMessage(), 'MySQL error (1213):')) {
                    throw $e;
                }
                \Amp\delay(0.01 * $attempt);
            }
        }
    }

    /**
     * @return list<array{id: int, bucket: string, storage_tier: string, storage_path: string, operation: string, key_name: ?string, upload_id: ?string, attempts: int, lease_expires_at: float, lease_generation: int, dispatch_id: ?string}>
     */
    private function claimStorageGarbage(int $limit): array
    {
        return $this->transaction(function () use ($limit): array {
            $now = $this->multipartWriteIntentNow();
            $leaseExpiresAt = QueueLease::expiresAt($now);
            $result = $this->conn()->execute(
                "SELECT id, bucket, storage_tier, storage_path, operation, key_name, upload_id, attempts, lease_generation, dispatch_id
                 FROM s3_storage_garbage
                 WHERE status IN ('pending', 'processing') AND next_attempt_at <= ?
                   AND NOT (operation = 'part_write_intent' AND dispatch_id IS NULL)
                 ORDER BY next_attempt_at ASC, id ASC LIMIT ? FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'bucket' => (string) $row['bucket'],
                    'storage_tier' => (string) $row['storage_tier'],
                    'storage_path' => (string) $row['storage_path'],
                    'operation' => (string) $row['operation'],
                    'key_name' => $row['key_name'] === null ? null : (string) $row['key_name'],
                    'upload_id' => $row['upload_id'] === null ? null : (string) $row['upload_id'],
                    'attempts' => (int) $row['attempts'],
                    'lease_expires_at' => $leaseExpiresAt,
                    'lease_generation' => (int) $row['lease_generation'] + 1,
                    'dispatch_id' => $row['dispatch_id'] === null ? null : (string) $row['dispatch_id'],
                ];
            }
            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $this->conn()->execute(
                    "UPDATE s3_storage_garbage SET status = 'processing', next_attempt_at = ?,
                     lease_generation = lease_generation + 1 WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...$ids],
                );
            }

            return $rows;
        });
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        $sql = 'DELETE FROM s3_storage_garbage WHERE id = ?';
        $params = [$id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ?";
            $params[] = $expectedLeaseExpiresAt;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE id = ? AND status = 'processing'
             AND next_attempt_at = ? AND lease_generation = ?",
            [$id, $leaseExpiresAt, $leaseGeneration],
        )->getRowCount() ?? 0) === 1;
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "UPDATE s3_storage_garbage SET next_attempt_at = ?
             WHERE id = ? AND status = 'processing' AND next_attempt_at = ? AND lease_generation = ?
               AND next_attempt_at > UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AND ? > next_attempt_at",
            [$newLeaseExpiresAt, $id, $expectedLeaseExpiresAt, $leaseGeneration, $newLeaseExpiresAt],
        )->getRowCount() ?? 0) === 1;
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        $sql
            = "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = ?, next_attempt_at = ?
             WHERE id = ?";
        $params = [$error, $nextAttemptAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ?";
            $params[] = $expectedLeaseExpiresAt;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = ?, next_attempt_at = ?
             WHERE id = ? AND status = 'processing' AND next_attempt_at = ? AND lease_generation = ?",
            [$error, $nextAttemptAt, $id, $leaseExpiresAt, $leaseGeneration],
        )->getRowCount() ?? 0) === 1;
    }

    // ===============================================================
    // Transaction support
    // ===============================================================

    public function beginTransaction(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException('beginTransaction() must be called from within a Fiber.');
        }
        /** @var \Fiber<mixed, mixed, mixed, mixed> $fiber */
        if (isset($this->fiberTxMap[$fiber])) {
            throw new \LogicException('Nested beginTransaction() is unsupported; use transaction() to join the current transaction.');
        }
        $this->fiberTxMap[$fiber] = $this->pool->beginTransaction();
        $this->objectPartLayoutPublicationIds[$fiber] = [];
    }

    public function commit(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberTxMap[$fiber])) {
            throw new \LogicException('commit() requires an active transaction in the current Fiber.');
        }
        $transaction = $this->fiberTxMap[$fiber];
        unset($this->fiberTxMap[$fiber]);
        unset($this->objectPartLayoutPublicationIds[$fiber]);
        $transaction->commit();
    }

    public function rollback(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberTxMap[$fiber])) {
            throw new \LogicException('rollback() requires an active transaction in the current Fiber.');
        }
        $transaction = $this->fiberTxMap[$fiber];
        unset($this->fiberTxMap[$fiber]);
        unset($this->objectPartLayoutPublicationIds[$fiber]);
        $transaction->rollback();
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $transaction = $this->fiberTransaction();
        if ($transaction === null) {
            throw new \LogicException('lockOwnerForUpdate() requires an active transaction.');
        }

        $transaction->execute(
            <<<'SQL'
            INSERT INTO s3_owner_write_locks (owner_id)
            VALUES (?)
            ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id)
            SQL,
            [$ownerId],
        );
        $transaction->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = ? FOR UPDATE',
            [$ownerId],
        )->fetchRow();
    }

    public function prepareOwnerReadLock(string $ownerId): void
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('prepareOwnerReadLock() must be called outside an active transaction.');
        }

        $row = $this->pool->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = ?',
            [$ownerId],
        )->fetchRow();
        if ($row !== null) {
            return;
        }

        $this->pool->execute(
            'INSERT IGNORE INTO s3_owner_write_locks (owner_id) VALUES (?)',
            [$ownerId],
        );
    }

    public function lockOwnerForRead(string $ownerId): void
    {
        $transaction = $this->fiberTransaction();
        if ($transaction === null) {
            throw new \LogicException('lockOwnerForRead() requires an active transaction.');
        }

        $row = $transaction->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = ? LOCK IN SHARE MODE',
            [$ownerId],
        )->fetchRow();
        if ($row === null) {
            throw new \RuntimeException('Owner read lock row is missing; call prepareOwnerReadLock() before the transaction.');
        }
    }

    public function transaction(callable $callback): mixed
    {
        if (\Fiber::getCurrent() === null) {
            return \Amp\async(fn(): mixed => $this->transaction($callback))->await();
        }

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        if ($ownTx) {
            $this->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownTx) {
                $this->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $this->fiberTransaction() !== null) {
                try {
                    $this->rollback();
                } catch (\Throwable) {
                    // Preserve the operation failure; rollback is best-effort
                    // once the transaction connection itself has failed.
                }
            }

            throw $e;
        }
    }

    // ===============================================================
    // Internal helpers
    // ===============================================================

    private function conn(): MysqlLink
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberTxMap[$fiber])) {
            return $this->fiberTxMap[$fiber];
        }

        return $this->pool;
    }

    private function translateStorageCleanupCapacity(\Throwable $error): never
    {
        if (str_contains($error->getMessage(), 'Storage cleanup capacity is exhausted.')) {
            throw new \OpsFour\S3Server\Exception\StorageCleanupCapacityException(previous: $error);
        }
        throw $error;
    }

    private function fiberTransaction(): ?MysqlTransaction
    {
        $fiber = \Fiber::getCurrent();

        return ($fiber !== null && isset($this->fiberTxMap[$fiber])) ? $this->fiberTxMap[$fiber] : null;
    }

    private function authorizeObjectPartLayoutPublication(int $objectId): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberTxMap[$fiber])) {
            throw new \LogicException('Object part layout publication authority requires an active transaction.');
        }
        $publicationIds = $this->objectPartLayoutPublicationIds[$fiber] ?? [];
        $publicationIds[$objectId] = true;
        $this->objectPartLayoutPublicationIds[$fiber] = $publicationIds;
    }

    private static function isIgnorableSchemaMigrationError(string $sql, \Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        return (
            preg_match('/^ALTER TABLE [a-z0-9_]+ ADD COLUMN /i', $normalizedSql) === 1
            && str_contains($message, 'mysql error (1060):')
            && str_contains($message, 'duplicate column name')
        ) || (
            (preg_match('/^ALTER TABLE [a-z0-9_]+ ADD INDEX /i', $normalizedSql) === 1
                || preg_match('/^CREATE (?:UNIQUE )?INDEX /i', $normalizedSql) === 1)
            && str_contains($message, 'mysql error (1061):')
            && str_contains($message, 'duplicate key name')
        )
            || (
                str_contains($sql, 'ADD CONSTRAINT chk_s3_encryption_configs_supported')
                && str_contains($message, 'mysql error (3822):')
                && str_contains($message, 'duplicate check constraint name')
            )
            || (
                str_contains($sql, 'ADD CONSTRAINT chk_s3_storage_garbage_operation')
                && str_contains($message, 'mysql error (3822):')
                && str_contains($message, 'duplicate check constraint name')
            )
            || (
                str_contains($sql, 'ADD CONSTRAINT chk_s3_write_intent_capacity')
                && str_contains($message, 'mysql error (3822):')
                && str_contains($message, 'duplicate check constraint name')
            )
            || (
                str_contains($sql, ' DROP INDEX ')
                && str_starts_with($sql, 'ALTER TABLE ')
                && str_contains($message, 'mysql error (1091):')
            )
            || (
                $normalizedSql === 'ALTER TABLE s3_write_intent_capacity DROP CHECK chk_s3_write_intent_capacity'
                && (str_contains($message, 'mysql error (1091):')
                    || (str_contains($message, 'mysql error (3821):')
                        && str_contains($message, "check constraint 'chk_s3_write_intent_capacity' is not found")))
            );
    }

    /** @param array<string, int|float|string|null> $row */
    private function rowToBucketInfo(array $row): BucketInfo
    {
        return new BucketInfo(
            name: (string) $row['name'],
            ownerId: (string) $row['owner_id'],
            region: (string) $row['region'],
            creationDate: new \DateTimeImmutable($this->formatTimestamp($row['created_at'])),
            generation: (string) $row['generation'],
        );
    }

    /** @param array<string, int|float|string|null> $row */
    private function rowToObjectInfo(array $row): ObjectInfo
    {
        $userMetadata = is_string($row['user_metadata'])
            ? json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['user_metadata'] ?? []);

        $systemMetadata = [];

        if (isset($row['storage_path'])) {
            $systemMetadata['storagePath'] = (string) $row['storage_path'];
        }
        if (isset($row['bucket_generation'])) {
            $systemMetadata['bucketGeneration'] = (string) $row['bucket_generation'];
        }
        if (isset($row['content_encoding'])) {
            $systemMetadata['content-encoding'] = (string) $row['content_encoding'];
        }
        if (isset($row['content_disposition'])) {
            $systemMetadata['content-disposition'] = (string) $row['content_disposition'];
        }
        if (isset($row['cache_control'])) {
            $systemMetadata['cache-control'] = (string) $row['cache_control'];
        }
        if (isset($row['checksum_crc32'])) {
            $systemMetadata['checksum-crc32'] = (string) $row['checksum_crc32'];
        }
        if (isset($row['checksum_crc32c'])) {
            $systemMetadata['checksum-crc32c'] = (string) $row['checksum_crc32c'];
        }
        if (isset($row['checksum_sha1'])) {
            $systemMetadata['checksum-sha1'] = (string) $row['checksum_sha1'];
        }
        if (isset($row['checksum_sha256'])) {
            $systemMetadata['checksum-sha256'] = (string) $row['checksum_sha256'];
        }
        if (isset($row['is_latest'])) {
            $systemMetadata['isLatest'] = (bool) (int) $row['is_latest'] ? '1' : '0';
        }
        if (isset($row['created_at'])) {
            $systemMetadata['createdAt'] = $this->formatTimestamp($row['created_at']);
        }
        if (isset($row['noncurrent_at'])) {
            $systemMetadata['noncurrentAt'] = $this->formatTimestamp($row['noncurrent_at']);
        }

        $rawVersionId = (string) ($row['version_id'] ?? 'null');
        $versionId = $rawVersionId === 'null' ? null : $rawVersionId;

        $lastModified = isset($row['updated_at'])
            ? new \DateTimeImmutable($this->formatTimestamp($row['updated_at']))
            : new \DateTimeImmutable();
        $restoreExpiresAt = isset($row['restore_expires_at']) && $row['restore_expires_at'] !== ''
            ? new \DateTimeImmutable($this->formatTimestamp($row['restore_expires_at']))
            : null;

        /** @var array<string, string> $typedUserMetadata */
        $typedUserMetadata = $userMetadata;

        return new ObjectInfo(
            bucket: (string) $row['bucket'],
            key: (string) $row['key_name'],
            size: (int) $row['size'],
            etag: (string) $row['etag'],
            contentType: (string) ($row['content_type'] ?? 'application/octet-stream'),
            storageClass: (string) ($row['storage_class'] ?? 'STANDARD'),
            storageTier: (string) ($row['storage_tier'] ?? ($row['storage_class'] ?? 'STANDARD')),
            transitionStatus: (string) ($row['transition_status'] ?? 'available'),
            transitionTargetTier: isset($row['transition_target_tier']) ? (string) $row['transition_target_tier'] : null,
            transitionError: isset($row['transition_error']) ? (string) $row['transition_error'] : null,
            restoreStatus: isset($row['restore_status']) ? (string) $row['restore_status'] : null,
            restoredStoragePath: isset($row['restored_storage_path']) ? (string) $row['restored_storage_path'] : null,
            restoreExpiresAt: $restoreExpiresAt,
            ownerId: (string) ($row['owner_id'] ?? ''),
            versionId: $versionId,
            isDeleteMarker: (bool) (int) ($row['is_delete_marker'] ?? 0),
            userMetadata: $typedUserMetadata,
            systemMetadata: $systemMetadata,
            lastModified: $lastModified,
            restoredStorageTier: isset($row['restored_storage_tier']) ? (string) $row['restored_storage_tier'] : null,
            partLayout: $this->rowToObjectPartLayoutSummary($row),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowToObjectPartLayoutSummary(array $row): ?ObjectPartLayoutSummary
    {
        if ((bool) (int) ($row['is_delete_marker'] ?? 0)) {
            foreach (['part_layout_kind', 'part_layout_count', 'part_layout_compat_count', 'part_layout_digest'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null) {
                    throw new \RuntimeException('Current metadata schema contains a delete marker with an object part layout.');
                }
            }

            return null;
        }
        if (($row['part_layout_kind'] ?? null) === null) {
            if (array_key_exists('part_layout_kind', $row)) {
                throw new \RuntimeException('Current metadata schema contains an object without a part layout.');
            }
            return null;
        }
        if (($row['part_layout_count'] ?? null) === null || ($row['part_layout_digest'] ?? null) === null) {
            throw new \RuntimeException('Current metadata schema contains an incomplete object part layout summary.');
        }

        return new ObjectPartLayoutSummary((string) $row['part_layout_kind'], (int) $row['part_layout_count'], $row['part_layout_compat_count'] === null ? null : (int) $row['part_layout_compat_count'], (string) $row['part_layout_digest']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowToTierTransitionJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'bucket' => (string) $row['bucket'],
            'key' => (string) $row['key_name'],
            'versionId' => $row['version_id'] !== null ? (string) $row['version_id'] : null,
            'sourceTier' => (string) $row['source_tier'],
            'targetTier' => (string) $row['target_tier'],
            'targetStorageClass' => (string) $row['target_storage_class'],
            'sourceStoragePath' => (string) $row['source_storage_path'],
            'origin' => (string) $row['origin'],
            'lifecycleRuleId' => $row['lifecycle_rule_id'] !== null ? (string) $row['lifecycle_rule_id'] : null,
            'expectedIsLatest' => $row['expected_is_latest'] !== null ? (bool) $row['expected_is_latest'] : null,
            'targetStoragePath' => $row['target_storage_path'] !== null ? (string) $row['target_storage_path'] : null,
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'maxAttempts' => (int) $row['max_attempts'],
            'nextAttemptAt' => (float) $row['next_attempt_at'],
            'leaseGeneration' => (int) $row['lease_generation'],
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'createdAt' => $this->formatTimestamp($row['created_at']),
            'updatedAt' => $this->formatTimestamp($row['updated_at']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rowToRestoreJob(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'bucket' => (string) $row['bucket'],
            'key' => (string) $row['key_name'],
            'versionId' => $row['version_id'] !== null ? (string) $row['version_id'] : null,
            'sourceTier' => (string) $row['source_tier'],
            'sourceStoragePath' => (string) $row['source_storage_path'],
            'restoredStoragePath' => $row['restored_storage_path'] !== null ? (string) $row['restored_storage_path'] : null,
            'restoreDays' => (int) $row['restore_days'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'maxAttempts' => (int) $row['max_attempts'],
            'nextAttemptAt' => (float) $row['next_attempt_at'],
            'leaseGeneration' => (int) $row['lease_generation'],
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'createdAt' => $this->formatTimestamp($row['created_at']),
            'updatedAt' => $this->formatTimestamp($row['updated_at']),
        ];
    }

    private function assertObjectTargetExists(string $bucket, string $key, ?string $versionId): void
    {
        $object = $versionId === null
            ? $this->getObjectMetadata($bucket, $key)
            : $this->getObjectMetadataByVersion($bucket, $key, $versionId);

        if ($object === null || $object->isDeleteMarker) {
            throw new NoSuchKeyException();
        }
    }

    private function escapeLikePattern(string $pattern): string
    {
        $pattern = str_replace('\\', '\\\\', $pattern);
        $pattern = str_replace('%', '\\%', $pattern);
        $pattern = str_replace('_', '\\_', $pattern);

        return $pattern;
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        // MySQL TIMESTAMP format is "YYYY-MM-DD HH:MM:SS" — normalize to ISO 8601.
        $str = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $str)) {
            return str_replace(' ', 'T', $str) . 'Z';
        }

        return $str;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    // ===============================================================
    // Public Access Block
    // ===============================================================

    public function getPublicAccessBlock(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_public_access_blocks WHERE bucket = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return [
            'blockPublicAcls' => (bool) $row['block_public_acls'],
            'ignorePublicAcls' => (bool) $row['ignore_public_acls'],
            'blockPublicPolicy' => (bool) $row['block_public_policy'],
            'restrictPublicBuckets' => (bool) $row['restrict_public_buckets'],
        ];
    }

    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void
    {
        $this->conn()->execute(
            'INSERT INTO s3_public_access_blocks (bucket, block_public_acls, ignore_public_acls, block_public_policy, restrict_public_buckets) '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE block_public_acls = VALUES(block_public_acls), ignore_public_acls = VALUES(ignore_public_acls), block_public_policy = VALUES(block_public_policy), restrict_public_buckets = VALUES(restrict_public_buckets)',
            [$bucket, (int) $blockPublicAcls, (int) $ignorePublicAcls, (int) $blockPublicPolicy, (int) $restrictPublicBuckets],
        );
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_public_access_blocks WHERE bucket = ?',
            [$bucket],
        );
    }

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        $row = $this->conn()->execute('SELECT object_ownership FROM s3_bucket_ownership_controls WHERE bucket = ?', [$bucket])->fetchRow();

        return $row === null ? null : (string) $row['object_ownership'];
    }

    public function putBucketOwnershipControls(string $bucket, string $ownership): void
    {
        $this->conn()->execute(
            'INSERT INTO s3_bucket_ownership_controls (bucket, object_ownership) VALUES (?, ?) ON DUPLICATE KEY UPDATE object_ownership = VALUES(object_ownership)',
            [$bucket, $ownership],
        );
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_bucket_ownership_controls WHERE bucket = ?', [$bucket]);
    }

    // ===============================================================
    // Bucket Logging
    // ===============================================================

    public function getBucketLogging(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_bucket_logging WHERE bucket = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return [
            'targetBucket' => (string) $row['target_bucket'],
            'targetPrefix' => (string) $row['target_prefix'],
        ];
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->conn()->execute(
            'INSERT INTO s3_bucket_logging (bucket, target_bucket, target_prefix) '
            . 'VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE target_bucket = VALUES(target_bucket), target_prefix = VALUES(target_prefix)',
            [$bucket, $targetBucket, $targetPrefix],
        );
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_bucket_logging WHERE bucket = ?',
            [$bucket],
        );
    }

    // ===============================================================
    // Distributed locks
    // ===============================================================

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        $ttlSeconds = max(1, $ttlSeconds);

        $insert = $this->conn()->execute(
            'INSERT IGNORE INTO s3_locks (lock_name, owner_id, expires_at, updated_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())',
            [$lockName, $ownerId, $ttlSeconds],
        );
        if (($insert->getRowCount() ?? 0) > 0) {
            return true;
        }

        $update = $this->conn()->execute(
            'UPDATE s3_locks
             SET owner_id = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), updated_at = UTC_TIMESTAMP()
             WHERE lock_name = ? AND (owner_id = ? OR expires_at <= UTC_TIMESTAMP())',
            [$ownerId, $ttlSeconds, $lockName, $ownerId],
        );

        return ($update->getRowCount() ?? 0) > 0;
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_locks WHERE lock_name = ? AND owner_id = ?',
            [$lockName, $ownerId],
        );
    }

    // ===============================================================
    // Lifecycle checkpoints
    // ===============================================================

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        $result = $this->conn()->execute(
            'SELECT cursor_key, cursor_version_id, cursor_upload_id FROM s3_lifecycle_checkpoints WHERE bucket = ? AND rule_id = ? AND action = ?',
            [$bucket, $ruleId, $action],
        );
        $row = $result->fetchRow();
        if ($row === null) {
            return null;
        }

        return [
            'cursorKey' => $row['cursor_key'] !== null ? (string) $row['cursor_key'] : null,
            'cursorVersionId' => $row['cursor_version_id'] !== null ? (string) $row['cursor_version_id'] : null,
            'cursorUploadId' => $row['cursor_upload_id'] !== null ? (string) $row['cursor_upload_id'] : null,
        ];
    }

    public function putLifecycleCheckpoint(
        string $bucket,
        string $ruleId,
        string $action,
        ?string $cursorKey,
        ?string $cursorVersionId = null,
        ?string $cursorUploadId = null,
    ): void {
        $this->conn()->execute(
            'INSERT INTO s3_lifecycle_checkpoints (bucket, rule_id, action, cursor_key, cursor_version_id, cursor_upload_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                cursor_key = VALUES(cursor_key),
                cursor_version_id = VALUES(cursor_version_id),
                cursor_upload_id = VALUES(cursor_upload_id),
                updated_at = UTC_TIMESTAMP()',
            [$bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId],
        );
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_lifecycle_checkpoints WHERE bucket = ? AND rule_id = ? AND action = ?',
            [$bucket, $ruleId, $action],
        );
    }

    // ===============================================================
    // Lifecycle query methods
    // ===============================================================

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        $sql = 'SELECT * FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0 AND created_at < ?';
        $params = [$bucket, $olderThan->format('Y-m-d H:i:s')];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($afterKey !== null) {
            $sql .= ' AND key_name > ?';
            $params[] = $afterKey;
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, 's3_objects', $tags);

        $sql .= ' ORDER BY key_name ASC LIMIT ?';
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);

        $objects = [];
        foreach ($result as $row) {
            $objects[] = $this->rowToObjectInfo($row);
        }

        return $objects;
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$noncurrentDays} days")
            ->format('Y-m-d H:i:s');

        $sql = 'SELECT * FROM s3_objects WHERE bucket = ? AND is_latest = 0 AND is_delete_marker = 0 AND noncurrent_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($afterKey !== null) {
            $sql .= ' AND (key_name > ? OR (key_name = ? AND version_id > ?))';
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, 's3_objects', $tags);

        $sql .= ' ORDER BY key_name ASC, version_id ASC LIMIT ?';
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);

        $objects = [];
        foreach ($result as $row) {
            $objects[] = $this->rowToObjectInfo($row);
        }

        return $objects;
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null, ?\DateTimeImmutable $createdBefore = null): array
    {
        $cutoff = ($createdBefore ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$daysAfterInitiation} days"))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $sql = 'SELECT upload_id, bucket, key_name FROM s3_multipart_uploads WHERE bucket = ? AND created_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($afterKey !== null) {
            $sql .= ' AND (key_name > ? OR (key_name = ? AND upload_id > ?))';
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterUploadId ?? '';
        }

        $sql .= ' ORDER BY key_name ASC, upload_id ASC LIMIT ?';
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);

        $uploads = [];
        foreach ($result as $row) {
            $uploads[] = [
                'upload_id' => (string) $row['upload_id'],
                'bucket' => (string) $row['bucket'],
                'key_name' => (string) $row['key_name'],
            ];
        }

        return $uploads;
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        // Find delete markers where no other versions exist for the same key.
        $sql = 'SELECT dm.* FROM s3_objects dm '
            . 'WHERE dm.bucket = ? AND dm.is_delete_marker = 1 AND dm.is_latest = 1 '
            . 'AND NOT EXISTS (SELECT 1 FROM s3_objects o WHERE o.bucket = dm.bucket AND o.key_name = dm.key_name AND o.is_delete_marker = 0)';
        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND CAST(LEFT(dm.key_name, LENGTH(?)) AS BINARY) = CAST(? AS BINARY)';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($afterKey !== null) {
            $sql .= ' AND (dm.key_name > ? OR (dm.key_name = ? AND dm.version_id > ?))';
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, 'dm', $tags);

        $sql .= ' ORDER BY dm.key_name ASC, dm.version_id ASC LIMIT ?';
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);

        $objects = [];
        foreach ($result as $row) {
            $objects[] = $this->rowToObjectInfo($row);
        }

        return $objects;
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        $result = $this->conn()->execute(
            'SELECT o.*, b.generation AS bucket_generation
             FROM s3_objects AS o
             INNER JOIN s3_buckets AS b ON b.name = o.bucket
             WHERE o.restore_status = ? AND o.restored_storage_path IS NOT NULL AND o.restore_expires_at IS NOT NULL AND o.restore_expires_at <= ?
             ORDER BY o.restore_expires_at ASC, o.bucket ASC, o.key_name ASC, o.version_id ASC
             LIMIT ?',
            [
                'restored',
                $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                max(1, min(10000, $limit)),
            ],
        );

        $objects = [];
        foreach ($result as $row) {
            $objects[] = $this->rowToObjectInfo($row);
        }

        return $objects;
    }

    /**
     * @param  list<mixed>  $params
     * @param  array<string, string>  $tags
     */
    private function appendLifecycleTagFilters(string $sql, array &$params, string $objectAlias, array $tags): string
    {
        foreach ($tags as $key => $value) {
            $sql .= " AND EXISTS (
                SELECT 1 FROM s3_tagging lt
                WHERE lt.resource_type = 'object'
                  AND lt.bucket = {$objectAlias}.bucket
                  AND lt.key_name = {$objectAlias}.key_name
                  AND lt.version_id = {$objectAlias}.version_id
                  AND lt.tag_key = ?
                  AND lt.tag_value = ?
            )";
            $params[] = $key;
            $params[] = $value;
        }

        return $sql;
    }
}
