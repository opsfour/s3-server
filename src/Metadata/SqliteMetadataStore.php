<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Policy\PolicyDocumentValidator;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * SQLite-backed implementation of the S3 metadata store.
 *
 * Uses PDO with prepared statements for all queries. Suitable for
 * single-node deployments. For multi-node HA, use the Postgres or
 * MySQL implementations (Phase 9).
 *
 * WAL mode is enabled for concurrent read performance. All writes
 * are serialized by SQLite's internal locking.
 */
final class SqliteMetadataStore implements FencedQueueStore, FencedStorageGarbageStore, FencedMultipartWriteIntentStore, StorageGarbageLeaseStore, MetadataStore, MetadataWriteReadinessProbe, SharedAuthorizationLockStore, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartPartWriteIntentInventoryStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, LocalMultipartFenceAdmissionStore, RecursiveCleanupAdmissionStore, MultipartUploadTierStore, MultipartWriteIntentStore, LocalMultipartCompletionIntentStore, ObjectWriteIntentStore, PagedMultipartPartsStore, MultipartPartChecksumStore, RestoredCopyTierStore, MultipartCompletionCapacityReader, WriteIntentCapacityReader, StorageCleanupCapacityReader, RecoveryStorageGarbageStore, ObjectPartLayoutStore
{
    private ?\PDO $pdo = null;

    /** Whether the current transaction acquired SQLite's writer lock eagerly. */
    private bool $immediateTransaction = false;

    /** Whether a deferred transaction has already prepared a metadata mutation. */
    private bool $deferredTransactionMutated = false;

    /** @var array<string, \PDOStatement> Cached prepared statements keyed by SQL. */
    private array $stmtCache = [];

    /** @var array<int, true> Layouts still being assembled in the active transaction. */
    private array $objectPartLayoutPublicationIds = [];

    /**
     * @param  string  $databasePath  Absolute path to the SQLite database file.
     *                                Use ':memory:' for in-memory databases (testing).
     */
    public function __construct(
        private readonly string $databasePath,
        private readonly bool $readOnly = false,
    ) {}

    public function isInMemory(): bool
    {
        return $this->databasePath === ':memory:';
    }

    /** Return the configured file without opening or migrating the database. */
    public function databaseFilePath(): ?string
    {
        return $this->isInMemory() ? null : $this->databasePath;
    }

    public function assertMetadataWritable(): void
    {
        $this->connection()->exec('UPDATE s3_buckets SET region = region WHERE 0');
    }

    // ===============================================================
    // Initialization
    // ===============================================================

    public function initialize(): void
    {
        if ($this->readOnly) {
            throw new \LogicException('Read-only SQLite metadata cannot run migrations.');
        }
        $this->ensureConnection();
        \assert($this->pdo instanceof \PDO);

        $schemaManager = new SchemaManager($this->pdo);
        $schemaManager->migrate();
        $schemaManager->assertMultipartCompletionCapacityConsistent();
        $schemaManager->assertWriteIntentCapacityConsistent();
        $schemaManager->assertStorageCleanupCapacityConsistent();
        $schemaManager->assertStorageCleanupTriggers();
    }

    public function assertSupportedBucketEncryptionInventory(): void
    {
        $this->ensureConnection();
        \assert($this->pdo instanceof \PDO);
        $schema = new \OpsFour\S3Server\Metadata\Schema\SchemaManager($this->pdo);
        // An unopened SQLite file contains no stored configuration. Preserve
        // the caller's earlier startup checks instead of treating the absent
        // schema as a contradictory encryption row.
        if ($schema->getCurrentVersion() === 0) {
            return;
        }
        $schema->assertSupportedBucketEncryptionInventory();
    }

    // ===============================================================
    // Bucket operations
    // ===============================================================

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $sql = 'INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES (?, ?, ?, ?)';

        try {
            $this->executeWithRetry($sql, [$bucket, $ownerId, $region, bin2hex(random_bytes(16))]);
        } catch (\PDOException $e) {
            unset($this->stmtCache[$sql]);

            // SQLSTATE[23000]: UNIQUE constraint violation.
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')
                || str_contains($e->getMessage(), '19')) {
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
            $stmt = $this->prepare('SELECT owner_id FROM s3_buckets WHERE name = ?');
            $stmt->execute([$bucket]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
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

            $this->prepare('DELETE FROM s3_parts WHERE upload_id IN (SELECT upload_id FROM s3_multipart_uploads WHERE bucket = ?)')->execute([$bucket]);
            $this->prepare('DELETE FROM s3_multipart_uploads WHERE bucket = ?')->execute([$bucket]);
            foreach ($bucketTables as $table) {
                $this->prepare("DELETE FROM {$table} WHERE bucket = ?")->execute([$bucket]);
            }
            $this->prepare(
                "DELETE FROM s3_acls
                 WHERE (resource_type = 'bucket' AND resource_name = ?)
                    OR (resource_type = 'object' AND resource_name LIKE ? ESCAPE '\\')",
            )->execute([$bucket, $this->escapeLikePattern($bucket . '/') . '%']);
            $this->prepare('DELETE FROM s3_buckets WHERE name = ? AND owner_id = ?')->execute([$bucket, $ownerId]);
        });
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        $stmt = $this->prepare(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE name = ?',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->rowToBucketInfo($row);
    }

    public function listBuckets(string $ownerId): array
    {
        $stmt = $this->prepare(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE owner_id = ? ORDER BY name ASC',
        );
        $stmt->execute([$ownerId]);

        $buckets = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function countBuckets(string $ownerId): int
    {
        $stmt = $this->prepare('SELECT COUNT(*) FROM s3_buckets WHERE owner_id = ?');
        $stmt->execute([$ownerId]);

        return (int) $stmt->fetchColumn();
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
            $sql .= ' AND name >= ? AND substr(name, 1, length(?)) = ?';
            $params[] = $prefix;
            $params[] = $prefix;
            $params[] = $prefix;
        }
        if ($region !== null) {
            $sql .= ' AND region = ?';
            $params[] = $region;
        }
        $sql .= ' ORDER BY name ASC LIMIT ' . ($pageSize + 1);
        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        $buckets = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (count($buckets) === $pageSize) {
                return ['buckets' => $buckets, 'nextMarker' => $buckets[$pageSize - 1]->name];
            }
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return ['buckets' => $buckets, 'nextMarker' => null];
    }

    public function listAllBuckets(): array
    {
        $pdo = $this->connection();

        $stmt = $pdo->query(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets ORDER BY name ASC',
        );
        if ($stmt === false) {
            return [];
        }

        $buckets = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function bucketExists(string $bucket): bool
    {
        $stmt = $this->prepare('SELECT 1 FROM s3_buckets WHERE name = ?');
        $stmt->execute([$bucket]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    public function getBucketOwner(string $bucket): ?string
    {
        $stmt = $this->prepare('SELECT owner_id FROM s3_buckets WHERE name = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row['owner_id'] : null;
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
        $now = gmdate('Y-m-d\TH:i:s\Z');
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
            // A suspended bucket may have a numbered latest version. Keep its
            // payload and make the replacement null version the only latest row.
            $demote = $this->prepare(
                'UPDATE s3_objects SET is_latest = 0, noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND is_latest = 1',
            );
            $demote->execute([$now, $bucket, $key]);

            // The old null version is replaced in place. Its version-scoped
            // state must not attach to the new object.
            $this->clearNullVersionMetadata($bucket, $key);

            $stmt = $this->prepare(
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
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                is_latest = 1,
                noncurrent_at = NULL,
                is_delete_marker = 0,
                owner_id = excluded.owner_id,
                etag = excluded.etag,
                size = excluded.size,
                content_type = excluded.content_type,
                content_encoding = excluded.content_encoding,
                content_disposition = excluded.content_disposition,
                cache_control = excluded.cache_control,
                storage_class = excluded.storage_class,
                storage_path = excluded.storage_path,
                storage_tier = 'STANDARD',
                transition_status = 'available',
                transition_target_tier = NULL,
                transition_error = NULL,
                restore_status = NULL,
                restored_storage_path = NULL,
                restored_storage_tier = NULL,
                restore_expires_at = NULL,
                user_metadata = excluded.user_metadata,
                checksum_crc32 = excluded.checksum_crc32,
                checksum_crc32c = excluded.checksum_crc32c,
                checksum_sha1 = excluded.checksum_sha1,
                checksum_sha256 = excluded.checksum_sha256,
                created_at = excluded.created_at,
                updated_at = excluded.updated_at
            SQL,
            );

            $stmt->execute([
                $bucket, $key,
                $ownerId, $etag, $size, $contentType, $contentEncoding,
                $contentDisposition, $cacheControl, $storageClass, $storagePath,
                $userMetadataJson, $checksumCrc32, $checksumCrc32c, $checksumSha1,
                $checksumSha256, $now, $now,
            ]);
            $this->authorizeObjectPartLayoutPublication($bucket, $key, 'null');
            $this->putObjectPartLayout($bucket, $key, 'null', ObjectPartLayout::knownSingle($size));
        });
    }

    private function clearNullVersionMetadata(string $bucket, string $key): void
    {
        $this->prepare("DELETE FROM s3_object_parts WHERE object_id IN (SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null')")
            ->execute([$bucket, $key]);
        $this->prepare("UPDATE s3_objects SET part_layout_kind = NULL, part_layout_count = NULL, part_layout_compat_count = NULL, part_layout_digest = NULL WHERE bucket = ? AND key_name = ? AND version_id = 'null'")
            ->execute([$bucket, $key]);
        foreach (['s3_object_retention', 's3_object_legal_holds'] as $table) {
            $this->prepare("DELETE FROM {$table} WHERE bucket = ? AND key_name = ? AND version_id = 'null'")
                ->execute([$bucket, $key]);
        }
        $this->prepare(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = 'null'",
        )->execute([$bucket, $key]);
        $this->prepare(
            'DELETE FROM s3_acls WHERE resource_type = ? AND resource_name IN (?, ?)',
        )->execute([
            'object',
            $bucket . '/' . $key,
            \OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName($bucket, $key, 'null'),
        ]);
        $this->prepare("DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null'")
            ->execute([$bucket, $key]);
    }

    public function getObjectMetadata(string $bucket, string $key): ?ObjectInfo
    {
        $stmt = $this->prepare(
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
            WHERE bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0
            SQL,
        );
        $stmt->execute([$bucket, $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->rowToObjectInfo($row);
    }

    public function getCurrentObjectVersion(string $bucket, string $key): ?ObjectInfo
    {
        $stmt = $this->prepare(
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
            WHERE bucket = ? AND key_name = ? AND is_latest = 1
            SQL,
        );
        $stmt->execute([$bucket, $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->rowToObjectInfo($row);
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        // For non-versioned buckets (version_id = 'null'), simply delete the row.
        // For versioned buckets (Phase 5), this will insert a delete marker instead.
        $stmt = $this->prepare(
            "DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = 'null'",
        );
        $stmt->execute([$bucket, $key]);
    }

    public function objectExists(string $bucket, string $key): bool
    {
        $stmt = $this->prepare(
            'SELECT 1 FROM s3_objects WHERE bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0',
        );
        $stmt->execute([$bucket, $key]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    public function supportsObjectPartLayouts(): bool
    {
        return true;
    }

    private function authorizeObjectPartLayoutPublication(string $bucket, string $key, string $versionId): void
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('Object part layout publication authority requires an active transaction.');
        }
        $statement = $this->prepare('SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?');
        $statement->execute([$bucket, $key, $versionId]);
        $objectId = $statement->fetchColumn();
        if (! is_int($objectId)) {
            throw new \RuntimeException('New object incarnation cannot be resolved for its part layout.');
        }
        $this->objectPartLayoutPublicationIds[$objectId] = true;
    }

    public function putObjectPartLayout(string $bucket, string $key, string $versionId, ObjectPartLayout $layout): void
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('Object part layouts must be published inside the object metadata transaction.');
        }
        $statement = $this->prepare('SELECT id, size, is_delete_marker FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?');
        $statement->execute([$bucket, $key, $versionId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['is_delete_marker'] === 1 || (int) $row['size'] !== $layout->objectSize) {
            throw new \RuntimeException('Object part layout does not match a concrete object row.');
        }
        $objectId = (int) $row['id'];
        if (! isset($this->objectPartLayoutPublicationIds[$objectId])) {
            throw new \LogicException('Published object part layouts are immutable.');
        }
        $this->prepare('DELETE FROM s3_object_parts WHERE object_id = ?')->execute([$objectId]);
        $summary = $layout->summary($objectId);
        $this->prepare('UPDATE s3_objects SET part_layout_kind = ?, part_layout_count = ?, part_layout_compat_count = ?, part_layout_digest = ? WHERE id = ?')
            ->execute([$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $objectId]);
        $insert = $this->prepare('INSERT INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($layout->parts as $part) {
            $insert->execute([$objectId, $part->ordinal, $part->uploadPartNumber, $part->offset, $part->size, $part->checksumAlgorithm, $part->checksumValue]);
        }
    }

    public function getObjectPartLayout(ObjectInfo $object): ObjectPartLayout
    {
        $expected = $object->partLayout;
        if ($object->isDeleteMarker || $expected === null) {
            throw new \RuntimeException('Object metadata has no authoritative part layout.');
        }
        $statement = $this->prepare('SELECT id, size, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?');
        $statement->execute([$object->bucket, $object->key, $object->versionId ?? 'null']);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        if ($row['part_layout_count'] === null || $row['part_layout_digest'] === null) {
            throw new \RuntimeException('Stored object part layout summary is incomplete.');
        }
        if (! hash_equals($expected->digest, (string) $row['part_layout_digest'])) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        $partsStatement = $this->prepare('SELECT ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value FROM s3_object_parts WHERE object_id = ? ORDER BY ordinal');
        $partsStatement->execute([(int) $row['id']]);
        $parts = [];
        foreach ($partsStatement->fetchAll(\PDO::FETCH_ASSOC) as $part) {
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

        $stmt = $this->prepare(<<<SQL
            UPDATE s3_objects
            SET storage_class = ?,
                storage_tier = ?,
                storage_path = ?,
                transition_status = ?,
                transition_target_tier = ?,
                transition_error = ?
            WHERE {$where}
            SQL);
        $stmt->execute($params);
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
            $restoreExpiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $bucket,
            $key,
        ];
        if ($versionId !== null) {
            $params[] = $versionId;
        }

        $stmt = $this->prepare(<<<SQL
            UPDATE s3_objects
            SET restore_status = ?,
                restored_storage_path = ?,
                restored_storage_tier = ?,
                restore_expires_at = ?
            WHERE {$where}
            SQL);
        $stmt->execute($params);
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
        $stmt = $this->prepare(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'objectCount' => $row !== false ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== false ? (int) $row['total_size'] : 0,
        ];
    }

    public function getBucketStorageStats(string $bucket): array
    {
        $stmt = $this->prepare(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = ? AND is_delete_marker = 0',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'objectCount' => $row !== false ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== false ? (int) $row['total_size'] : 0,
        ];
    }

    public function getOwnerStorageStats(string $ownerId): array
    {
        $stmt = $this->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(size), 0) AS total_size FROM s3_objects WHERE owner_id = ? AND is_delete_marker = 0',
        );
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'objectCount' => $row !== false ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== false ? (int) $row['total_size'] : 0,
        ];
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        $stmt = $this->prepare(
            'SELECT * FROM s3_account_quotas WHERE owner_id = ?',
        );
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
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
        $stmt = $this->prepare(
            'SELECT * FROM s3_account_quotas ORDER BY owner_id ASC',
        );
        $stmt->execute();

        $quotas = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
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

        $stmt = $this->prepare($after === null
            ? 'SELECT * FROM s3_account_quotas ORDER BY owner_id ASC LIMIT ?'
            : 'SELECT * FROM s3_account_quotas WHERE owner_id > ? ORDER BY owner_id ASC LIMIT ?');
        if ($after !== null) {
            $stmt->bindValue(1, $after, \PDO::PARAM_STR);
        }
        $stmt->bindValue($after === null ? 1 : 2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $page = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
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
        $stmt = $this->prepare(
            'INSERT INTO s3_account_quotas (owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner, max_multipart_uploads_per_bucket, max_multipart_uploads_per_owner, max_multipart_bytes_per_bucket, max_multipart_bytes_per_owner, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
             ON CONFLICT(owner_id) DO UPDATE SET
                max_buckets_per_owner = excluded.max_buckets_per_owner,
                max_objects_per_bucket = excluded.max_objects_per_bucket,
                max_bytes_per_bucket = excluded.max_bytes_per_bucket,
                max_bytes_per_owner = excluded.max_bytes_per_owner,
                max_multipart_uploads_per_bucket = excluded.max_multipart_uploads_per_bucket,
                max_multipart_uploads_per_owner = excluded.max_multipart_uploads_per_owner,
                max_multipart_bytes_per_bucket = excluded.max_multipart_bytes_per_bucket,
                max_multipart_bytes_per_owner = excluded.max_multipart_bytes_per_owner,
                updated_at = excluded.updated_at',
        );
        $stmt->execute([
            $ownerId,
            $quota->maxBucketsPerOwner,
            $quota->maxObjectsPerBucket,
            $quota->maxBytesPerBucket,
            $quota->maxBytesPerOwner,
            $quota->maxMultipartUploadsPerBucket,
            $quota->maxMultipartUploadsPerOwner,
            $quota->maxMultipartBytesPerBucket,
            $quota->maxMultipartBytesPerOwner,
        ]);
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $stmt = $this->prepare('DELETE FROM s3_account_quotas WHERE owner_id = ?');
        $stmt->execute([$ownerId]);
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        $stmt = $this->prepare('SELECT policy_json FROM s3_account_policies WHERE owner_id = ?');
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (string) $row['policy_json'] : null;
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        PolicyDocumentValidator::validateIdentityPolicy($policyJson);
        $this->transaction(function () use ($ownerId, $policyJson): void {
            $this->lockOwnerForUpdate($ownerId);
            $stmt = $this->prepare(<<<'SQL'
                INSERT INTO s3_account_policies (owner_id, policy_json)
                VALUES (?, ?)
                ON CONFLICT(owner_id) DO UPDATE SET
                    policy_json = excluded.policy_json,
                    updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                SQL);
            $stmt->execute([$ownerId, $policyJson]);
        });
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->transaction(function () use ($ownerId): void {
            $this->lockOwnerForUpdate($ownerId);
            $stmt = $this->prepare('DELETE FROM s3_account_policies WHERE owner_id = ?');
            $stmt->execute([$ownerId]);
        });
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $stmt = $this->prepare('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?');
        $stmt->execute([$policyName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (string) $row['policy_json'] : null;
    }

    public function lockNamedPoliciesForUpdate(array $policyNames): array
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('lockNamedPoliciesForUpdate() requires an active transaction.');
        }
        $policyNames = array_values(array_unique(array_filter($policyNames, static fn(string $name): bool => $name !== '')));
        sort($policyNames, SORT_STRING);
        $lock = $this->prepare('UPDATE s3_named_policies SET policy_json = policy_json WHERE policy_name = ?');
        $read = $this->prepare('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?');
        $policies = [];
        foreach ($policyNames as $name) {
            $lock->execute([$name]);
            $read->execute([$name]);
            $row = $read->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                $policies[$name] = (string) $row['policy_json'];
            }
        }

        return $policies;
    }

    public function lockNamedPoliciesForRead(array $policyNames): array
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('lockNamedPoliciesForRead() requires an active transaction.');
        }
        $policyNames = array_values(array_unique(array_filter($policyNames, static fn(string $name): bool => $name !== '')));
        sort($policyNames, SORT_STRING);
        $read = $this->prepare('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?');
        $policies = [];
        foreach ($policyNames as $name) {
            $read->execute([$name]);
            $row = $read->fetch(\PDO::FETCH_ASSOC);
            if ($row !== false) {
                $policies[$name] = (string) $row['policy_json'];
            }
        }

        return $policies;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        PolicyDocumentValidator::validateIdentityPolicy($policyJson);
        $stmt = $this->prepare(<<<'SQL'
            INSERT INTO s3_named_policies (policy_name, policy_json)
            VALUES (?, ?)
            ON CONFLICT(policy_name) DO UPDATE SET
                policy_json = excluded.policy_json,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL);
        $stmt->execute([$policyName, $policyJson]);
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $stmt = $this->prepare('DELETE FROM s3_named_policies WHERE policy_name = ?');
        $stmt->execute([$policyName]);
    }

