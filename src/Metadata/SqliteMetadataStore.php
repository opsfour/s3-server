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
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
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
final class SqliteMetadataStore implements MetadataStore
{
    private ?\PDO $pdo = null;

    /** @var array<string, \PDOStatement> Cached prepared statements keyed by SQL. */
    private array $stmtCache = [];

    /**
     * @param  string  $databasePath  Absolute path to the SQLite database file.
     *                                Use ':memory:' for in-memory databases (testing).
     */
    public function __construct(
        private readonly string $databasePath,
    ) {}

    // ===============================================================
    // Initialization
    // ===============================================================

    public function initialize(): void
    {
        $this->ensureConnection();
        \assert($this->pdo instanceof \PDO);

        $schemaManager = new SchemaManager($this->pdo);
        $schemaManager->migrate();
    }

    // ===============================================================
    // Bucket operations
    // ===============================================================

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $sql = 'INSERT INTO s3_buckets (name, owner_id, region) VALUES (?, ?, ?)';

        try {
            $this->executeWithRetry($sql, [$bucket, $ownerId, $region]);
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
        // Verify the bucket exists and get its owner.
        $stmt = $this->prepare('SELECT owner_id FROM s3_buckets WHERE name = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new NoSuchBucketException(
                'The specified bucket does not exist.',
            );
        }

        if ($row['owner_id'] !== $ownerId) {
            throw new AccessDeniedException('Access Denied');
        }

        // Verify the bucket is empty.
        $objectCount = $this->countObjects($bucket);
        if ($objectCount > 0) {
            throw new BucketNotEmptyException(
                'The bucket you tried to delete is not empty.',
            );
        }

        // Auto-abort any outstanding multipart uploads (AWS S3 behavior since 2023).
        $this->prepare('DELETE FROM s3_parts WHERE upload_id IN (SELECT upload_id FROM s3_multipart_uploads WHERE bucket = ?)')->execute([$bucket]);
        $this->prepare('DELETE FROM s3_multipart_uploads WHERE bucket = ?')->execute([$bucket]);

        $stmt = $this->prepare('DELETE FROM s3_buckets WHERE name = ? AND owner_id = ?');
        $stmt->execute([$bucket, $ownerId]);
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        $stmt = $this->prepare(
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE name = ?',
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
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE owner_id = ? ORDER BY name ASC',
        );
        $stmt->execute([$ownerId]);

        $buckets = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function listAllBuckets(): array
    {
        $pdo = $this->connection();

        $stmt = $pdo->query(
            'SELECT name, owner_id, region, created_at FROM s3_buckets ORDER BY name ASC',
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

        // INSERT OR REPLACE: if the key already exists with version_id='null',
        // this atomically replaces the row. For versioned buckets (Phase 5),
        // the logic will differ.
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
                restore_expires_at = NULL,
                user_metadata = excluded.user_metadata,
                checksum_crc32 = excluded.checksum_crc32,
                checksum_crc32c = excluded.checksum_crc32c,
                checksum_sha1 = excluded.checksum_sha1,
                checksum_sha256 = excluded.checksum_sha256,
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
                restore_status, restored_storage_path, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
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
            gmdate('Y-m-d\TH:i:s\Z'),
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
                transition_error = ?,
                updated_at = ?
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
        $this->assertObjectTargetExists($bucket, $key, $versionId);

        $where = $versionId === null
            ? 'bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0'
            : 'bucket = ? AND key_name = ? AND version_id = ?';
        $params = [
            $restoreStatus,
            $restoredStoragePath,
            $restoreExpiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            gmdate('Y-m-d\TH:i:s\Z'),
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
                restore_expires_at = ?,
                updated_at = ?
            WHERE {$where}
            SQL);
        $stmt->execute($params);
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

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        $stmt = $this->prepare(
            'SELECT max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner FROM s3_account_quotas WHERE owner_id = ?',
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
        );
    }

    public function listAccountQuotas(): array
    {
        $stmt = $this->prepare(
            'SELECT owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner FROM s3_account_quotas ORDER BY owner_id ASC',
        );
        $stmt->execute();

        $quotas = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $quotas[(string) $row['owner_id']] = new QuotaConfig(
                maxBucketsPerOwner: (int) $row['max_buckets_per_owner'],
                maxObjectsPerBucket: (int) $row['max_objects_per_bucket'],
                maxBytesPerBucket: (int) $row['max_bytes_per_bucket'],
                maxBytesPerOwner: (int) $row['max_bytes_per_owner'],
            );
        }

        return $quotas;
    }