    public function countObjects(string $bucket): int
    {
        $stmt = $this->prepare(
            'SELECT COUNT(*) as cnt FROM s3_objects WHERE bucket = ?',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (int) $row['cnt'] : 0;
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
        $pdo = $this->connection();

        // The token is the canonical base64 encoding of the last key on the preceding page.
        $effectiveStartAfter = $continuationToken === null
            ? $startAfter
            : \OpsFour\S3Server\Http\ObjectContinuationToken::decode($continuationToken);

        // Clamp maxKeys to valid range.
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

        // Build the base query; delimiter listings fetch sorted chunks as needed.
        $sql = 'SELECT bucket, key_name, version_id, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_tier, transition_status, transition_target_tier, transition_error, '
            . 'restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, noncurrent_at, updated_at '
            . 'FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
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

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
                objects: array_values($objects),
                commonPrefixes: [],
                startAfter: $startAfter,
                continuationToken: $continuationToken,
                nextContinuationToken: $nextContinuationToken,
            );
        }

        return DelimitedObjectListing::page(
            fetchRows: static function (?string $cursor, int $limit, ?string $afterPrefix) use ($pdo, $sql, $params): array {
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

                $stmt = $pdo->prepare($pageSql);
                $stmt->execute($pageParams);

                return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
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
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_multipart_uploads (upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
        );

        $stmt->execute([
            $uploadId,
            $bucket,
            $bucketInfo->generation,
            $key,
            $ownerId,
            $storageTier,
            $contentType ?? 'application/octet-stream',
            json_encode($userMetadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        self::validateObjectWriteIntent($intent);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write admission requires its own transaction.');
        }

        $retries = 0;
        while (true) {
            $callbackCompleted = false;
            try {
                return $this->transaction(function () use ($intent, $leaseSeconds, &$callbackCompleted): ObjectWriteIntent {
                    $globalCount = $this->prepare("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object'");
                    $globalCount->execute();
                    $global = $globalCount->fetchColumn();
                    if ($global === false || (int) $global >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL) {
                        throw new SlowDownException('Physical write recovery capacity is exhausted.');
                    }

                    $ownerCount = $this->prepare('SELECT COUNT(*) FROM s3_object_write_intents WHERE owner_id = ?');
                    $ownerCount->execute([$intent->ownerId]);
                    if ((int) $ownerCount->fetchColumn() >= ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER) {
                        throw new SlowDownException('Physical write recovery capacity is exhausted.');
                    }

                    $stmt = $this->prepare(<<<'SQL'
                        INSERT INTO s3_object_write_intents
                            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                             path_digest, purpose, state, lease_until, dispatch_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active',
                            (julianday('now') - 2440587.5) * 86400.0 + ?, NULL)
                    SQL);
                    $stmt->execute([
                        $intent->attemptId, $intent->bucket, $intent->bucketGeneration, $intent->ownerId,
                        $intent->storageTier, $intent->storagePath, hash('sha256', $intent->storagePath),
                        $intent->purpose, $leaseSeconds,
                    ]);

                    $reserved = $this->getObjectWriteIntent($intent->attemptId)
                        ?? throw new \RuntimeException('Reserved physical write intent disappeared.');
                    $callbackCompleted = true;

                    return $reserved;
                });
            } catch (\PDOException $e) {
                // WAL read snapshots cannot be promoted after a concurrent commit.
                // Retry only the metadata admission after its transaction rolled back;
                // a COMMIT error may have succeeded and must never be retried.
                // The transaction can throw while committing after the callback
                // has returned and changed this by-reference safety flag.
                if ($callbackCompleted || ! self::isSqliteBusy($e) || $retries >= 5) {
                    throw $e;
                }

                usleep(50_000 * (2 ** $retries));
                $retries++;
            }
        }
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        $stmt = $this->prepare('SELECT * FROM s3_object_write_intents WHERE attempt_id = ?');
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::objectWriteIntentFromRow($row);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        return $this->transaction(function () use ($attemptId, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $stmt = $this->prepare(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = (julianday('now') - 2440587.5) * 86400.0 + ?
                WHERE attempt_id = ? AND state = 'active' AND lease_until = CAST(? AS REAL)
                  AND lease_until > (julianday('now') - 2440587.5) * 86400.0
                  AND (julianday('now') - 2440587.5) * 86400.0 + ? > lease_until
            SQL);
            $stmt->execute([$leaseSeconds, $attemptId, sprintf('%.17F', $expectedLeaseUntil), $leaseSeconds]);

            return $stmt->rowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteDispatchId($dispatchId);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write dispatch requires its own committed transaction.');
        }

        return $this->transaction(function () use ($attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds): ?ObjectWriteIntent {
            $stmt = $this->prepare(<<<'SQL'
                UPDATE s3_object_write_intents
                SET dispatch_id = ?, lease_until = MAX(lease_until, (julianday('now') - 2440587.5) * 86400.0 + ?)
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL
                  AND lease_until = CAST(? AS REAL)
                  AND lease_until > (julianday('now') - 2440587.5) * 86400.0
                SQL);
            $stmt->execute([$dispatchId, $leaseSeconds, $attemptId, sprintf('%.17F', $expectedLeaseUntil)]);

            return $stmt->rowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
    }

    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteDispatchId($dispatchId);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write lease refresh requires its own committed transaction.');
        }

        return $this->transaction(function () use ($attemptId, $dispatchId, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $stmt = $this->prepare(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = MAX(lease_until, (julianday('now') - 2440587.5) * 86400.0 + ?)
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
                  AND lease_until = CAST(? AS REAL)
                  AND lease_until > (julianday('now') - 2440587.5) * 86400.0
                SQL);
            $stmt->execute([$leaseSeconds, $attemptId, $dispatchId, sprintf('%.17F', $expectedLeaseUntil)]);
            if ($stmt->rowCount() === 1) {
                return $this->getObjectWriteIntent($attemptId);
            }

            $unchanged = $this->prepare(<<<'SQL'
                SELECT * FROM s3_object_write_intents
                WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
                  AND lease_until = CAST(? AS REAL)
                  AND lease_until > (julianday('now') - 2440587.5) * 86400.0
                SQL);
            $unchanged->execute([$attemptId, $dispatchId, sprintf('%.17F', $expectedLeaseUntil)]);
            $row = $unchanged->fetch(\PDO::FETCH_ASSOC);

            return $row === false ? null : self::objectWriteIntentFromRow($row);
        });
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $stmt = $this->prepare("DELETE FROM s3_object_write_intents WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL");
        $stmt->execute([$attemptId]);

        return $stmt->rowCount() === 1;
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $stmt = $this->prepare("DELETE FROM s3_object_write_intents WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?");
        $stmt->execute([$attemptId, $dispatchId]);

        return $stmt->rowCount() === 1;
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        $stmt = $this->prepare(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = ? AND state = 'active'
              AND (dispatch_id IS NULL OR dispatch_id = '00000000000000000000000000000000')
              AND lease_until > (julianday('now') - 2440587.5) * 86400.0
        SQL);
        $stmt->execute([$attemptId]);

        return $stmt->rowCount() === 1;
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        $stmt = $this->prepare(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
              AND lease_until > (julianday('now') - 2440587.5) * 86400.0
        SQL);
        $stmt->execute([$attemptId, $dispatchId]);

        return $stmt->rowCount() === 1;
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        $stmt = $this->prepare(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = (julianday('now') - 2440587.5) * 86400.0
            WHERE attempt_id = ? AND state = 'active'
        SQL);
        $stmt->execute([$attemptId]);

        return $stmt->rowCount() === 1;
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
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write claims require their own transaction.');
        }

        return $this->transaction(function () use ($storageTier, $limit, $leaseSeconds): array {
            $stmt = $this->prepare(<<<'SQL'
                SELECT attempt_id, claim_generation FROM s3_object_write_intents
                WHERE storage_tier = ? AND state IN ('active', 'reconciling')
                  AND dispatch_id IS NOT NULL
                  AND lease_until <= (julianday('now') - 2440587.5) * 86400.0
                ORDER BY lease_until, attempt_id LIMIT ?
            SQL);
            $stmt->execute([$storageTier, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $claimed = [];
            foreach ($rows as $row) {
                $update = $this->prepare(<<<'SQL'
                    UPDATE s3_object_write_intents
                    SET state = 'reconciling', claim_generation = claim_generation + 1,
                        lease_until = (julianday('now') - 2440587.5) * 86400.0 + ?
                    WHERE attempt_id = ? AND storage_tier = ? AND claim_generation = ?
                      AND state IN ('active', 'reconciling') AND dispatch_id IS NOT NULL
                      AND lease_until <= (julianday('now') - 2440587.5) * 86400.0
                SQL);
                $update->execute([$leaseSeconds, $row['attempt_id'], $storageTier, $row['claim_generation']]);
                if ($update->rowCount() !== 1) {
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
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $stmt = $this->prepare(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id IN (
                SELECT attempt_id FROM s3_object_write_intents
                WHERE storage_tier = ? AND state = 'active' AND dispatch_id IS NULL
                  AND lease_until <= (julianday('now') - 2440587.5) * 86400.0
                ORDER BY lease_until, attempt_id LIMIT ?
            ) AND state = 'active' AND dispatch_id IS NULL
        SQL);
        $stmt->execute([$storageTier, $limit]);

        return $stmt->rowCount();
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        $stmt = $this->prepare(<<<'SQL'
            UPDATE s3_object_write_intents SET state = 'sealed'
            WHERE attempt_id = ? AND state = 'reconciling' AND claim_generation = ?
              AND lease_until > (julianday('now') - 2440587.5) * 86400.0
        SQL);
        $stmt->execute([$attemptId, $claimGeneration]);

        return $stmt->rowCount() === 1;
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        return $this->transaction(function () use ($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds): ?ObjectWriteIntent {
            $stmt = $this->prepare(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = (julianday('now') - 2440587.5) * 86400.0 + ?
                WHERE attempt_id = ? AND state = 'reconciling' AND claim_generation = ?
                  AND lease_until = CAST(? AS REAL)
                  AND lease_until > (julianday('now') - 2440587.5) * 86400.0
                  AND (julianday('now') - 2440587.5) * 86400.0 + ? > lease_until
            SQL);
            $stmt->execute([$leaseSeconds, $attemptId, $claimGeneration, sprintf('%.17F', $expectedLeaseUntil), $leaseSeconds]);

            return $stmt->rowCount() === 1 ? $this->getObjectWriteIntent($attemptId) : null;
        });
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
        );
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
            || $intent->dispatchId !== null) {
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
            $upload = $this->prepare('SELECT 1 FROM s3_multipart_uploads WHERE upload_id = ? AND bucket = ? AND bucket_generation = ? AND owner_id = ? AND storage_tier = ?');
            $upload->execute([$intent->uploadId, $intent->bucket, $intent->bucketGeneration, $intent->ownerId, $intent->storageTier]);
            if ($upload->fetchColumn() === false) {
                throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
            }
            $count = $this->prepare('SELECT COUNT(*) FROM s3_multipart_write_intents WHERE owner_id = ?');
            $count->execute([$intent->ownerId]);
            if ((int) $count->fetchColumn() >= MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart completion recovery capacity is exhausted.');
            }
            $stmt = $this->prepare(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, active_upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, sse_mode, state, lease_until, next_attempt_at, dispatch_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NULL)
        SQL);
            try {
                $stmt->execute([
                    $intent->attemptId, $intent->uploadId, $intent->uploadId, $intent->bucket,
                    $intent->bucketGeneration, $intent->ownerId, $intent->storageTier,
                    $intent->assemblyPath, $intent->finalPath, $intent->partDigest,
                    $intent->sseMode, sprintf('%.17F', $intent->leaseUntil), sprintf('%.17F', $intent->nextAttemptAt),
                ]);
            } catch (\PDOException $error) {
                if (str_contains($error->getMessage(), 'Multipart completion recovery capacity is exhausted.')) {
                    throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart completion recovery capacity is exhausted.');
                }

                throw $error;
            }
        });
    }

    public function multipartWriteIntentNow(): float
    {
        $stmt = $this->prepare("SELECT (julianday('now') - 2440587.5) * 86400.0");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        if (! is_numeric($value)) {
            throw new \RuntimeException('Metadata database clock is unavailable.');
        }

        return (float) $value;
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        $stmt = $this->prepare('SELECT * FROM s3_multipart_write_intents WHERE attempt_id = ?');
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::multipartWriteIntentFromRow($row);
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents
            SET lease_until = ?, next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'active' AND lease_until = CAST(? AS REAL)
            AND lease_until > (julianday('now') - 2440587.5) * 86400.0 AND ? > lease_until");
        $newLease = sprintf('%.17F', $newLeaseUntil);
        $stmt->execute([$newLease, $newLease, $attemptId, sprintf('%.17F', $expectedLeaseUntil), $newLease]);

        return $stmt->rowCount() === 1;
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents SET state = 'abandoned', active_upload_id = NULL,
            next_attempt_at = ? WHERE attempt_id = ? AND state = 'active'");
        $stmt->execute([$nextAttemptAt, $attemptId]);

        return $stmt->rowCount() === 1;
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents SET state = 'abandoned', active_upload_id = NULL,
            next_attempt_at = ? WHERE upload_id = ? AND state = 'active'");
        $stmt->execute([$nextAttemptAt, $uploadId]);

        return $stmt->rowCount();
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        $stmt = $this->prepare("DELETE FROM s3_multipart_write_intents
            WHERE attempt_id = ? AND state = 'active'
            AND dispatch_id IS NULL
            AND lease_until > (julianday('now') - 2440587.5) * 86400.0");
        $stmt->execute([$attemptId]);

        return $stmt->rowCount() === 1;
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart completion dispatch ID.');
        }
        $statement = $this->prepare("UPDATE s3_multipart_write_intents
            SET dispatch_id = ?, lease_until = ?, next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'active' AND dispatch_id IS NULL AND lease_until = CAST(? AS REAL)
              AND lease_until > (julianday('now') - 2440587.5) * 86400.0 AND ? > lease_until");
        $newLease = sprintf('%.17F', $newLeaseUntil);
        $statement->execute([$dispatchId, $newLease, $newLease, $attemptId, sprintf('%.17F', $expectedLeaseUntil), $newLease]);

        return $statement->rowCount() === 1;
    }

    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart completion dispatch ID.');
        }
        $statement = $this->prepare("DELETE FROM s3_multipart_write_intents
            WHERE attempt_id = ? AND state = 'active' AND (dispatch_id IS NULL OR dispatch_id = ?)");
        $statement->execute([$attemptId, $dispatchId]);

        return $statement->rowCount() === 1;
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        $statement = $this->prepare("DELETE FROM s3_multipart_write_intents WHERE attempt_id IN (
            SELECT attempt_id FROM s3_multipart_write_intents
            WHERE storage_tier = ? AND dispatch_id IS NULL
              AND ((state = 'active' AND lease_until <= (julianday('now') - 2440587.5) * 86400.0)
                OR (state = 'abandoned' AND next_attempt_at <= (julianday('now') - 2440587.5) * 86400.0))
            ORDER BY next_attempt_at, attempt_id LIMIT ?)");
        $statement->execute([$storageTier, max(1, $limit)]);

        return $statement->rowCount();
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $statement = $this->prepare("DELETE FROM s3_multipart_write_intents
            WHERE attempt_id = ? AND state = 'active' AND dispatch_id = ?
              AND lease_until > (julianday('now') - 2440587.5) * 86400.0");
        $statement->execute([$attemptId, $dispatchId]);

        return $statement->rowCount() === 1;
    }

    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        return $this->transaction(function () use ($limit, $now, $leaseUntil): array {
            $stmt = $this->prepare("SELECT * FROM s3_multipart_write_intents
                WHERE (state = 'active' AND lease_until <= ?)
                   OR (state = 'abandoned' AND next_attempt_at <= ?)
                ORDER BY next_attempt_at, attempt_id LIMIT ?");
            $stmt->execute([$now, $now, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $claimed = [];
            foreach ($rows as $row) {
                $update = $this->prepare("UPDATE s3_multipart_write_intents
                    SET state = 'abandoned', active_upload_id = NULL, next_attempt_at = ?, claim_generation = claim_generation + 1
                    WHERE attempt_id = ?");
                $update->execute([sprintf('%.17F', $leaseUntil), $row['attempt_id']]);
                $row['state'] = 'abandoned';
                $row['next_attempt_at'] = $leaseUntil;
                $row['claim_generation'] = (int) $row['claim_generation'] + 1;
                $claimed[] = self::multipartWriteIntentFromRow($row);
            }

            return $claimed;
        });
    }

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents SET next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ?");
        $stmt->execute([$nextAttemptAt, $attemptId, sprintf('%.17F', $expectedLeaseUntil)]);

        return $stmt->rowCount() === 1;
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents SET next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ? AND claim_generation = ?
              AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0 AND ? > next_attempt_at");
        $stmt->execute([sprintf('%.17F', $newLeaseUntil), $attemptId, sprintf('%.17F', $expectedLeaseUntil), $claimGeneration, $newLeaseUntil]);

        return $stmt->rowCount() === 1;
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $stmt = $this->prepare("UPDATE s3_multipart_write_intents SET next_attempt_at = ?
            WHERE attempt_id = ? AND state = 'abandoned' AND next_attempt_at = ? AND claim_generation = ?
              AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0");
        $stmt->execute([sprintf('%.17F', $nextAttemptAt), $attemptId, sprintf('%.17F', $expectedLeaseUntil), $claimGeneration]);

        return $stmt->rowCount() === 1;
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
        $stmt = $this->prepare(
            'SELECT upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata, created_at '
            . 'FROM s3_multipart_uploads WHERE upload_id = ?',
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        /** @var array<string, string> $userMetadata */
        $userMetadata = json_decode((string) $row['user_metadata'], true, 512, JSON_THROW_ON_ERROR);

        return [
            'upload_id' => (string) $row['upload_id'],
            'bucket' => (string) $row['bucket'],
            'bucket_generation' => (string) $row['bucket_generation'],
            'key_name' => (string) $row['key_name'],
            'owner_id' => (string) $row['owner_id'],
            'storage_tier' => $row['storage_tier'] === null ? null : (string) $row['storage_tier'],
            'content_type' => (string) $row['content_type'],
            'user_metadata' => $userMetadata,
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        // Delete parts first (FK constraint with ON DELETE CASCADE handles this,
        // but we're explicit for clarity and portability).
        $this->deleteParts($uploadId);

        $stmt = $this->prepare('DELETE FROM s3_multipart_uploads WHERE upload_id = ?');
        $stmt->execute([$uploadId]);
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

        $pdo = $this->connection();

        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at '
            . 'FROM s3_multipart_uploads WHERE bucket = ?';
        $params = [$bucket];
        if ($observedBucket !== null) {
            // Legacy rows are safe only because deleteBucket removes every upload
            // in the same transaction before this bucket name can be reused.
            $sql .= " AND (bucket_generation = ? OR (bucket_generation = '' AND owner_id = ?))";
            $params[] = $observedBucket->generation;
            $params[] = $observedBucket->ownerId;
        }

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        // S3 orders uploads with the same key by initiation time. The upload ID
        // is only a stable tie-breaker for uploads created at the same instant.
        if ($keyMarker !== null && $keyMarker !== '') {
            if ($uploadIdMarker !== null && $uploadIdMarker !== '') {
                $markerSql = 'SELECT created_at FROM s3_multipart_uploads '
                    . 'WHERE bucket = ? AND key_name = ? COLLATE BINARY AND upload_id = ? COLLATE BINARY';
                $markerParams = [$bucket, $keyMarker, $uploadIdMarker];
                if ($observedBucket !== null) {
                    $markerSql .= " AND (bucket_generation = ? OR (bucket_generation = '' AND owner_id = ?))";
                    $markerParams[] = $observedBucket->generation;
                    $markerParams[] = $observedBucket->ownerId;
                }
                $markerStmt = $this->prepare($markerSql . ' LIMIT 1');
                $markerStmt->execute($markerParams);
                $markerCreatedAt = $markerStmt->fetchColumn();
                if (is_string($markerCreatedAt)) {
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND (created_at > ? OR (created_at = ? AND upload_id > ?))))';
                    array_push($params, $keyMarker, $keyMarker, $markerCreatedAt, $markerCreatedAt, $uploadIdMarker);
                } else {
                    // An arbitrary marker need not identify a currently live
                    // upload. S3 still includes same-key uploads whose IDs
                    // sort after the supplied upload-id-marker.
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND upload_id > ?))';
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
                fetchRows: static function (?array $cursor, int $limit, ?string $afterPrefix) use ($pdo, $sql, $params): array {
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
                        $query .= ' AND (key_name > ? OR (key_name = ? AND (created_at > ? OR (created_at = ? AND upload_id > ?))))';
                        array_push($queryParams, $cursor['key'], $cursor['key'], $cursor['createdAt'], $cursor['createdAt'], $cursor['uploadId']);
                    }
                    $query .= ' ORDER BY key_name ASC, created_at ASC, upload_id ASC LIMIT ?';
                    $queryParams[] = $limit;
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($queryParams);

                    return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
                },
                prefix: $prefix ?? '',
                delimiter: $delimiter,
                maxUploads: $effectiveMax,
                keyMarker: $keyMarker,
            );
        }
        $sql .= ' ORDER BY key_name ASC, created_at ASC, upload_id ASC LIMIT ?';
        $params[] = $effectiveMax + 1; // Fetch one extra to detect truncation.

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
                LEFT JOIN s3_parts p ON p.upload_id = u.upload_id
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
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'uploadCount' => $row !== false ? (int) $row['upload_count'] : 0,
            'bytesUsed' => $row !== false ? (int) $row['bytes_used'] : 0,
        ];
    }

    public function putPart(
        string $uploadId,
        int $partNumber,
        string $etag,
        int $size,
        string $storagePath,
    ): void {
        $this->putPartWithChecksum($uploadId, $partNumber, $etag, $size, $storagePath, null, null);
    }

    public function supportsMultipartPartChecksums(): bool
    {
        return true;
    }

    public function putPartWithChecksum(
        string $uploadId,
        int $partNumber,
        string $etag,
        int $size,
        string $storagePath,
        ?string $checksumAlgorithm,
        ?string $checksumValue,
    ): void {
        // Verify the multipart upload exists.
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null) {
            throw new NoSuchUploadException(
                'The specified upload does not exist.',
            );
        }

        // INSERT OR REPLACE: uploading the same part number again replaces it.
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path, checksum_algorithm, checksum_value)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(upload_id, part_number) DO UPDATE SET
                etag = excluded.etag,
                size = excluded.size,
                storage_path = excluded.storage_path,
                checksum_algorithm = excluded.checksum_algorithm,
                checksum_value = excluded.checksum_value,
                created_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue]);
    }

    public function getParts(string $uploadId): array
    {
        $stmt = $this->prepare(
            'SELECT part_number, etag, size, storage_path, checksum_algorithm, checksum_value, created_at '
            . 'FROM s3_parts WHERE upload_id = ? ORDER BY part_number ASC',
        );
        $stmt->execute([$uploadId]);

        $parts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Cast numeric fields to proper types.
        /** @var list<array{part_number: int, etag: string, size: int, storage_path: string, created_at: string}> */
        return array_map(static function (array $part): array {
            $part['part_number'] = (int) $part['part_number'];
            $part['size'] = (int) $part['size'];

            return $part;
        }, $parts);
    }

    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array
    {
        if ($limit < 1 || $limit > 1001) {
            throw new \InvalidArgumentException('Multipart part page limit must be between 1 and 1001.');
        }

        $stmt = $this->prepare(
            'SELECT part_number, etag, size, storage_path, checksum_algorithm, checksum_value, created_at '
            . 'FROM s3_parts WHERE upload_id = ? AND part_number > ? '
            . 'ORDER BY part_number ASC LIMIT ?',
        );
        $stmt->bindValue(1, $uploadId, \PDO::PARAM_STR);
        $stmt->bindValue(2, $afterPartNumber, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array{part_number: int|string, etag: string, size: int|string, storage_path: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $row['part_number'] = (int) $row['part_number'];
            $row['size'] = (int) $row['size'];

            return $row;
        }, $rows);
    }

    public function deleteParts(string $uploadId): void
    {
        $stmt = $this->prepare('DELETE FROM s3_parts WHERE upload_id = ?');
        $stmt->execute([$uploadId]);
    }

    // ===============================================================
    // Versioning
    // ===============================================================

    public function getBucketVersioning(string $bucket): string
    {
        $stmt = $this->prepare('SELECT versioning FROM s3_buckets WHERE name = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new NoSuchBucketException(
                'The specified bucket does not exist.',
            );
        }

        return $row['versioning'];
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        if (! in_array($status, ['Enabled', 'Suspended'], true)) {
            throw new \InvalidArgumentException(
                "Versioning status must be 'Enabled' or 'Suspended', got '{$status}'.",
            );
        }

        // First verify the bucket exists (rowCount=0 on no-op UPDATE is indistinguishable from missing bucket).
        $check = $this->prepare('SELECT 1 FROM s3_buckets WHERE name = ?');
        $check->execute([$bucket]);
        if ($check->fetch() === false) {
            throw new NoSuchBucketException(
                'The specified bucket does not exist.',
            );
        }

        $stmt = $this->prepare('UPDATE s3_buckets SET versioning = ? WHERE name = ?');
        $stmt->execute([$status, $bucket]);
    }