    public function putAccountQuota(string $ownerId, QuotaConfig $quota): void
    {
        $stmt = $this->prepare(
            'INSERT INTO s3_account_quotas (owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner, updated_at)
             VALUES (?, ?, ?, ?, ?, strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
             ON CONFLICT(owner_id) DO UPDATE SET
                max_buckets_per_owner = excluded.max_buckets_per_owner,
                max_objects_per_bucket = excluded.max_objects_per_bucket,
                max_bytes_per_bucket = excluded.max_bytes_per_bucket,
                max_bytes_per_owner = excluded.max_bytes_per_owner,
                updated_at = excluded.updated_at',
        );
        $stmt->execute([
            $ownerId,
            $quota->maxBucketsPerOwner,
            $quota->maxObjectsPerBucket,
            $quota->maxBytesPerBucket,
            $quota->maxBytesPerOwner,
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
        $stmt = $this->prepare(<<<'SQL'
            INSERT INTO s3_account_policies (owner_id, policy_json)
            VALUES (?, ?)
            ON CONFLICT(owner_id) DO UPDATE SET
                policy_json = excluded.policy_json,
                updated_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL);
        $stmt->execute([$ownerId, $policyJson]);
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $stmt = $this->prepare('DELETE FROM s3_account_policies WHERE owner_id = ?');
        $stmt->execute([$ownerId]);
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $stmt = $this->prepare('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?');
        $stmt->execute([$policyName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? (string) $row['policy_json'] : null;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
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

        // Decode continuation token: it's a base64-encoded key name.
        $effectiveStartAfter = $startAfter;
        if ($continuationToken !== null) {
            $decoded = base64_decode($continuationToken, true);
            if ($decoded !== false) {
                $effectiveStartAfter = $decoded;
            }
        }

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

        // Build query to fetch enough rows for delimiter grouping.
        // We fetch more than maxKeys because delimiter grouping can collapse
        // many rows into a single common prefix entry.
        $sql = 'SELECT bucket, key_name, version_id, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, created_at, updated_at '
            . 'FROM s3_objects WHERE bucket = ? AND is_latest = 1 AND is_delete_marker = 0';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
        }

        if ($effectiveStartAfter !== null && $effectiveStartAfter !== '') {
            $sql .= ' AND key_name > ?';
            $params[] = $effectiveStartAfter;
        }

        $sql .= ' ORDER BY key_name ASC';

        // If no delimiter, we can limit directly. Otherwise, we need to fetch
        // enough rows to fill maxKeys entries after grouping.
        if ($delimiter === null || $delimiter === '') {
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

        // With delimiter: we need to group keys by common prefix.
        // Fetch a generous number of rows and group them.
        // We use a large fetch limit to handle cases where many keys share
        // the same common prefix.
        $fetchLimit = $maxKeys * 5 + 200;
        $sql .= ' LIMIT ?';
        $params[] = $fetchLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));

        return $this->groupByDelimiter(
            rows: $rows,
            bucket: $bucket,
            prefix: $prefix ?? '',
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            startAfter: $startAfter,
            continuationToken: $continuationToken,
            fetchedAll: count($rows) < $fetchLimit,
        );
    }

    /**
     * Group fetched rows by delimiter to produce objects and common prefixes.
     *
     * The S3 listing specification requires that:
     * 1. Keys that contain the delimiter after the prefix are grouped into common prefixes.
     * 2. Keys that do NOT contain the delimiter after the prefix are returned as objects.
     * 3. Each common prefix counts as 1 towards maxKeys.
     * 4. Each object counts as 1 towards maxKeys.
     *
     * @param  list<array<string, mixed>>  $rows  Database rows sorted by key_name ASC.
     * @param  string  $bucket  The bucket name.
     * @param  string  $prefix  The prefix filter.
     * @param  string  $delimiter  The delimiter character.
     * @param  int  $maxKeys  Maximum entries to return.
     * @param  string|null  $startAfter  The startAfter parameter.
     * @param  string|null  $continuationToken  The continuation token used.
     * @param  bool  $fetchedAll  Whether all matching rows were fetched.
     */
    private function groupByDelimiter(
        array $rows,
        string $bucket,
        string $prefix,
        string $delimiter,
        int $maxKeys,
        ?string $startAfter,
        ?string $continuationToken,
        bool $fetchedAll,
    ): ListObjectsResult {
        $objects = [];
        $commonPrefixes = [];
        $seenPrefixes = [];
        $entryCount = 0;
        $isTruncated = false;
        $lastKeyProcessed = null;

        $prefixLen = strlen($prefix);

        foreach ($rows as $row) {
            if ($entryCount >= $maxKeys) {
                $isTruncated = true;
                break;
            }

            $keyName = $row['key_name'];
            $lastKeyProcessed = $keyName;

            // Find the delimiter in the key AFTER the prefix.
            $afterPrefix = substr($keyName, $prefixLen);
            $delimPos = strpos($afterPrefix, $delimiter);

            if ($delimPos !== false) {
                // This key has the delimiter after the prefix.
                // The common prefix is: prefix + everything up to and including the delimiter.
                $commonPrefix = $prefix . substr($afterPrefix, 0, $delimPos + strlen($delimiter));

                if (! isset($seenPrefixes[$commonPrefix])) {
                    $seenPrefixes[$commonPrefix] = true;
                    $commonPrefixes[] = $commonPrefix;
                    $entryCount++;
                }
                // Skip this row — it's grouped under a common prefix (no entry counted).
            } else {
                // No delimiter after prefix — this is a direct object.
                $objects[] = $this->rowToObjectInfo($row);
                $entryCount++;
            }
        }

        // If we didn't exhaust maxKeys but also didn't fetch all rows,
        // then we need to consider it potentially truncated.
        if (! $isTruncated && ! $fetchedAll) {
            // We fetched a large batch but it wasn't enough.
            // The result may still be truncated.
            $isTruncated = true;
        }

        // Sort common prefixes (they should already be sorted since keys are sorted,
        // but ensure correctness).
        sort($commonPrefixes, SORT_STRING);

        $nextContinuationToken = null;
        if ($isTruncated && $lastKeyProcessed !== null) {
            $nextContinuationToken = base64_encode($lastKeyProcessed);
        }

        return new ListObjectsResult(
            name: $bucket,
            prefix: $prefix,
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            isTruncated: $isTruncated,
            keyCount: count($objects) + count($commonPrefixes),
            objects: $objects,
            commonPrefixes: $commonPrefixes,
            startAfter: $startAfter,
            continuationToken: $continuationToken,
            nextContinuationToken: $nextContinuationToken,
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
        $stmt = $this->prepare(
            <<<'SQL'
            INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, content_type, user_metadata)
            VALUES (?, ?, ?, ?, ?, ?)
            SQL,
        );

        $stmt->execute([
            $uploadId,
            $bucket,
            $key,
            $ownerId,
            $contentType ?? 'application/octet-stream',
            json_encode($userMetadata, JSON_THROW_ON_ERROR),
        ]);
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        $stmt = $this->prepare(
            'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at '
            . 'FROM s3_multipart_uploads WHERE upload_id = ?',
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['user_metadata'] = json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR);

        return $row;
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
        $pdo = $this->connection();

        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, created_at '
            . 'FROM s3_multipart_uploads WHERE bucket = ?';
        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
        }

        // Pagination via key-marker / upload-id-marker.
        if ($keyMarker !== null && $keyMarker !== '') {
            if ($uploadIdMarker !== null && $uploadIdMarker !== '') {
                $sql .= ' AND (key_name > ? OR (key_name = ? AND upload_id > ?))';
                $params[] = $keyMarker;
                $params[] = $keyMarker;
                $params[] = $uploadIdMarker;
            } else {
                $sql .= ' AND key_name > ?';
                $params[] = $keyMarker;
            }
        }

        $effectiveMax = max(1, min(1000, $maxUploads));
        $sql .= ' ORDER BY key_name ASC, upload_id ASC LIMIT ?';
        $params[] = $effectiveMax + 1; // Fetch one extra to detect truncation.

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $isTruncated = count($rows) > $effectiveMax;
        if ($isTruncated) {
            $rows = array_slice($rows, 0, $effectiveMax);
        }

        // Delimiter grouping.
        $commonPrefixes = [];
        $uploads = [];

        if ($delimiter !== null && $delimiter !== '') {
            $prefixLen = strlen($prefix ?? '');
            $seen = [];

            foreach ($rows as $row) {
                $keyName = $row['key_name'];
                $afterPrefix = substr($keyName, $prefixLen);
                $delimPos = strpos($afterPrefix, $delimiter);

                if ($delimPos !== false) {
                    $commonPrefix = ($prefix ?? '') . substr($afterPrefix, 0, $delimPos + strlen($delimiter));
                    if (! isset($seen[$commonPrefix])) {
                        $seen[$commonPrefix] = true;
                        $commonPrefixes[] = $commonPrefix;
                    }
                } else {
                    $uploads[] = $row;
                }
            }
        } else {
            $uploads = $rows;
        }

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
            'commonPrefixes' => $commonPrefixes,
        ];
    }

    public function putPart(
        string $uploadId,
        int $partNumber,
        string $etag,
        int $size,
        string $storagePath,
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
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(upload_id, part_number) DO UPDATE SET
                etag = excluded.etag,
                size = excluded.size,
                storage_path = excluded.storage_path,
                created_at = (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            SQL,
        );

        $stmt->execute([$uploadId, $partNumber, $etag, $size, $storagePath]);
    }

    public function getParts(string $uploadId): array
    {
        $stmt = $this->prepare(
            'SELECT part_number, etag, size, storage_path, created_at '
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

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // Mark all previous versions of the same key as non-latest.
            $stmt = $this->prepare(
                'UPDATE s3_objects SET is_latest = 0 WHERE bucket = ? AND key_name = ? AND is_latest = 1',
            );
            $stmt->execute([$bucket, $key]);

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

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        $pdo = $this->connection();
        $versionId = $suspended ? 'null' : bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // Mark all previous versions as non-latest (both enabled and suspended).
            // Under suspended versioning, the delete marker with version_id='null' must become
            // the sole is_latest=1 row — any previously-latest versioned row must be demoted.
            $stmt = $this->prepare(
                'UPDATE s3_objects SET is_latest = 0 WHERE bucket = ? AND key_name = ? AND is_latest = 1',
            );
            $stmt->execute([$bucket, $key]);

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
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
                        is_delete_marker = 1,
                        owner_id = excluded.owner_id,
                        etag = '',
                        size = 0,
                        storage_path = '',
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
                restore_status, restored_storage_path, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
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

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // Delete the specific version row.
            $stmt = $this->prepare(
                'DELETE FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ?',
            );
            $stmt->execute([$bucket, $key, $versionId]);

            // If it was the latest, promote the next most recent version.
            if (!empty($objectInfo->systemMetadata['isLatest'])) {
                $stmt = $this->prepare(
                    <<<'SQL'
                    UPDATE s3_objects
                    SET is_latest = 1
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
                'isTruncated' => false,
                'nextKeyMarker' => null,
                'nextVersionIdMarker' => null,
                'commonPrefixes' => [],
            ];
        }

        // Build query: all versions (including delete markers).
        $sql = 'SELECT bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, created_at, updated_at '
            . 'FROM s3_objects WHERE bucket = ?';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Resume after specific key+version: resolve the id of the marker version
                // to use id-based pagination that matches the sort order (created_at DESC, id DESC).
                $markerStmt = $this->prepare(
                    'SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ? LIMIT 1',
                );
                $markerStmt->execute([$bucket, $keyMarker, $versionIdMarker]);
                $markerRow = $markerStmt->fetch(\PDO::FETCH_ASSOC);

                if ($markerRow !== false) {
                    $sql .= ' AND (key_name > ? OR (key_name = ? AND id < ?))';
                    $params[] = $keyMarker;
                    $params[] = $keyMarker;
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

        $sql .= ' ORDER BY key_name ASC, created_at DESC, id DESC';

        if ($delimiter === null || $delimiter === '') {
            // No delimiter: straightforward limit.
            $sql .= ' LIMIT ?';
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

            foreach ($rows as $row) {
                $info = $this->rowToObjectInfo($row);
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
                'isTruncated' => $isTruncated,
                'nextKeyMarker' => $nextKeyMarker,
                'nextVersionIdMarker' => $nextVersionIdMarker,
                'commonPrefixes' => [],
            ];
        }

        // With delimiter: group by common prefix.
        $fetchLimit = $maxKeys * 5 + 200;
        $sql .= ' LIMIT ?';
        $params[] = $fetchLimit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));

        /** @var array{versions: list<ObjectInfo>, deleteMarkers: list<ObjectInfo>, isTruncated: bool, nextKeyMarker: ?string, nextVersionIdMarker: ?string, commonPrefixes: list<string>} */
        return $this->groupVersionsByDelimiter(
            rows: $rows,
            prefix: $prefix ?? '',
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            fetchedAll: count($rows) < $fetchLimit,
        );
    }

    /**
     * Group version rows by delimiter for ListObjectVersions.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{versions: list<ObjectInfo>, deleteMarkers: list<ObjectInfo>, isTruncated: bool, nextKeyMarker: ?string, nextVersionIdMarker: ?string, commonPrefixes: list<string>}
     */
    private function groupVersionsByDelimiter(
        array $rows,
        string $prefix,
        string $delimiter,
        int $maxKeys,
        bool $fetchedAll,
    ): array {
        $versions = [];
        $deleteMarkers = [];
        $commonPrefixes = [];
        $seenPrefixes = [];
        $entryCount = 0;
        $isTruncated = false;
        $lastRow = null;

        $prefixLen = strlen($prefix);

        foreach ($rows as $row) {
            if ($entryCount >= $maxKeys) {
                $isTruncated = true;
                break;
            }

            $keyName = $row['key_name'];
            $lastRow = $row;

            $afterPrefix = substr($keyName, $prefixLen);
            $delimPos = strpos($afterPrefix, $delimiter);

            if ($delimPos !== false) {
                $commonPrefix = $prefix . substr($afterPrefix, 0, $delimPos + strlen($delimiter));

                if (! isset($seenPrefixes[$commonPrefix])) {
                    $seenPrefixes[$commonPrefix] = true;
                    $commonPrefixes[] = $commonPrefix;
                    $entryCount++;
                }
            } else {
                $info = $this->rowToObjectInfo($row);
                if ((bool) $row['is_delete_marker']) {
                    $deleteMarkers[] = $info;
                } else {
                    $versions[] = $info;
                }
                $entryCount++;
            }
        }

        if (! $isTruncated && ! $fetchedAll) {
            $isTruncated = true;
        }

        sort($commonPrefixes, SORT_STRING);

        $nextKeyMarker = null;
        $nextVersionIdMarker = null;
        if ($isTruncated && $lastRow !== null) {
            $nextKeyMarker = $lastRow['key_name'];
            $nextVersionIdMarker = $lastRow['version_id'];
        }

        return [
            'versions' => $versions,
            'deleteMarkers' => $deleteMarkers,
            'isTruncated' => $isTruncated,
            'nextKeyMarker' => $nextKeyMarker,
            'nextVersionIdMarker' => $nextVersionIdMarker,
            'commonPrefixes' => $commonPrefixes,
        ];
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

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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

    public function getObjectTagging(string $bucket, string $key): array
    {
        $stmt = $this->prepare(
            'SELECT tag_key, tag_value FROM s3_tagging '
            . "WHERE resource_type = 'object' AND bucket = ? AND key_name = ? ORDER BY id ASC",
        );
        $stmt->execute([$bucket, $key]);

        $tags = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tags[] = [
                'key' => $row['tag_key'],
                'value' => $row['tag_value'],
            ];
        }

        return $tags;
    }

    public function putObjectTagging(string $bucket, string $key, array $tags): void
    {
        $pdo = $this->connection();

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $this->prepare(
                "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ?",
            );
            $stmt->execute([$bucket, $key]);

            $stmt = $this->prepare(
                'INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) '
                . "VALUES ('object', ?, ?, ?, ?)",
            );

            foreach ($tags as $tag) {
                $stmt->execute([$bucket, $key, $tag['key'], $tag['value']]);
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

    public function deleteObjectTagging(string $bucket, string $key): void
    {
        $stmt = $this->prepare(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = ? AND key_name = ?",
        );
        $stmt->execute([$bucket, $key]);
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
            'SELECT allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds '
            . 'FROM s3_cors_rules WHERE bucket = ? ORDER BY rule_order ASC',
        );
        $stmt->execute([$bucket]);

        $rules = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $rules[] = [
                'allowedOrigins' => json_decode($row['allowed_origins'], true, 512, JSON_THROW_ON_ERROR),
                'allowedMethods' => json_decode($row['allowed_methods'], true, 512, JSON_THROW_ON_ERROR),
                'allowedHeaders' => json_decode($row['allowed_headers'], true, 512, JSON_THROW_ON_ERROR),
                'exposeHeaders' => json_decode($row['expose_headers'], true, 512, JSON_THROW_ON_ERROR),
                'maxAgeSeconds' => ((int) $row['max_age_seconds']) !== 0 ? (int) $row['max_age_seconds'] : null,
            ];
        }

        return $rules;
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $pdo = $this->connection();
        $ownTx = !$pdo->inTransaction();

        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $this->prepare('DELETE FROM s3_cors_rules WHERE bucket = ?');
            $stmt->execute([$bucket]);

            $stmt = $this->prepare(
                'INSERT INTO s3_cors_rules (bucket, rule_order, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            );

            foreach ($rules as $index => $rule) {
                $stmt->execute([
                    $bucket,
                    $index,
                    json_encode($rule['allowedOrigins'], JSON_THROW_ON_ERROR),
                    json_encode($rule['allowedMethods'], JSON_THROW_ON_ERROR),
                    json_encode($rule['allowedHeaders'], JSON_THROW_ON_ERROR),
                    json_encode($rule['exposeHeaders'], JSON_THROW_ON_ERROR),
                    $rule['maxAgeSeconds'] ?? 0,
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

        return [
            'sseAlgorithm' => $row['sse_algorithm'],
            'kmsMasterKeyId' => $row['kms_master_key_id'],
            'bucketKeyEnabled' => ((int) $row['bucket_key_enabled']) === 1,
        ];
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
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

    /**
     * @param  list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>  $rules
     */
    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $pdo = $this->connection();

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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
    ): int {
        $stmt = $this->prepare(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
            microtime(true),
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);
        $pdo = $this->connection();
        $this->executeWithRetry(
            "UPDATE s3_tier_transition_jobs SET status = 'pending', updated_at = ?
             WHERE status = 'processing' AND next_attempt_at < ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $now - 300],
        );

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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
                    "UPDATE s3_tier_transition_jobs SET status = 'processing', updated_at = ? WHERE id IN ({$placeholders})",
                );
                $update->execute([gmdate('Y-m-d\TH:i:s\Z'), ...$ids]);
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

        return array_values(array_map($this->rowToTierTransitionJob(...), $rows));
    }

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
    ): void {
        $sql = $incrementAttempts
            ? 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE(?, target_storage_path), updated_at = ? WHERE id = ?'
            : 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), target_storage_path = COALESCE(?, target_storage_path), updated_at = ? WHERE id = ?';
        $stmt = $this->prepare($sql);
        $stmt->execute([
            $status,
            $error,
            $nextAttemptAt,
            $targetStoragePath,
            gmdate('Y-m-d\TH:i:s\Z'),
            $id,
        ]);
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
            microtime(true),
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);
        $pdo = $this->connection();
        $this->executeWithRetry(
            "UPDATE s3_restore_jobs SET status = 'pending', updated_at = ?
             WHERE status = 'processing' AND next_attempt_at < ?",
            [gmdate('Y-m-d\TH:i:s\Z'), $now - 300],
        );

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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
                    "UPDATE s3_restore_jobs SET status = 'processing', updated_at = ? WHERE id IN ({$placeholders})",
                );
                $update->execute([gmdate('Y-m-d\TH:i:s\Z'), ...$ids]);
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