    // ===============================================================
    // Versioning-aware object operations
    // ===============================================================

    /**
     * @param  array<string, string>  $userMetadata
     */
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
        $pdo = $this->connection();
        $versionId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $userMetadataJson = json_encode($userMetadata, JSON_THROW_ON_ERROR);

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            // Mark all previous versions of the same key as non-latest.
            $stmt = $this->prepare(
                'UPDATE s3_objects SET is_latest = 0, noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND is_latest = 1',
            );
            $stmt->execute([$now, $bucket, $key]);

            // Insert the new version as latest.
            $stmt = $this->prepare(
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
            );

            $stmt->execute([
                $bucket, $key, $versionId,
                $ownerId, $etag, $size, $contentType, $contentEncoding,
                $contentDisposition, $cacheControl, $storageClass, $storagePath,
                $userMetadataJson, $checksumCrc32, $checksumCrc32c, $checksumSha1,
                $checksumSha256, $now, $now,
            ]);
            $this->authorizeObjectPartLayoutPublication($bucket, $key, $versionId);
            $this->putObjectPartLayout($bucket, $key, $versionId, ObjectPartLayout::knownSingle($size));

            if ($ownTx) {
                try {
                    $pdo->commit();
                } finally {
                    $this->objectPartLayoutPublicationIds = [];
                }
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    $pdo->rollBack();
                } finally {
                    $this->objectPartLayoutPublicationIds = [];
                }
            }
            throw $e;
        }

        return $versionId;
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        $pdo = $this->connection();
        $versionId = $suspended ? 'null' : bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            // Mark all previous versions as non-latest (both enabled and suspended).
            // Under suspended versioning, the delete marker with version_id='null' must become
            // the sole is_latest=1 row — any previously-latest versioned row must be demoted.
            $stmt = $this->prepare(
                'UPDATE s3_objects SET is_latest = 0, noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND is_latest = 1',
            );
            $stmt->execute([$now, $bucket, $key]);

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
                $this->clearNullVersionMetadata($bucket, $key);
                $stmt = $this->prepare(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, 'null', 1, 1,
                        ?, '', 0, 'application/octet-stream', 'STANDARD', '',
                        ?, ?
                    )
                    ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                        is_latest = 1,
                        noncurrent_at = NULL,
                        is_delete_marker = 1,
                        owner_id = excluded.owner_id,
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
                        created_at = excluded.created_at,
                        updated_at = excluded.updated_at
                    SQL,
                );
                $stmt->execute([$bucket, $key, $ownerId, $now, $now]);
            } else {
                // Enabled: insert new delete marker with random version ID.
                $stmt = $this->prepare(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, 1, 1,
                        ?, '', 0, 'application/octet-stream', 'STANDARD', '',
                        ?, ?
                    )
                    SQL,
                );
                $stmt->execute([$bucket, $key, $versionId, $ownerId, $now, $now]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $versionId;
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        $stmt = $this->prepare(
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
        );
        $stmt->execute([$bucket, $key, $versionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->rowToObjectInfo($row);
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        $pdo = $this->connection();

        // First, get the version info so we can return it.
        $objectInfo = $this->getObjectMetadataByVersion($bucket, $key, $versionId);

        if ($objectInfo === null) {
            return null;
        }

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            // Delete the specific version row.
            $stmt = $this->prepare(
                'DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?',
            );
            $stmt->execute([$bucket, $key, $versionId]);

            // If it was the latest, promote the next most recent version.
            if (! empty($objectInfo->systemMetadata['isLatest'])) {
                $stmt = $this->prepare(
                    <<<'SQL'
                    UPDATE s3_objects
                    SET is_latest = 1, noncurrent_at = NULL
                    WHERE bucket = ? AND key_name = ?
                      AND id = (
                          SELECT id FROM s3_objects
                          WHERE bucket = ? AND key_name = ?
                          ORDER BY created_at DESC, id DESC
                          LIMIT 1
                      )
                      AND is_latest = 0
                    SQL,
                );
                $stmt->execute([$bucket, $key, $bucket, $key]);
            }

            // Clean up retention and legal hold for this version.
            $effectiveVersionId = $versionId;
            $stmt = $this->prepare(
                'DELETE FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = ?',
            );
            $stmt->execute([$bucket, $key, $effectiveVersionId]);

            $stmt = $this->prepare(
                'DELETE FROM s3_object_legal_holds WHERE bucket = ? AND key_name = ? AND version_id = ?',
            );
            $stmt->execute([$bucket, $key, $effectiveVersionId]);
            $this->prepare(
                "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?",
            )->execute([$bucket, $key, $effectiveVersionId]);
            $this->prepare(
                "DELETE FROM s3_acls WHERE resource_type = 'object' AND resource_name = ?",
            )->execute([\OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName($bucket, $key, $versionId)]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
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
        $pdo = $this->connection();
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

        // Build query: all versions (including delete markers).
        $sql = 'SELECT bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_tier, transition_status, transition_target_tier, transition_error, '
            . 'restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, noncurrent_at, updated_at, id '
            . 'FROM s3_objects WHERE bucket = ?';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
            $params[] = $prefix;
            $params[] = $prefix;
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Resolve the marker row to resume in latest, created_at, id order.
                $markerStmt = $this->prepare(
                    'SELECT id, is_latest, created_at FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ? LIMIT 1',
                );
                $markerStmt->execute([$bucket, $keyMarker, $versionIdMarker]);
                $markerRow = $markerStmt->fetch(\PDO::FETCH_ASSOC);

                if ($markerRow !== false) {
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
            // No delimiter: straightforward limit.
            $sql .= ' ORDER BY key_name ASC, is_latest DESC, created_at DESC, id DESC LIMIT ?';
            $params[] = $maxKeys + 1;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
                if ((bool) $row['is_delete_marker']) {
                    $deleteMarkers[] = $info;
                } else {
                    $versions[] = $info;
                }
            }

            $nextKeyMarker = null;
            $nextVersionIdMarker = null;
            if ($isTruncated && count($rows) > 0) {
                $lastRow = $rows[count($rows) - 1];
                $nextKeyMarker = $lastRow['key_name'];
                $nextVersionIdMarker = $lastRow['version_id'];
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
            fetchRows: static function (?array $cursor, int $limit, ?string $afterPrefix) use ($pdo, $sql, $params): array {
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
                $stmt = $pdo->prepare($query);
                $stmt->execute($queryParams);

                return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
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

    /**
     * @return array{objectLockEnabled: string, rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}}|null
     */
    public function getObjectLockConfig(string $bucket): ?array
    {
        $stmt = $this->prepare(
            'SELECT object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years '
            . 'FROM s3_lock_configs WHERE bucket = ?',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $config = [
            'objectLockEnabled' => ((int) $row['object_lock_enabled']) === 1 ? 'Enabled' : 'Disabled',
        ];

        if ($row['default_retention_mode'] !== null) {
            $retention = [
                'mode' => $row['default_retention_mode'],
            ];
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

    /**
     * @param  array{objectLockEnabled: string, rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}}  $config
     */
    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $objectLockEnabled = $config['objectLockEnabled'] === 'Enabled' ? 1 : 0;
        $retentionMode = $config['rule']['defaultRetention']['mode'] ?? null;
        $retentionDays = $config['rule']['defaultRetention']['days'] ?? null;
        $retentionYears = $config['rule']['defaultRetention']['years'] ?? null;

        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_lock_configs (bucket, object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(bucket) DO UPDATE SET
                object_lock_enabled = excluded.object_lock_enabled,
                default_retention_mode = excluded.default_retention_mode,
                default_retention_days = excluded.default_retention_days,
                default_retention_years = excluded.default_retention_years,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$bucket, $objectLockEnabled, $retentionMode, $retentionDays, $retentionYears]);

        // Also update the bucket's object_lock_enabled flag.
        $stmt = $this->prepare('UPDATE s3_buckets SET object_lock_enabled = ? WHERE name = ?');
        $stmt->execute([$objectLockEnabled, $bucket]);
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        $effectiveVersionId = $versionId ?? 'null';

        $stmt = $this->prepare(
            'SELECT mode, retain_until_date FROM s3_object_retention '
            . 'WHERE bucket = ? AND key_name = ? AND version_id = ?',
        );
        $stmt->execute([$bucket, $key, $effectiveVersionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'mode' => $row['mode'],
            'retainUntilDate' => $row['retain_until_date'],
        ];
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';

        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_object_retention (bucket, key_name, version_id, mode, retain_until_date)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                mode = excluded.mode,
                retain_until_date = excluded.retain_until_date,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$bucket, $key, $effectiveVersionId, $mode, $retainUntilDate]);
    }

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        $stmt = $this->prepare(
            'DELETE FROM s3_object_retention WHERE bucket = ? AND key_name = ? AND version_id = ?',
        );
        $stmt->execute([$bucket, $key, $versionId ?? 'null']);
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        $effectiveVersionId = $versionId ?? 'null';

        $stmt = $this->prepare(
            'SELECT status FROM s3_object_legal_holds '
            . 'WHERE bucket = ? AND key_name = ? AND version_id = ?',
        );
        $stmt->execute([$bucket, $key, $effectiveVersionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $row['status'];
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';

        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_object_legal_holds (bucket, key_name, version_id, status)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                status = excluded.status,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$bucket, $key, $effectiveVersionId, $status]);
    }

    // ===============================================================
    // ACLs
    // ===============================================================

    public function getAcl(string $resourceType, string $resourceName): array
    {
        $stmt = $this->prepare(
            'SELECT grantee_type, grantee_id, permission FROM s3_acls '
            . 'WHERE resource_type = ? AND resource_name = ? ORDER BY id ASC',
        );
        $stmt->execute([$resourceType, $resourceName]);

        $grants = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $grants[] = [
                'granteeType' => $row['grantee_type'],
                'granteeId' => $row['grantee_id'],
                'permission' => $row['permission'],
            ];
        }

        return $grants;
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $pdo = $this->connection();

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            // Delete existing grants for this resource.
            $stmt = $this->prepare(
                'DELETE FROM s3_acls WHERE resource_type = ? AND resource_name = ?',
            );
            $stmt->execute([$resourceType, $resourceName]);

            // Insert new grants.
            $stmt = $this->prepare(
                'INSERT INTO s3_acls (resource_type, resource_name, grantee_type, grantee_id, permission) '
                . 'VALUES (?, ?, ?, ?, ?)',
            );

            foreach ($grants as $grant) {
                $stmt->execute([
                    $resourceType,
                    $resourceName,
                    $grant['granteeType'],
                    $grant['granteeId'],
                    $grant['permission'],
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ===============================================================
    // Tagging
    // ===============================================================

    public function getBucketTagging(string $bucket): array
    {
        $stmt = $this->prepare(
            'SELECT tag_key, tag_value FROM s3_tagging '
            . "WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL ORDER BY id ASC",
        );
        $stmt->execute([$bucket]);

        $tags = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tags[] = [
                'key' => $row['tag_key'],
                'value' => $row['tag_value'],
            ];
        }

        return $tags;
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $pdo = $this->connection();

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare(
                "DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL",
            );
            $stmt->execute([$bucket]);

            $stmt = $this->prepare(
                'INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) '
                . "VALUES ('bucket', ?, NULL, ?, ?)",
            );

            foreach ($tags as $tag) {
                $stmt->execute([$bucket, $tag['key'], $tag['value']]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $stmt = $this->prepare(
            "DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = ? AND key_name IS NULL",
        );
        $stmt->execute([$bucket]);
    }

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        $stmt = $this->prepare(
            'SELECT tag_key, tag_value FROM s3_tagging '
            . "WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ? ORDER BY id ASC",
        );
        $stmt->execute([$bucket, $key, $versionId ?? 'null']);

        $tags = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tags[] = [
                'key' => $row['tag_key'],
                'value' => $row['tag_value'],
            ];
        }

        return $tags;
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $pdo = $this->connection();

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare(
                "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?",
            );
            $stmt->execute([$bucket, $key, $versionId ?? 'null']);

            $stmt = $this->prepare(
                'INSERT INTO s3_tagging (resource_type, bucket, key_name, version_id, tag_key, tag_value) '
                . "VALUES ('object', ?, ?, ?, ?, ?)",
            );

            foreach ($tags as $tag) {
                $stmt->execute([$bucket, $key, $versionId ?? 'null', $tag['key'], $tag['value']]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteObjectTagging(string $bucket, string $key, ?string $versionId = null): void
    {
        $stmt = $this->prepare(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ? AND version_id = ?",
        );
        $stmt->execute([$bucket, $key, $versionId ?? 'null']);
    }

    // ===============================================================
    // Bucket Policies
    // ===============================================================

    public function getBucketPolicy(string $bucket): ?string
    {
        $stmt = $this->prepare('SELECT policy_json FROM s3_policies WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $row['policy_json'];
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_policies (bucket, policy_json)
            VALUES (?, ?)
            ON CONFLICT(bucket) DO UPDATE SET
                policy_json = excluded.policy_json,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$bucket, $policyJson]);
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_policies WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // CORS
    // ===============================================================

    public function getBucketCors(string $bucket): array
    {
        $stmt = $this->prepare(
            'SELECT rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds '
            . 'FROM s3_cors_rules WHERE bucket = ? ORDER BY rule_order ASC',
        );
        $stmt->execute([$bucket]);

        $rules = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rule = [
                'allowedOrigins' => json_decode($row['allowed_origins'], true, 512, JSON_THROW_ON_ERROR),
                'allowedMethods' => json_decode($row['allowed_methods'], true, 512, JSON_THROW_ON_ERROR),
                'allowedHeaders' => json_decode($row['allowed_headers'], true, 512, JSON_THROW_ON_ERROR),
                'exposeHeaders' => json_decode($row['expose_headers'], true, 512, JSON_THROW_ON_ERROR),
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
        $pdo = $this->connection();
        $ownTx = ! $pdo->inTransaction();

        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare('DELETE FROM s3_cors_rules WHERE bucket = ?');
            $stmt->execute([$bucket]);

            $stmt = $this->prepare(
                'INSERT INTO s3_cors_rules (bucket, rule_order, rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            );

            foreach ($rules as $index => $rule) {
                $stmt->execute([
                    $bucket,
                    $index,
                    $rule['id'] ?? null,
                    json_encode($rule['allowedOrigins'], JSON_THROW_ON_ERROR),
                    json_encode($rule['allowedMethods'], JSON_THROW_ON_ERROR),
                    json_encode($rule['allowedHeaders'], JSON_THROW_ON_ERROR),
                    json_encode($rule['exposeHeaders'], JSON_THROW_ON_ERROR),
                    $rule['maxAgeSeconds'] ?? -1,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteBucketCors(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_cors_rules WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // Encryption Config
    // ===============================================================

    public function getBucketEncryption(string $bucket): ?array
    {
        $stmt = $this->prepare(
            'SELECT sse_algorithm, kms_master_key_id, bucket_key_enabled '
            . 'FROM s3_encryption_configs WHERE bucket = ?',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $config = [
            'sseAlgorithm' => $row['sse_algorithm'],
            'kmsMasterKeyId' => $row['kms_master_key_id'],
            'bucketKeyEnabled' => ((int) $row['bucket_key_enabled']) !== 0,
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
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(bucket) DO UPDATE SET
                sse_algorithm = excluded.sse_algorithm,
                kms_master_key_id = excluded.kms_master_key_id,
                bucket_key_enabled = excluded.bucket_key_enabled,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled ? 1 : 0]);
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_encryption_configs WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // Lifecycle Configuration
    // ===============================================================

    /**
     * @return list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>
     */
    public function getBucketLifecycle(string $bucket): array
    {
        $stmt = $this->prepare(
            'SELECT rule_id, status, prefix, filter_json, transitions_json, expiration_json, '
            . 'noncurrent_transitions_json, noncurrent_expiration_json, abort_incomplete_days '
            . 'FROM s3_lifecycle_rules WHERE bucket = ? ORDER BY rule_id ASC',
        );
        $stmt->execute([$bucket]);

        $rules = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rules[] = [
                'id' => $row['rule_id'],
                'status' => $row['status'],
                'prefix' => $row['prefix'],
                'filter' => $row['filter_json'] !== null ? json_decode($row['filter_json'], true, 512, JSON_THROW_ON_ERROR) : null,
                'transitions' => $row['transitions_json'] !== null ? json_decode($row['transitions_json'], true, 512, JSON_THROW_ON_ERROR) : null,
                'expiration' => $row['expiration_json'] !== null ? json_decode($row['expiration_json'], true, 512, JSON_THROW_ON_ERROR) : null,
                'noncurrentTransitions' => $row['noncurrent_transitions_json'] !== null ? json_decode($row['noncurrent_transitions_json'], true, 512, JSON_THROW_ON_ERROR) : null,
                'noncurrentExpiration' => $row['noncurrent_expiration_json'] !== null ? json_decode($row['noncurrent_expiration_json'], true, 512, JSON_THROW_ON_ERROR) : null,
                'abortIncompleteDays' => $row['abort_incomplete_days'] !== null ? (int) $row['abort_incomplete_days'] : null,
            ];
        }

        return $rules;
    }

    public function listReferencedStorageTiers(): array
    {
        $tiers = $this->prepare(StorageTierReferences::TIER_SQL);
        $tiers->execute();
        $lifecycle = $this->prepare(StorageTierReferences::LIFECYCLE_SQL);
        $lifecycle->execute();

        return StorageTierReferences::collect(
            $tiers->fetchAll(\PDO::FETCH_ASSOC),
            $lifecycle->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param  list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>  $rules
     */
    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $pdo = $this->connection();

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare('DELETE FROM s3_lifecycle_rules WHERE bucket = ?');
            $stmt->execute([$bucket]);

            $stmt = $this->prepare(
                'INSERT INTO s3_lifecycle_rules (bucket, rule_id, status, prefix, filter_json, '
                . 'transitions_json, expiration_json, noncurrent_transitions_json, noncurrent_expiration_json, '
                . 'abort_incomplete_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );

            foreach ($rules as $rule) {
                $stmt->execute([
                    $bucket,
                    $rule['id'],
                    $rule['status'],
                    $rule['prefix'] ?? null,
                    isset($rule['filter']) ? json_encode($rule['filter'], JSON_THROW_ON_ERROR) : null,
                    isset($rule['transitions']) ? json_encode($rule['transitions'], JSON_THROW_ON_ERROR) : null,
                    isset($rule['expiration']) ? json_encode($rule['expiration'], JSON_THROW_ON_ERROR) : null,
                    isset($rule['noncurrentTransitions']) ? json_encode($rule['noncurrentTransitions'], JSON_THROW_ON_ERROR) : null,
                    isset($rule['noncurrentExpiration']) ? json_encode($rule['noncurrentExpiration'], JSON_THROW_ON_ERROR) : null,
                    $rule['abortIncompleteDays'] ?? null,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_lifecycle_rules WHERE bucket = ?');
        $stmt->execute([$bucket]);
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
        $stmt = $this->prepare(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at,
                origin, lifecycle_rule_id, expected_is_latest
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
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
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);
        $pdo = $this->connection();
        $this->executeWithRetry(
            "UPDATE s3_tier_transition_jobs SET status = 'pending', updated_at = ?
             WHERE status = 'processing' AND next_attempt_at < ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $now],
        );

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare(
                "SELECT * FROM s3_tier_transition_jobs
                 WHERE status = 'pending' AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ?",
            );
            $stmt->execute([$now, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->prepare(
                    "UPDATE s3_tier_transition_jobs
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1, updated_at = ?
                     WHERE id IN ({$placeholders})",
                );
                $update->execute([sprintf('%.17F', $leaseExpiresAt), gmdate('Y-m-d\TH:i:s\Z'), ...$ids]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($rows as &$row) {
            $row['next_attempt_at'] = $leaseExpiresAt;
            $row['lease_generation'] = (int) $row['lease_generation'] + 1;
            $row['status'] = 'processing';
        }
        unset($row);

        return array_values(array_map($this->rowToTierTransitionJob(...), $rows));
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
            ? 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE(?, target_storage_path), updated_at = ? WHERE id = ?'
            : 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), target_storage_path = COALESCE(?, target_storage_path), updated_at = ? WHERE id = ?';
        $params = [
            $status,
            $error,
            $nextAttemptAt,
            $targetStoragePath,
            gmdate('Y-m-d\TH:i:s\Z'),
            $id,
        ];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_tier_transition_jobs
             SET next_attempt_at = ?, updated_at = ?
             WHERE id = ? AND status = 'processing'";
        $params = [sprintf('%.17F', $leaseExpiresAt), gmdate('Y-m-d\TH:i:s\Z'), $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND next_attempt_at = ? AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function getTierTransitionJob(int $id): ?array
    {
        $stmt = $this->prepare('SELECT * FROM s3_tier_transition_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->rowToTierTransitionJob($row) : null;
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
        $stmt = $this->prepare(
            'INSERT INTO s3_restore_jobs (
                bucket, key_name, version_id, source_tier, source_storage_path,
                restore_days, max_attempts, next_attempt_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $bucket,
            $key,
            $versionId,
            $sourceTier,
            $sourceStoragePath,
            max(1, $restoreDays),
            max(1, $maxAttempts),
            $this->multipartWriteIntentNow(),
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);
        $pdo = $this->connection();
        $this->executeWithRetry(
            "UPDATE s3_restore_jobs SET status = 'pending', updated_at = ?
             WHERE status = 'processing' AND next_attempt_at < ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $now],
        );

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare(
                "SELECT * FROM s3_restore_jobs
                 WHERE status = 'pending' AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT ?",
            );
            $stmt->execute([$now, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $update = $this->prepare(
                    "UPDATE s3_restore_jobs
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1, updated_at = ?
                     WHERE id IN ({$placeholders})",
                );
                $update->execute([sprintf('%.17F', $leaseExpiresAt), gmdate('Y-m-d\TH:i:s\Z'), ...$ids]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            throw $e;
        }

        foreach ($rows as &$row) {
            $row['next_attempt_at'] = $leaseExpiresAt;
            $row['lease_generation'] = (int) $row['lease_generation'] + 1;
            $row['status'] = 'processing';
        }
        unset($row);

        return array_values(array_map($this->rowToRestoreJob(...), $rows));
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
            ? 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE(?, restored_storage_path), updated_at = ? WHERE id = ?'
            : 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), restored_storage_path = COALESCE(?, restored_storage_path), updated_at = ? WHERE id = ?';
        $params = [
            $status,
            $error,
            $nextAttemptAt,
            $restoredStoragePath,
            gmdate('Y-m-d\TH:i:s\Z'),
            $id,
        ];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_restore_jobs
             SET next_attempt_at = ?, updated_at = ?
             WHERE id = ? AND status = 'processing'";
        $params = [sprintf('%.17F', $leaseExpiresAt), gmdate('Y-m-d\TH:i:s\Z'), $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND next_attempt_at = ? AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $stmt = $this->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function getRestoreJob(int $id): ?array
    {
        $stmt = $this->prepare('SELECT * FROM s3_restore_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->rowToRestoreJob($row) : null;
    }

    // ===============================================================
    // Notification Configuration
    // ===============================================================

    /**
     * @return list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, mixed>}>
     */
    public function getBucketNotification(string $bucket): array
    {
        $stmt = $this->prepare(
            'SELECT config_id, event_type, destination_type, destination_arn, filter_rules_json '
            . 'FROM s3_notification_configs WHERE bucket = ? ORDER BY config_id ASC',
        );
        $stmt->execute([$bucket]);

        $configs = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $configs[] = [
                'id' => $row['config_id'],
                'events' => self::decodeEvents($row['event_type']),
                'destinationType' => $row['destination_type'],
                'destinationArn' => $row['destination_arn'],
                'filterRules' => $row['filter_rules_json'] !== null ? json_decode($row['filter_rules_json'], true, 512, JSON_THROW_ON_ERROR) : null,
            ];
        }

        return $configs;
    }

    /**
     * @param  list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, mixed>}>  $configs
     */
    public function putBucketNotification(string $bucket, array $configs): void
    {
        $pdo = $this->connection();

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            $stmt = $this->prepare('DELETE FROM s3_notification_configs WHERE bucket = ?');
            $stmt->execute([$bucket]);

            $stmt = $this->prepare(
                'INSERT INTO s3_notification_configs (bucket, config_id, event_type, destination_type, destination_arn, filter_rules_json) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
            );

            foreach ($configs as $config) {
                $stmt->execute([
                    $bucket,
                    $config['id'],
                    json_encode($config['events'], JSON_THROW_ON_ERROR),
                    $config['destinationType'],
                    $config['destinationArn'],
                    isset($config['filterRules']) ? json_encode($config['filterRules'], JSON_THROW_ON_ERROR) : null,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
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

        // Legacy format: plain event string (e.g., "s3:ObjectCreated:Put")
        return [$raw];
    }

    // ===============================================================
    // Website Configuration
    // ===============================================================

    /**
     * @return array{indexDocument: string, errorDocument: ?string, redirectAllHost: ?string, redirectAllProtocol: ?string, routingRules: ?list<array<string, mixed>>}|null
     */
    public function getBucketWebsite(string $bucket): ?array
    {
        $stmt = $this->prepare(
            'SELECT index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json '
            . 'FROM s3_website_configs WHERE bucket = ?',
        );
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'indexDocument' => $row['index_document'],
            'errorDocument' => $row['error_document'],
            'redirectAllHost' => $row['redirect_all_host'],
            'redirectAllProtocol' => $row['redirect_all_protocol'],
            'routingRules' => $row['routing_rules_json'] !== null ? json_decode($row['routing_rules_json'], true, 512, JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $routingRules
     */
    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_website_configs (bucket, index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(bucket) DO UPDATE SET
                index_document = excluded.index_document,
                error_document = excluded.error_document,
                redirect_all_host = excluded.redirect_all_host,
                redirect_all_protocol = excluded.redirect_all_protocol,
                routing_rules_json = excluded.routing_rules_json,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([
            $bucket,
            $indexDocument,
            $errorDocument,
            $redirectAllHost,
            $redirectAllProtocol,
            $routingRules !== null ? json_encode($routingRules, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_website_configs WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // Public Access Block
    // ===============================================================

    public function getPublicAccessBlock(string $bucket): ?array
    {
        $stmt = $this->prepare('SELECT * FROM s3_public_access_blocks WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
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
        $stmt = $this->prepare(
            'INSERT INTO s3_public_access_blocks (bucket, block_public_acls, ignore_public_acls, block_public_policy, restrict_public_buckets) '
            . 'VALUES (?, ?, ?, ?, ?) '
            . 'ON CONFLICT(bucket) DO UPDATE SET block_public_acls = excluded.block_public_acls, ignore_public_acls = excluded.ignore_public_acls, block_public_policy = excluded.block_public_policy, restrict_public_buckets = excluded.restrict_public_buckets',
        );
        $stmt->execute([$bucket, (int) $blockPublicAcls, (int) $ignorePublicAcls, (int) $blockPublicPolicy, (int) $restrictPublicBuckets]);
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_public_access_blocks WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        $stmt = $this->prepare('SELECT object_ownership FROM s3_bucket_ownership_controls WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function putBucketOwnershipControls(string $bucket, string $ownership): void
    {
        $stmt = $this->prepare('INSERT INTO s3_bucket_ownership_controls (bucket, object_ownership) VALUES (?, ?) ON CONFLICT(bucket) DO UPDATE SET object_ownership = excluded.object_ownership');
        $stmt->execute([$bucket, $ownership]);
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_bucket_ownership_controls WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // Bucket Logging
    // ===============================================================

    public function getBucketLogging(string $bucket): ?array
    {
        $stmt = $this->prepare('SELECT * FROM s3_bucket_logging WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'targetBucket' => $row['target_bucket'],
            'targetPrefix' => $row['target_prefix'],
        ];
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $stmt = $this->prepare(
            'INSERT INTO s3_bucket_logging (bucket, target_bucket, target_prefix) '
            . 'VALUES (?, ?, ?) '
            . 'ON CONFLICT(bucket) DO UPDATE SET target_bucket = excluded.target_bucket, target_prefix = excluded.target_prefix',
        );
        $stmt->execute([$bucket, $targetBucket, $targetPrefix]);
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $stmt = $this->prepare('DELETE FROM s3_bucket_logging WHERE bucket = ?');
        $stmt->execute([$bucket]);
    }

    // ===============================================================
    // Distributed locks
    // ===============================================================

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $ttlSeconds) . ' seconds')
            ->format('Y-m-d\TH:i:s\Z');

        $insert = $this->prepare(
            'INSERT OR IGNORE INTO s3_locks (lock_name, owner_id, expires_at, updated_at) VALUES (?, ?, ?, ?)',
        );
        $insert->execute([$lockName, $ownerId, $expiresAt, $now]);
        if ($insert->rowCount() > 0) {
            return true;
        }

        $update = $this->prepare(
            'UPDATE s3_locks
             SET owner_id = ?, expires_at = ?, updated_at = ?
             WHERE lock_name = ? AND (owner_id = ? OR expires_at <= ?)',
        );
        $update->execute([$ownerId, $expiresAt, $now, $lockName, $ownerId, $now]);

        return $update->rowCount() > 0;
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $stmt = $this->prepare('DELETE FROM s3_locks WHERE lock_name = ? AND owner_id = ?');
        $stmt->execute([$lockName, $ownerId]);
    }

    // ===============================================================
    // Lifecycle checkpoints
    // ===============================================================

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        $stmt = $this->prepare(
            'SELECT cursor_key, cursor_version_id, cursor_upload_id FROM s3_lifecycle_checkpoints WHERE bucket = ? AND rule_id = ? AND action = ?',
        );
        $stmt->execute([$bucket, $ruleId, $action]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
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
        $stmt = $this->prepare(
            'INSERT INTO s3_lifecycle_checkpoints (bucket, rule_id, action, cursor_key, cursor_version_id, cursor_upload_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(bucket, rule_id, action) DO UPDATE SET
                cursor_key = excluded.cursor_key,
                cursor_version_id = excluded.cursor_version_id,
                cursor_upload_id = excluded.cursor_upload_id,
                updated_at = excluded.updated_at',
        );
        $stmt->execute([
            $bucket,
            $ruleId,
            $action,
            $cursorKey,
            $cursorVersionId,
            $cursorUploadId,
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $stmt = $this->prepare('DELETE FROM s3_lifecycle_checkpoints WHERE bucket = ? AND rule_id = ? AND action = ?');
        $stmt->execute([$bucket, $ruleId, $action]);
    }

    // ===============================================================
    // Lifecycle query methods
    // ===============================================================

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        $pdo = $this->connection();

        $sql = 'SELECT * FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0 AND created_at < ?';
        $params = [$bucket, $olderThan->format('Y-m-d\TH:i:s\Z')];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map(fn(array $row) => $this->rowToObjectInfo($row), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        $pdo = $this->connection();

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$noncurrentDays} days")
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT * FROM s3_objects WHERE bucket = ? AND is_latest = 0 AND is_delete_marker = 0 AND noncurrent_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map(fn(array $row) => $this->rowToObjectInfo($row), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null, ?\DateTimeImmutable $createdBefore = null): array
    {
        $pdo = $this->connection();

        $cutoff = ($createdBefore ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$daysAfterInitiation} days"))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT upload_id, bucket, key_name FROM s3_multipart_uploads WHERE bucket = ? AND created_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(key_name, 1, length(?)) = ? COLLATE BINARY';
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        $pdo = $this->connection();

        // Find delete markers where no other versions exist for the same key.
        $sql = 'SELECT dm.* FROM s3_objects dm '
            . 'WHERE dm.bucket = ? AND dm.is_delete_marker = 1 AND dm.is_latest = 1 '
            . 'AND NOT EXISTS (SELECT 1 FROM s3_objects o WHERE o.bucket = dm.bucket AND o.key_name = dm.key_name AND o.is_delete_marker = 0)';
        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= ' AND substr(dm.key_name, 1, length(?)) = ? COLLATE BINARY';
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map(fn(array $row) => $this->rowToObjectInfo($row), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        $stmt = $this->prepare(
            'SELECT o.*, b.generation AS bucket_generation
             FROM s3_objects AS o
             INNER JOIN s3_buckets AS b ON b.name = o.bucket
             WHERE o.restore_status = ? AND o.restored_storage_path IS NOT NULL AND o.restore_expires_at IS NOT NULL AND o.restore_expires_at <= ?
             ORDER BY o.restore_expires_at ASC, o.bucket ASC, o.key_name ASC, o.version_id ASC
             LIMIT ?',
        );
        $stmt->execute([
            'restored',
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            max(1, min(10000, $limit)),
        ]);

        return array_values(array_map(fn(array $row) => $this->rowToObjectInfo($row), $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    // ===============================================================
    // Rate Limiting
    // ===============================================================

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        $now = microtime(true);

        $this->prepare(
            'INSERT OR IGNORE INTO s3_rate_limit_buckets (ip, tokens, last_refill_at) VALUES (?, ?, ?)',
        )->execute([$ip, $maxTokens, $now]);

        $stmt = $this->prepare(
            'UPDATE s3_rate_limit_buckets SET
                tokens = MIN(CAST(? AS REAL), tokens + MAX(0.0, ? - last_refill_at) * ?) - 1.0,
                last_refill_at = MAX(last_refill_at, CAST(? AS REAL))
             WHERE ip = ? AND MIN(CAST(? AS REAL), tokens + MAX(0.0, ? - last_refill_at) * ?) >= 1.0',
        );
        $stmt->execute([$maxTokens, $now, $refillRate, $now, $ip, $maxTokens, $now, $refillRate]);

        return $stmt->rowCount() > 0;
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $cutoff = microtime(true) - $maxAgeSeconds;
        $this->prepare('DELETE FROM s3_rate_limit_buckets WHERE last_refill_at < ?')->execute([$cutoff]);
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
        $this->prepare(
            'INSERT INTO s3_notification_queue (bucket, key_name, event_name, destination_url, payload_json, max_attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, $this->multipartWriteIntentNow()]);

        return (int) $this->connection()->lastInsertId();
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->prepare('UPDATE s3_notification_queue SET payload_json = ? WHERE id = ?')->execute([$payloadJson, $id]);
    }

    public function dequeueNotifications(int $limit): array
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);
        $pdo = $this->connection();

        // Fast-path: skip transaction if queue is empty (avoids write-lock contention).
        $countStmt = $this->prepare("SELECT COUNT(*) FROM s3_notification_queue WHERE status IN ('pending', 'processing')");
        $countStmt->execute();
        if ((int) $countStmt->fetchColumn() === 0) {
            return [];
        }

        $ownTx = ! $pdo->inTransaction();
        if ($ownTx) {
            $this->beginImmediateTransaction($pdo);
        }

        try {
            // Reset stale "processing" items (stuck > 5 minutes).
            $this->prepare(
                "UPDATE s3_notification_queue SET status = 'pending'
                 WHERE status = 'processing' AND next_attempt_at < ?",
            )->execute([$now]);

            $stmt = $this->prepare(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts, lease_generation
                 FROM s3_notification_queue
                 WHERE status = 'pending' AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC
                 LIMIT ?",
            );
            $stmt->execute([$now, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                // Use connection()->prepare() directly — dynamic IN clause would pollute the statement cache.
                // PDO stringifies float parameters using the process precision.
                // Preserve every bit of the lease timestamp so a rapid reclaim
                // cannot accidentally reuse the old worker's SQL token. %F
                // keeps the decimal point independent of LC_NUMERIC.
                $pdo->prepare(
                    "UPDATE s3_notification_queue
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1
                     WHERE id IN ({$placeholders})",
                )->execute([sprintf('%.17F', $leaseExpiresAt), ...$ids]);
            }

            if ($ownTx) {
                $pdo->commit();
            }

            return array_values(array_map(
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
            ));
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @phpstan-impure */
    public function getNotificationQueueStats(): array
    {
        $stmt = $this->prepare('SELECT status, COUNT(*) AS count FROM s3_notification_queue GROUP BY status');
        $stmt->execute();

        $stats = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stats[(string) $row['status']] = (int) $row['count'];
        }

        return $stats;
    }

    public function getMultipartCompletionIntentCount(): int
    {
        $statement = $this->prepare('SELECT retained_count, typeof(retained_count) AS count_type FROM s3_multipart_completion_capacity WHERE id = 1');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row['count_type'] !== 'integer' || ! is_numeric($row['retained_count'] ?? null)) {
            throw new \RuntimeException('Multipart completion recovery capacity counter is unavailable.');
        }

        return (int) $row['retained_count'];
    }

    /** @return array{object: int, part: int} */
    public function getWriteIntentCapacityCounts(): array
    {
        $statement = $this->prepare('SELECT kind, retained_count, typeof(retained_count) AS count_type FROM s3_write_intent_capacity WHERE kind IN (\'object\', \'part\')');
        $statement->execute();
        $counts = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['count_type'] !== 'integer' || ! is_numeric($row['retained_count'] ?? null)) {
                throw new \RuntimeException('Write intent recovery capacity counter is unavailable.');
            }
            $counts[(string) $row['kind']] = (int) $row['retained_count'];
        }
        if (! isset($counts['object'], $counts['part']) || count($counts) !== 2) {
            throw new \RuntimeException('Write intent recovery capacity counter is unavailable.');
        }

        return ['object' => $counts['object'], 'part' => $counts['part']];
    }

    public function getStorageCleanupCount(): int
    {
        $statement = $this->prepare('SELECT retained_count, typeof(retained_count) AS count_type FROM s3_storage_cleanup_capacity WHERE id = 1');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || $row['count_type'] !== 'integer' || ! is_numeric($row['retained_count'] ?? null)) {
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
        // Terminal rows have no next attempt. Record when delivery completed
        // so a long-queued event retains its full inspection window.
        if ($status === 'sent' || $status === 'dead_letter') {
            $nextAttemptAt = $this->multipartWriteIntentNow();
        }
        $sql = $incrementAttempts
            ? 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1 WHERE id = ?'
            : 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at) WHERE id = ?';
        $params = [$status, $error, $nextAttemptAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ? AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = ?';
            $params[] = $expectedLeaseGeneration;
        }
        $statement = $this->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = $this->multipartWriteIntentNow() - $maxAgeSeconds;
        $this->prepare(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND next_attempt_at < ?",
        )->execute([$cutoff]);
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

    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        try {
            $this->prepare(
                'INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at)
                 VALUES (?, ?, ?, ?)',
            )->execute([$bucket, $storageTier, $storagePath, $this->multipartWriteIntentNow()]);
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function enqueueRecoveryStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        try {
            $this->prepare(
                'INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, recovery_admission, next_attempt_at)
                 VALUES (?, ?, ?, 1, ?)',
            )->execute([$bucket, $storageTier, $storagePath, $this->multipartWriteIntentNow()]);
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function listMultipartPartWriteIntents(string $ownerId, int $afterId, int $limit): array
    {
        $statement = $this->prepare(
            "SELECT id, bucket, storage_tier, storage_path, status, attempts, created_at, next_attempt_at
             FROM s3_storage_garbage
             WHERE operation = 'part_write_intent' AND owner_id = ? AND id > ?
             ORDER BY id LIMIT ?",
        );
        $statement->execute([$ownerId, max(0, $afterId), max(1, min(1000, $limit))]);

        return array_values(array_map(MultipartPartWriteIntentInventoryRow::fromDatabase(...), $statement->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
        bool $localReservation = false,
    ): void {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Multipart part write intent admission requires its own transaction.');
        }
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['bucket_generation'] !== $bucketGeneration) {
            throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
        }
        $ownerId = $upload['owner_id'];
        $this->transaction(function () use ($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath, $ownerId, $localReservation): void {
            $this->lockOwnerForUpdate($ownerId);
            $global = $this->prepare("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part'");
            $global->execute();
            $retained = $global->fetchColumn();
            if ($retained === false || (int) $retained >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS) {
                throw new SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $statement = $this->prepare("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = ?");
            $statement->execute([$ownerId]);
            $count = (int) $statement->fetchColumn();
            if ($count >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $existing = $this->prepare("SELECT 1 FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = ? AND upload_id = ? AND storage_path = ? LIMIT 1");
            $existing->execute([$ownerId, $uploadId, $storagePath]);
            $committed = $this->prepare('SELECT 1 FROM s3_parts WHERE upload_id = ? AND storage_path = ? LIMIT 1');
            $committed->execute([$uploadId, $storagePath]);
            if ($existing->fetchColumn() !== false || $committed->fetchColumn() !== false) {
                throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart part path has already been used.');
            }
            $due = $this->multipartWriteIntentNow() + 43_200;
            $insert = $this->prepare(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
             SELECT bucket, storage_tier, ?, 'part_write_intent', key_name, upload_id, owner_id, ?, ?
             FROM s3_multipart_uploads
             WHERE upload_id = ? AND bucket = ? AND bucket_generation = ? AND owner_id = ? AND storage_tier = ?",
            );
            $insert->execute([$storagePath, $due, $localReservation ? null : ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $uploadId, $bucket, $bucketGeneration, $ownerId, $storageTier]);
            if ($insert->rowCount() !== 1) {
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
        $statement = $this->prepare("SELECT 1 FROM s3_storage_garbage AS g
            WHERE g.id = ? AND g.storage_path = ? AND g.operation = 'part_write_intent'
              AND g.status = 'processing' AND g.lease_generation = ?
              AND g.next_attempt_at > (julianday('now') - 2440587.5) * 86400.0
              AND g.upload_id IS NOT NULL AND g.owner_id IS NOT NULL
              AND g.dispatch_id IS NOT NULL AND g.dispatch_id != ?
              AND NOT EXISTS (
                  SELECT 1 FROM s3_parts AS p
                  WHERE p.upload_id = g.upload_id AND p.storage_path = g.storage_path
              )
              AND NOT EXISTS (
                  SELECT 1 FROM s3_storage_garbage AS other
                  WHERE other.id != g.id AND other.operation = 'part_write_intent'
                    AND other.owner_id = g.owner_id AND other.upload_id = g.upload_id
                    AND other.storage_path = g.storage_path
              ) LIMIT 1");
        $statement->execute([$garbageId, $storagePath, $leaseGeneration, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH]);

        return $statement->fetchColumn() !== false;
    }

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart part dispatch ID.');
        }
        $statement = $this->prepare("UPDATE s3_storage_garbage SET dispatch_id = ?
            WHERE bucket = ? AND upload_id = ? AND storage_path = ? AND operation = 'part_write_intent'
              AND status = 'pending' AND dispatch_id IS NULL
              AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0");
        $statement->execute([$dispatchId, $bucket, $uploadId, $storagePath]);

        return $statement->rowCount() === 1;
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $statement = $this->prepare("DELETE FROM s3_storage_garbage
            WHERE bucket = ? AND upload_id = ? AND storage_path = ? AND operation = 'part_write_intent'
              AND status = 'pending' AND dispatch_id = ?
              AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0");
        $statement->execute([$bucket, $uploadId, $storagePath, $dispatchId]);

        return $statement->rowCount() === 1;
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $statement = $this->prepare("DELETE FROM s3_storage_garbage
            WHERE bucket = ? AND upload_id = ? AND storage_path = ? AND operation = 'part_write_intent'
              AND status = 'pending' AND dispatch_id IS NULL");
        $statement->execute([$bucket, $uploadId, $storagePath]);

        return $statement->rowCount() === 1;
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $statement = $this->prepare("DELETE FROM s3_storage_garbage
            WHERE bucket = ? AND upload_id = ? AND storage_path = ? AND operation = 'part_write_intent'
              AND status = 'pending' AND dispatch_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM s3_parts AS p
                  WHERE p.upload_id = s3_storage_garbage.upload_id
                    AND p.storage_path = s3_storage_garbage.storage_path
              )");
        $statement->execute([$bucket, $uploadId, $storagePath, $dispatchId]);

        return $statement->rowCount() === 1;
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        $statement = $this->prepare("DELETE FROM s3_storage_garbage WHERE id IN (
            SELECT id FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND storage_tier = ?
              AND status = 'pending' AND dispatch_id IS NULL
              AND next_attempt_at <= (julianday('now') - 2440587.5) * 86400.0 ORDER BY id LIMIT ?)");
        $statement->execute([$storageTier, max(1, $limit)]);

        return $statement->rowCount();
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        $statement = $this->prepare("SELECT 1 FROM s3_storage_garbage WHERE storage_tier = ? AND operation = 'part_write_intent' AND dispatch_id = ? LIMIT 1");
        $statement->execute([$storageTier, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH]);
        if ($statement->fetchColumn() !== false) {
            throw new \RuntimeException('Legacy multipart part intent blocks local fence admission.');
        }
        $statement = $this->prepare('SELECT 1 FROM s3_multipart_write_intents WHERE storage_tier = ? AND dispatch_id = ? LIMIT 1');
        $statement->execute([$storageTier, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH]);
        if ($statement->fetchColumn() !== false) {
            throw new \RuntimeException('Legacy multipart completion intent blocks local fence admission.');
        }
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        $statement = $this->prepare("SELECT operation FROM s3_storage_garbage INDEXED BY idx_s3_storage_gc_recursive_unsafe
            WHERE storage_tier = ?
              AND CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1
            LIMIT 1");
        $statement->execute([$storageTier]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $operation = $row['operation'] ?? null;
        if ($operation === 'abort_multipart') {
            throw new \RuntimeException('Legacy recursive multipart cleanup blocks local storage admission.');
        }

        throw new \RuntimeException('Unclassified storage cleanup blocks local storage admission.');
    }

    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $statement = $this->prepare(
            "DELETE FROM s3_storage_garbage
             WHERE bucket = ? AND upload_id = ? AND storage_path = ?
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id = ? AND next_attempt_at > ?",
        );
        $statement->execute([$bucket, $uploadId, $storagePath, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $this->multipartWriteIntentNow()]);

        return $statement->rowCount() === 1;
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('Bucket multipart cleanup must be staged inside a transaction.');
        }
        $unknown = $this->prepare('SELECT 1 FROM s3_multipart_uploads WHERE bucket = ? AND (storage_tier IS NULL OR storage_tier = ?) LIMIT 1');
        $unknown->execute([$bucket, '']);
        if ($unknown->fetchColumn() !== false) {
            throw new \RuntimeException('Legacy multipart upload has no pinned storage tier.');
        }
        try {
            $this->prepare(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at)
             SELECT u.bucket, u.storage_tier, p.storage_path, 'delete_path', u.key_name, u.upload_id, u.owner_id, ?
             FROM s3_multipart_uploads AS u
             JOIN s3_parts AS p ON p.upload_id = u.upload_id
             WHERE u.bucket = ?",
            )->execute([$this->multipartWriteIntentNow(), $bucket]);
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function stageBucketMultipartCleanupForBucket(BucketInfo $bucket, string $storageTier): void
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('Bucket multipart cleanup must be staged inside a transaction.');
        }
        $stale = $this->prepare(
            "SELECT 1 FROM s3_multipart_uploads WHERE bucket = ?
               AND NOT (bucket_generation = ? OR (bucket_generation = '' AND owner_id = ?)) LIMIT 1",
        );
        $stale->execute([$bucket->name, $bucket->generation, $bucket->ownerId]);
        if ($stale->fetchColumn() !== false) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }
        $this->stageBucketMultipartCleanup($bucket->name, $storageTier);
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->prepare(
            "DELETE FROM s3_storage_garbage
             WHERE bucket = ? AND storage_tier = ? AND storage_path = ? AND operation = 'delete_path'
               AND status = 'pending'",
        )->execute([$bucket, $storageTier, $storagePath]);
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        if ($this->connection()->inTransaction()) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        return $this->transaction(function () use ($limit): array {
            $now = $this->multipartWriteIntentNow();
            $leaseExpiresAt = QueueLease::expiresAt($now);
            $this->prepare(
                "UPDATE s3_storage_garbage SET status = 'pending'
                 WHERE status = 'processing' AND next_attempt_at < ?",
            )->execute([$now]);
            $stmt = $this->prepare(
                "SELECT id, bucket, storage_tier, storage_path, operation, key_name, upload_id, attempts, lease_generation, dispatch_id
                 FROM s3_storage_garbage
                 WHERE status = 'pending' AND next_attempt_at <= ?
                   AND NOT (operation = 'part_write_intent' AND dispatch_id IS NULL)
                 ORDER BY id LIMIT ?",
            );
            $stmt->execute([$now, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $this->prepare(
                    "UPDATE s3_storage_garbage
                     SET status = 'processing', next_attempt_at = ?, lease_generation = lease_generation + 1
                     WHERE id = ?",
                )->execute([sprintf('%.17F', $leaseExpiresAt), (int) $row['id']]);
            }

            return array_values(array_map(static fn(array $row): array => [
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
            ], $rows));
        });
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        $sql = 'DELETE FROM s3_storage_garbage WHERE id = ?';
        $params = [$id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = ?";
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        $statement = $this->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        $statement = $this->prepare(
            "DELETE FROM s3_storage_garbage
             WHERE id = ? AND status = 'processing' AND next_attempt_at = ? AND lease_generation = ?",
        );
        $statement->execute([$id, sprintf('%.17F', $leaseExpiresAt), $leaseGeneration]);

        return $statement->rowCount() === 1;
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        $statement = $this->prepare(
            "UPDATE s3_storage_garbage SET next_attempt_at = ?
             WHERE id = ? AND status = 'processing' AND next_attempt_at = ? AND lease_generation = ?
               AND next_attempt_at > (julianday('now') - 2440587.5) * 86400.0 AND ? > next_attempt_at",
        );
        $statement->execute([sprintf('%.17F', $newLeaseExpiresAt), $id, sprintf('%.17F', $expectedLeaseExpiresAt), $leaseGeneration, $newLeaseExpiresAt]);

        return $statement->rowCount() === 1;
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
            $params[] = sprintf('%.17F', $expectedLeaseExpiresAt);
        }
        $statement = $this->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        $statement = $this->prepare(
            "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = ?, next_attempt_at = ?
             WHERE id = ? AND status = 'processing' AND next_attempt_at = ? AND lease_generation = ?",
        );
        $statement->execute([$error, $nextAttemptAt, $id, sprintf('%.17F', $leaseExpiresAt), $leaseGeneration]);

        return $statement->rowCount() === 1;
    }

    // ===============================================================
    // Transaction support
    // ===============================================================

    public function beginTransaction(): void
    {
        $connection = $this->connection();
        if ($connection->inTransaction()) {
            throw new \LogicException('Nested beginTransaction() is unsupported; use transaction() to join the current transaction.');
        }
        $connection->beginTransaction();
        $this->immediateTransaction = false;
        $this->deferredTransactionMutated = false;
        $this->objectPartLayoutPublicationIds = [];
    }

    public function commit(): void
    {
        $connection = $this->connection();
        if (! $connection->inTransaction()) {
            throw new \LogicException('commit() requires an active transaction.');
        }
        try {
            $connection->commit();
        } finally {
            $this->immediateTransaction = false;
            $this->deferredTransactionMutated = false;
            $this->objectPartLayoutPublicationIds = [];
        }
    }

    public function rollback(): void
    {
        $pdo = $this->connection();
        if (! $pdo->inTransaction()) {
            throw new \LogicException('rollback() requires an active transaction.');
        }
        try {
            $pdo->rollBack();
        } catch (\Throwable $error) {
            $this->discardConnection();
            throw $error;
        } finally {
            $this->immediateTransaction = false;
            $this->deferredTransactionMutated = false;
            $this->objectPartLayoutPublicationIds = [];
        }

        // After rollback, prepared statements may reference invalidated transaction
        // state. Clear the cache to force re-preparation on next use.
        $this->stmtCache = [];
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $connection = $this->connection();
        if (! $connection->inTransaction()) {
            throw new \LogicException('lockOwnerForUpdate() requires an active transaction.');
        }
        if ($this->immediateTransaction) {
            return;
        }
        if ($this->deferredTransactionMutated) {
            throw new \LogicException('lockOwnerForUpdate() must be acquired before transactional metadata mutations.');
        }

        // Replace the deferred transaction before its first mutation so
        // competing workers wait at BEGIN instead of failing while promoting
        // a WAL snapshot. Callers re-read guarded state after taking the lock.
        $connection->rollBack();
        $this->stmtCache = [];
        $this->beginImmediateTransaction($connection);
    }

    public function prepareOwnerReadLock(string $ownerId): void
    {
        // SQLite read transactions use their snapshot as the read fence and
        // therefore need no durable owner-lock row.
    }

    public function lockOwnerForRead(string $ownerId): void
    {
        if (! $this->connection()->inTransaction()) {
            throw new \LogicException('lockOwnerForRead() requires an active transaction.');
        }
        // The transaction snapshot is established by the authorization reads.
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $ownTx = ! $pdo->inTransaction();

        if ($ownTx) {
            $pdo->beginTransaction();
            $this->immediateTransaction = false;
            $this->deferredTransactionMutated = false;
            $this->objectPartLayoutPublicationIds = [];
        }

        try {
            $result = $callback();

            if ($ownTx) {
                try {
                    $pdo->commit();
                } finally {
                    $this->immediateTransaction = false;
                    $this->deferredTransactionMutated = false;
                    $this->objectPartLayoutPublicationIds = [];
                }
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                    // Preserve the callback or ambiguous commit failure.
                    $this->discardConnection();
                } finally {
                    $this->immediateTransaction = false;
                    $this->deferredTransactionMutated = false;
                    $this->objectPartLayoutPublicationIds = [];
                    $this->stmtCache = [];
                }
            }

            throw $e;
        }
    }

    // ===============================================================
    // Internal helpers
    // ===============================================================

    private function discardConnection(): void
    {
        $this->stmtCache = [];
        $this->immediateTransaction = false;
        $this->deferredTransactionMutated = false;
        $this->objectPartLayoutPublicationIds = [];
        $this->pdo = null;
    }

    private function translateStorageCleanupCapacity(\Throwable $error): never
    {
        if (str_contains($error->getMessage(), 'Storage cleanup capacity is exhausted.')) {
            throw new \OpsFour\S3Server\Exception\StorageCleanupCapacityException(previous: $error);
        }
        throw $error;
    }

    private function beginImmediateTransaction(\PDO $pdo): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
        $this->immediateTransaction = true;
        $this->deferredTransactionMutated = false;
        $this->objectPartLayoutPublicationIds = [];
    }

    /**
     * Ensure the PDO connection is established.
     */
    private function ensureConnection(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        if ($this->readOnly && ! is_file($this->databasePath)) {
            throw new \RuntimeException('SQLite metadata file does not exist.');
        }
        $dsn = $this->readOnly
            ? 'sqlite:file:' . str_replace('%2F', '/', rawurlencode($this->databasePath)) . '?mode=ro'
            : 'sqlite:' . $this->databasePath;

        $this->pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Apply connection-scoped durability and performance settings here.
        // Schema migrations may not run when an existing database is current.
        $pragmas = $this->readOnly
            ? [
                'PRAGMA synchronous=FULL',
                'PRAGMA query_only=ON',
                'PRAGMA busy_timeout=5000',
                'PRAGMA cache_size=-64000',
                'PRAGMA mmap_size=268435456',
            ]
            : [
                'PRAGMA synchronous=FULL',
                'PRAGMA busy_timeout=5000',
                'PRAGMA foreign_keys=ON',
                'PRAGMA cache_size=-64000',
                'PRAGMA mmap_size=268435456',
            ];
        foreach ($pragmas as $pragma) {
            $this->pdo->query($pragma);
        }
    }

    /**
     * Get the PDO connection, initializing if necessary.
     */
    private function connection(): \PDO
    {
        $this->ensureConnection();
        \assert($this->pdo instanceof \PDO);

        return $this->pdo;
    }

    /**
     * Prepare reads afresh so their cursors are released after each call.
     * Reusing a SELECT statement can retain a SQLite WAL read snapshot and
     * hide commits made by another connection until that cursor is closed.
     * Statements without result cursors remain cached.
     */
    private function prepare(string $sql): \PDOStatement
    {
        if ($this->connection()->inTransaction()
            && ! $this->immediateTransaction
            && preg_match('/^\s*(?:SELECT|WITH|PRAGMA)\b/i', $sql) !== 1) {
            $this->deferredTransactionMutated = true;
        }
        if (preg_match('/^\s*(?:SELECT|WITH)\b/i', $sql) === 1) {
            return $this->connection()->prepare($sql);
        }

        return $this->stmtCache[$sql] ??= $this->connection()->prepare($sql);
    }

    /**
     * Execute a prepared statement with a short retry loop for SQLite writer
     * contention. A failed busy execute can invalidate the PDOStatement, so the
     * cached statement is dropped before retrying.
     *
     * @param  list<mixed>  $params
     */
    private function executeWithRetry(string $sql, array $params): void
    {
        $attempts = 0;

        while (true) {
            try {
                $this->prepare($sql)->execute($params);

                return;
            } catch (\PDOException $e) {
                unset($this->stmtCache[$sql]);

                if (! self::isSqliteBusy($e) || $attempts >= 5) {
                    throw $e;
                }

                usleep(50_000 * (2 ** $attempts));
                $attempts++;
            }
        }
    }

    private static function isSqliteBusy(\PDOException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'SQLSTATE[HY000]: General error: 5');
    }

    /**
     * Convert a database row to a BucketInfo DTO.
     *
     * @param  array<string, mixed>  $row  The database row.
     */
    private function rowToBucketInfo(array $row): BucketInfo
    {
        return new BucketInfo(
            name: $row['name'],
            ownerId: $row['owner_id'],
            region: $row['region'],
            creationDate: new \DateTimeImmutable($row['created_at']),
            generation: $row['generation'],
        );
    }

    /**
     * Convert a database row to an ObjectInfo DTO.
     *
     * Maps additional columns (storage_path, content_encoding, etc.)
     * into the systemMetadata array for transport.
     *
     * @param  array<string, mixed>  $row  The database row.
     */
    private function rowToObjectInfo(array $row): ObjectInfo
    {
        $userMetadata = is_string($row['user_metadata'])
            ? json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['user_metadata'] ?? []);

        // Build system metadata from non-core columns.
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

        // Track is_latest for version listing responses.
        if (isset($row['is_latest'])) {
            $systemMetadata['isLatest'] = (bool) $row['is_latest'] ? '1' : '0';
        }
        if (isset($row['created_at'])) {
            $systemMetadata['createdAt'] = (string) $row['created_at'];
        }
        if (isset($row['noncurrent_at'])) {
            $systemMetadata['noncurrentAt'] = (string) $row['noncurrent_at'];
        }

        $versionId = ($row['version_id'] ?? 'null') === 'null' ? null : $row['version_id'];

        $lastModified = isset($row['updated_at'])
            ? new \DateTimeImmutable($row['updated_at'])
            : new \DateTimeImmutable();
        $restoreExpiresAt = isset($row['restore_expires_at']) && $row['restore_expires_at'] !== ''
            ? new \DateTimeImmutable($row['restore_expires_at'])
            : null;

        return new ObjectInfo(
            bucket: $row['bucket'],
            key: $row['key_name'],
            size: (int) $row['size'],
            etag: $row['etag'],
            contentType: $row['content_type'] ?? 'application/octet-stream',
            storageClass: $row['storage_class'] ?? 'STANDARD',
            storageTier: $row['storage_tier'] ?? ($row['storage_class'] ?? 'STANDARD'),
            transitionStatus: $row['transition_status'] ?? 'available',
            transitionTargetTier: $row['transition_target_tier'] ?? null,
            transitionError: $row['transition_error'] ?? null,
            restoreStatus: $row['restore_status'] ?? null,
            restoredStoragePath: $row['restored_storage_path'] ?? null,
            restoreExpiresAt: $restoreExpiresAt,
            ownerId: $row['owner_id'] ?? '',
            versionId: $versionId,
            isDeleteMarker: (bool) ($row['is_delete_marker'] ?? false),
            userMetadata: $userMetadata,
            systemMetadata: $systemMetadata,
            lastModified: $lastModified,
            restoredStorageTier: $row['restored_storage_tier'] ?? null,
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

        return new ObjectPartLayoutSummary(
            (string) $row['part_layout_kind'],
            (int) $row['part_layout_count'],
            $row['part_layout_compat_count'] === null ? null : (int) $row['part_layout_compat_count'],
            (string) $row['part_layout_digest'],
        );
    }

    /**
     * @param  array<string, int|float|string|null>  $row
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
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $row
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
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
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

    /**
     * Escape special characters in a LIKE pattern.
     *
     * SQLite uses '%' and '_' as wildcards in LIKE. We need to escape
     * any literal occurrences of these in the prefix.
     *
     * @param  string  $pattern  The raw pattern (e.g., a key prefix).
     * @return string The escaped pattern safe for use in LIKE.
     */
    private function escapeLikePattern(string $pattern): string
    {
        // Escape the escape character first, then the wildcards.
        $pattern = str_replace('\\', '\\\\', $pattern);
        $pattern = str_replace('%', '\\%', $pattern);
        $pattern = str_replace('_', '\\_', $pattern);

        return $pattern;
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