        return array_values(array_map($this->rowToRestoreJob(...), $rows));
    }

    public function updateRestoreJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
    ): void {
        $sql = $incrementAttempts
            ? 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE(?, restored_storage_path), updated_at = ? WHERE id = ?'
            : 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), restored_storage_path = COALESCE(?, restored_storage_path), updated_at = ? WHERE id = ?';
        $stmt = $this->prepare($sql);
        $stmt->execute([
            $status,
            $error,
            $nextAttemptAt,
            $restoredStoragePath,
            gmdate('Y-m-d\TH:i:s\Z'),
            $id,
        ]);
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

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
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
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
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

        $sql = 'SELECT * FROM s3_objects WHERE bucket = ? AND is_latest = 0 AND is_delete_marker = 0 AND created_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
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

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array
    {
        $pdo = $this->connection();

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$daysAfterInitiation} days")
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT upload_id, bucket, key_name FROM s3_multipart_uploads WHERE bucket = ? AND created_at < ?';
        $params = [$bucket, $cutoff];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
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
            $sql .= " AND dm.key_name LIKE ? ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
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
            'SELECT * FROM s3_objects
             WHERE restore_status = ? AND restored_storage_path IS NOT NULL AND restore_expires_at IS NOT NULL AND restore_expires_at <= ?
             ORDER BY restore_expires_at ASC, bucket ASC, key_name ASC, version_id ASC
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
                tokens = MIN(?, tokens + (? - last_refill_at) * ?) - 1.0,
                last_refill_at = ?
             WHERE ip = ? AND (MIN(?, tokens + (? - last_refill_at) * ?)) >= 1.0',
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
    ): void {
        $this->prepare(
            'INSERT INTO s3_notification_queue (bucket, key_name, event_name, destination_url, payload_json, max_attempts, next_attempt_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, microtime(true)]);
    }

    public function dequeueNotifications(int $limit): array
    {
        $now = microtime(true);
        $pdo = $this->connection();

        // Fast-path: skip transaction if queue is empty (avoids write-lock contention).
        $countStmt = $this->prepare("SELECT COUNT(*) FROM s3_notification_queue WHERE status IN ('pending', 'processing')");
        $countStmt->execute();
        if ((int) $countStmt->fetchColumn() === 0) {
            return [];
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            // Reset stale "processing" items (stuck > 5 minutes).
            $this->prepare(
                "UPDATE s3_notification_queue SET status = 'pending'
                 WHERE status = 'processing' AND next_attempt_at < ?",
            )->execute([$now - 300]);

            $stmt = $this->prepare(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts
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
                $pdo->prepare(
                    "UPDATE s3_notification_queue SET status = 'processing' WHERE id IN ({$placeholders})",
                )->execute($ids);
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

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
    ): void {
        if ($incrementAttempts) {
            $this->prepare(
                'UPDATE s3_notification_queue
                 SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1
                 WHERE id = ?',
            )->execute([$status, $error, $nextAttemptAt, $id]);
        } else {
            $this->prepare(
                'UPDATE s3_notification_queue
                 SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at)
                 WHERE id = ?',
            )->execute([$status, $error, $nextAttemptAt, $id]);
        }
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $maxAgeSeconds);
        $this->prepare(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND created_at < ?",
        )->execute([$cutoff]);
    }

    // ===============================================================
    // Transaction support
    // ===============================================================

    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $this->connection()->rollBack();

        // After rollback, prepared statements may reference invalidated transaction
        // state. Clear the cache to force re-preparation on next use.
        $this->stmtCache = [];
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connection();
        $ownTx = !$pdo->inTransaction();

        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($ownTx) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTx) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    // ===============================================================
    // Internal helpers
    // ===============================================================

    /**
     * Ensure the PDO connection is established.
     */
    private function ensureConnection(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $dsn = 'sqlite:' . $this->databasePath;

        $this->pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Enable WAL mode for concurrent reads during writes, and set busy timeout.
        $pragmas = [
            'PRAGMA journal_mode=WAL',
            'PRAGMA synchronous=NORMAL',
            'PRAGMA busy_timeout=5000',
            'PRAGMA foreign_keys=ON',
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
     * Get a cached prepared statement, creating it if necessary.
     */
    private function prepare(string $sql): \PDOStatement
    {
        return $this->stmtCache[$sql] ??= $this->connection()->prepare($sql);
    }

    /**
     * Execute a prepared statement with a short retry loop for SQLite writer
     * contention. A failed busy execute can invalidate the PDOStatement, so the
     * cached statement is dropped before retrying.
     *
     * @param list<mixed> $params
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
        );
    }

    /**
     * @param array<string, int|float|string|null> $row
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
            'targetStoragePath' => $row['target_storage_path'] !== null ? (string) $row['target_storage_path'] : null,
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'maxAttempts' => (int) $row['max_attempts'],
            'nextAttemptAt' => (float) $row['next_attempt_at'],
            'lastError' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, int|float|string|null> $row
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
                  AND lt.tag_key = ?
                  AND lt.tag_value = ?
            )";
            $params[] = $key;
            $params[] = $value;
        }

        return $sql;
    }
}
