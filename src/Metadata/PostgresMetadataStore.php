<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresTransaction;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * PostgreSQL-backed implementation of the S3 metadata store.
 *
 * Uses amphp/postgres for fully async database access. Suitable for
 * multi-node HA deployments.
 */
final class PostgresMetadataStore implements MetadataStore
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, PostgresTransaction> */
    private \WeakMap $fiberTxMap;

    public function __construct(
        private readonly PostgresConnectionPool $pool,
    ) {
        $this->fiberTxMap = new \WeakMap();
    }

    public static function fromDsn(string $dsn): self
    {
        $pool = new PostgresConnectionPool(\Amp\Postgres\PostgresConfig::fromString($dsn));

        return new self($pool);
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
            if ($currentVersion >= PostgresSchema::VERSION) {
                return;
            }
        } catch (\Throwable) {
            // Table doesn't exist yet.
        }

        foreach (PostgresSchema::getCreateStatements() as $sql) {
            $this->pool->execute($sql);
        }

        // Run migration statements for upgrades (indexes use IF NOT EXISTS, safe to re-run).
        foreach (PostgresSchema::getMigrationStatements($currentVersion) as $sql) {
            $this->pool->execute($sql);
        }

        $this->pool->execute(
            'INSERT INTO s3_schema_version (version, description) VALUES ($1, $2)',
            [PostgresSchema::VERSION, 'Schema version ' . PostgresSchema::VERSION],
        );
    }

    // ===============================================================
    // Bucket operations
    // ===============================================================

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO s3_buckets (name, owner_id, region) VALUES ($1, $2, $3)',
                [$bucket, $ownerId, $region],
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'unique constraint')) {
                throw new BucketAlreadyExistsException(
                    'Your previous request to create the named bucket succeeded and you already own it.',
                );
            }
            throw $e;
        }
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $result = $this->conn()->execute(
            'SELECT owner_id FROM s3_buckets WHERE name = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            throw new NoSuchBucketException('The specified bucket does not exist.');
        }

        if ($row['owner_id'] !== $ownerId) {
            throw new AccessDeniedException('Access Denied');
        }

        $objectCount = $this->countObjects($bucket);
        if ($objectCount > 0) {
            throw new BucketNotEmptyException('The bucket you tried to delete is not empty.');
        }

        // Auto-abort any outstanding multipart uploads (AWS S3 behavior since 2023).
        $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id IN (SELECT upload_id FROM s3_multipart_uploads WHERE bucket = $1)', [$bucket]);
        $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE bucket = $1', [$bucket]);

        $this->conn()->execute(
            'DELETE FROM s3_buckets WHERE name = $1 AND owner_id = $2',
            [$bucket, $ownerId],
        );
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE name = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToBucketInfo($row) : null;
    }

    public function listBuckets(string $ownerId): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE owner_id = $1 ORDER BY name ASC',
            [$ownerId],
        );

        $buckets = [];
        foreach ($result as $row) {
            $buckets[] = $this->rowToBucketInfo($row);
        }

        return $buckets;
    }

    public function listAllBuckets(): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at FROM s3_buckets ORDER BY name ASC',
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
            'SELECT 1 FROM s3_buckets WHERE name = $1',
            [$bucket],
        );

        return $result->fetchRow() !== null;
    }

    public function getBucketOwner(string $bucket): ?string
    {
        $result = $this->conn()->execute(
            'SELECT owner_id FROM s3_buckets WHERE name = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['owner_id']) : null;
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

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_objects (
                bucket, key_name, version_id, is_latest, is_delete_marker,
                owner_id, etag, size, content_type, content_encoding,
                content_disposition, cache_control, storage_class, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
            ) VALUES (
                $1, $2, 'null', TRUE, FALSE,
                $3, $4, $5, $6, $7,
                $8, $9, $10, $11,
                $12::jsonb, $13, $14, $15,
                $16, $17::timestamptz, $18::timestamptz
            )
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                is_latest = TRUE,
                is_delete_marker = FALSE,
                owner_id = EXCLUDED.owner_id,
                etag = EXCLUDED.etag,
                size = EXCLUDED.size,
                content_type = EXCLUDED.content_type,
                content_encoding = EXCLUDED.content_encoding,
                content_disposition = EXCLUDED.content_disposition,
                cache_control = EXCLUDED.cache_control,
                storage_class = EXCLUDED.storage_class,
                storage_path = EXCLUDED.storage_path,
                storage_tier = 'STANDARD',
                transition_status = 'available',
                transition_target_tier = NULL,
                transition_error = NULL,
                restore_status = NULL,
                restored_storage_path = NULL,
                restore_expires_at = NULL,
                user_metadata = EXCLUDED.user_metadata,
                checksum_crc32 = EXCLUDED.checksum_crc32,
                checksum_crc32c = EXCLUDED.checksum_crc32c,
                checksum_sha1 = EXCLUDED.checksum_sha1,
                checksum_sha256 = EXCLUDED.checksum_sha256,
                updated_at = EXCLUDED.updated_at
            SQL,
            [
                $bucket, $key,
                $ownerId, $etag, $size, $contentType, $contentEncoding,
                $contentDisposition, $cacheControl, $storageClass, $storagePath,
                $userMetadataJson, $checksumCrc32, $checksumCrc32c, $checksumSha1,
                $checksumSha256, $now, $now,
            ],
        );
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
                restore_status, restored_storage_path, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
            FROM s3_objects
            WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE AND is_delete_marker = FALSE
            SQL,
            [$bucket, $key],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToObjectInfo($row) : null;
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->conn()->execute(
            "DELETE FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'",
            [$bucket, $key],
        );
    }

    public function objectExists(string $bucket, string $key): bool
    {
        $result = $this->conn()->execute(
            'SELECT 1 FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE AND is_delete_marker = FALSE',
            [$bucket, $key],
        );

        return $result->fetchRow() !== null;
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
            ? 'bucket = $8 AND key_name = $9 AND is_latest = TRUE AND is_delete_marker = FALSE'
            : 'bucket = $8 AND key_name = $9 AND version_id = $10';
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

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET storage_class = $1,
                storage_tier = $2,
                storage_path = $3,
                transition_status = $4,
                transition_target_tier = $5,
                transition_error = $6,
                updated_at = $7::timestamptz
            WHERE {$where}
            SQL, $params);
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
            ? 'bucket = $5 AND key_name = $6 AND is_latest = TRUE AND is_delete_marker = FALSE'
            : 'bucket = $5 AND key_name = $6 AND version_id = $7';
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

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET restore_status = $1,
                restored_storage_path = $2,
                restore_expires_at = $3::timestamptz,
                updated_at = $4::timestamptz
            WHERE {$where}
            SQL, $params);
    }

    public function getBucketStats(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = $1 AND is_latest = TRUE AND is_delete_marker = FALSE',
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
            'SELECT COUNT(*) as cnt, COALESCE(SUM(size), 0) as total_size FROM s3_objects WHERE bucket = $1 AND is_delete_marker = FALSE',
            [$bucket],
        );
        $row = $result->fetchRow();

        return [
            'objectCount' => $row !== null ? (int) $row['cnt'] : 0,
            'bytesUsed' => $row !== null ? (int) $row['total_size'] : 0,
        ];
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        $result = $this->conn()->execute(
            'SELECT max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner FROM s3_account_quotas WHERE owner_id = $1',
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
        );
    }

    public function listAccountQuotas(): array
    {
        $result = $this->conn()->execute(
            'SELECT owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner FROM s3_account_quotas ORDER BY owner_id ASC',
        );

        $quotas = [];
        while (($row = $result->fetchRow()) !== null) {
            $quotas[self::str($row['owner_id'])] = new QuotaConfig(
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
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_account_quotas (owner_id, max_buckets_per_owner, max_objects_per_bucket, max_bytes_per_bucket, max_bytes_per_owner, updated_at)
            VALUES ($1, $2, $3, $4, $5, NOW())
            ON CONFLICT(owner_id) DO UPDATE SET
                max_buckets_per_owner = excluded.max_buckets_per_owner,
                max_objects_per_bucket = excluded.max_objects_per_bucket,
                max_bytes_per_bucket = excluded.max_bytes_per_bucket,
                max_bytes_per_owner = excluded.max_bytes_per_owner,
                updated_at = excluded.updated_at
            SQL,
            [$ownerId, $quota->maxBucketsPerOwner, $quota->maxObjectsPerBucket, $quota->maxBytesPerBucket, $quota->maxBytesPerOwner],
        );
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->conn()->execute('DELETE FROM s3_account_quotas WHERE owner_id = $1', [$ownerId]);
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json::text AS policy_json FROM s3_account_policies WHERE owner_id = $1', [$ownerId]);
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['policy_json']) : null;
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_account_policies (owner_id, policy_json) VALUES ($1, $2::jsonb)
            ON CONFLICT(owner_id) DO UPDATE SET policy_json = EXCLUDED.policy_json, updated_at = NOW()
            SQL,
            [$ownerId, $policyJson],
        );
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->conn()->execute('DELETE FROM s3_account_policies WHERE owner_id = $1', [$ownerId]);
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json::text AS policy_json FROM s3_named_policies WHERE policy_name = $1', [$policyName]);
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['policy_json']) : null;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_named_policies (policy_name, policy_json) VALUES ($1, $2::jsonb)
            ON CONFLICT(policy_name) DO UPDATE SET policy_json = EXCLUDED.policy_json, updated_at = NOW()
            SQL,
            [$policyName, $policyJson],
        );
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->conn()->execute('DELETE FROM s3_named_policies WHERE policy_name = $1', [$policyName]);
    }

    public function countObjects(string $bucket): int
    {
        $result = $this->conn()->execute(
            'SELECT 1 FROM s3_objects WHERE bucket = $1 LIMIT 1',
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
        $effectiveStartAfter = $startAfter;
        if ($continuationToken !== null) {
            $decoded = base64_decode($continuationToken, true);
            if ($decoded !== false) {
                $effectiveStartAfter = $decoded;
            }
        }

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
            . 'storage_class, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, created_at, updated_at '
            . 'FROM s3_objects WHERE bucket = $1 AND is_latest = TRUE AND is_delete_marker = FALSE';

        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($effectiveStartAfter !== null && $effectiveStartAfter !== '') {
            $sql .= " AND key_name > \${$paramIndex}";
            $params[] = $effectiveStartAfter;
            $paramIndex++;
        }

        $sql .= ' ORDER BY key_name ASC';

        if ($delimiter === null || $delimiter === '') {
            $sql .= " LIMIT \${$paramIndex}";
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

        $fetchLimit = $maxKeys * 20 + 1000;
        $sql .= " LIMIT \${$paramIndex}";
        $params[] = $fetchLimit;

        $result = $this->conn()->execute($sql, $params);
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }

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
     * @param  list<array<string, mixed>>  $rows
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

            $keyName = (string) $row['key_name'];
            $afterPrefix = substr($keyName, $prefixLen);
            $delimPos = strpos($afterPrefix, $delimiter);

            if ($delimPos !== false) {
                $commonPrefix = $prefix . substr($afterPrefix, 0, $delimPos + strlen($delimiter));
                if (! isset($seenPrefixes[$commonPrefix])) {
                    $seenPrefixes[$commonPrefix] = true;
                    $commonPrefixes[] = $commonPrefix;
                    $entryCount++;
                    $lastKeyProcessed = $keyName;
                }
            } else {
                $objects[] = $this->rowToObjectInfo($row);
                $entryCount++;
                $lastKeyProcessed = $keyName;
            }
        }

        if (! $isTruncated && ! $fetchedAll) {
            $isTruncated = true;
        }

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
        $this->conn()->execute(
            'INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, content_type, user_metadata) VALUES ($1, $2, $3, $4, $5, $6::jsonb)',
            [$uploadId, $bucket, $key, $ownerId, $contentType ?? 'application/octet-stream', json_encode($userMetadata, JSON_THROW_ON_ERROR)],
        );
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        $result = $this->conn()->execute(
            'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE upload_id = $1',
            [$uploadId],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        $userMetadata = is_string($row['user_metadata'])
            ? json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['user_metadata'] ?? []);

        /** @var array<string, string> $userMetadata */

        return [
            'upload_id' => self::str($row['upload_id']),
            'bucket' => self::str($row['bucket']),
            'key_name' => self::str($row['key_name']),
            'owner_id' => self::str($row['owner_id']),
            'content_type' => self::str($row['content_type']),
            'user_metadata' => $userMetadata,
            'created_at' => $this->formatTimestamp($row['created_at']),
        ];
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->deleteParts($uploadId);
        $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = $1', [$uploadId]);
    }

    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, created_at FROM s3_multipart_uploads WHERE bucket = $1';
        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        // Pagination via key-marker / upload-id-marker.
        if ($keyMarker !== null && $keyMarker !== '') {
            if ($uploadIdMarker !== null && $uploadIdMarker !== '') {
                $sql .= " AND (key_name > \${$paramIndex} OR (key_name = \$" . ($paramIndex + 1) . " AND upload_id > \$" . ($paramIndex + 2) . '))';
                $params[] = $keyMarker;
                $params[] = $keyMarker;
                $params[] = $uploadIdMarker;
                $paramIndex += 3;
            } else {
                $sql .= " AND key_name > \${$paramIndex}";
                $params[] = $keyMarker;
                $paramIndex++;
            }
        }

        $effectiveMax = max(1, min(1000, $maxUploads));
        $sql .= " ORDER BY key_name ASC, upload_id ASC LIMIT \${$paramIndex}";
        $params[] = $effectiveMax + 1; // Fetch one extra to detect truncation.

        $result = $this->conn()->execute($sql, $params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, created_at: string}> $rows */
        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'upload_id' => self::str($row['upload_id']),
                'bucket' => self::str($row['bucket']),
                'key_name' => self::str($row['key_name']),
                'owner_id' => self::str($row['owner_id']),
                'content_type' => self::str($row['content_type']),
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

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

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null) {
            throw new NoSuchUploadException('The specified upload does not exist.');
        }

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES ($1, $2, $3, $4, $5)
            ON CONFLICT(upload_id, part_number) DO UPDATE SET
                etag = EXCLUDED.etag,
                size = EXCLUDED.size,
                storage_path = EXCLUDED.storage_path,
                created_at = NOW()
            SQL,
            [$uploadId, $partNumber, $etag, $size, $storagePath],
        );
    }

    public function getParts(string $uploadId): array
    {
        $result = $this->conn()->execute(
            'SELECT part_number, etag, size, storage_path, created_at FROM s3_parts WHERE upload_id = $1 ORDER BY part_number ASC',
            [$uploadId],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => self::int($row['part_number']),
                'etag' => self::str($row['etag']),
                'size' => self::int($row['size']),
                'storage_path' => self::str($row['storage_path']),
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

        return $parts;
    }

    public function deleteParts(string $uploadId): void
    {
        $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id = $1', [$uploadId]);
    }

    // ===============================================================
    // Versioning
    // ===============================================================

    public function getBucketVersioning(string $bucket): string
    {
        $result = $this->conn()->execute('SELECT versioning FROM s3_buckets WHERE name = $1', [$bucket]);
        $row = $result->fetchRow();

        if ($row === null) {
            throw new NoSuchBucketException('The specified bucket does not exist.');
        }

        return self::str($row['versioning']);
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        if (! in_array($status, ['Enabled', 'Suspended'], true)) {
            throw new \InvalidArgumentException("Versioning status must be 'Enabled' or 'Suspended', got '{$status}'.");
        }

        // Verify bucket exists first (rowCount=0 on no-op UPDATE is indistinguishable from missing bucket).
        $check = $this->conn()->execute('SELECT 1 FROM s3_buckets WHERE name = $1', [$bucket]);
        if ($check->fetchRow() === null) {
            throw new NoSuchBucketException('The specified bucket does not exist.');
        }

        $this->conn()->execute('UPDATE s3_buckets SET versioning = $1 WHERE name = $2', [$status, $bucket]);
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
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $userMetadataJson = json_encode($userMetadata, JSON_THROW_ON_ERROR);

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute(
                'UPDATE s3_objects SET is_latest = FALSE WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE',
                [$bucket, $key],
            );

            $link->execute(
                <<<'SQL'
                INSERT INTO s3_objects (
                    bucket, key_name, version_id, is_latest, is_delete_marker,
                    owner_id, etag, size, content_type, content_encoding,
                    content_disposition, cache_control, storage_class, storage_path,
                    user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                    checksum_sha256, created_at, updated_at
                ) VALUES (
                    $1, $2, $3, TRUE, FALSE,
                    $4, $5, $6, $7, $8,
                    $9, $10, $11, $12,
                    $13::jsonb, $14, $15, $16,
                    $17, $18::timestamptz, $19::timestamptz
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
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            // Mark all previous versions as non-latest (both enabled and suspended).
            $link->execute(
                'UPDATE s3_objects SET is_latest = FALSE WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE',
                [$bucket, $key],
            );

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
                $link->execute(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        created_at, updated_at
                    ) VALUES (
                        $1, $2, 'null', TRUE, TRUE,
                        $3, '', 0, 'application/octet-stream', 'STANDARD', '',
                        $4::timestamptz, $5::timestamptz
                    )
                    ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                        is_latest = TRUE,
                        is_delete_marker = TRUE,
                        owner_id = EXCLUDED.owner_id,
                        etag = '',
                        size = 0,
                        storage_path = '',
                        updated_at = EXCLUDED.updated_at
                    SQL,
                    [$bucket, $key, $ownerId, $now, $now],
                );
            } else {
                $link->execute(
                    <<<'SQL'
                    INSERT INTO s3_objects (
                        bucket, key_name, version_id, is_latest, is_delete_marker,
                        owner_id, etag, size, content_type, storage_class, storage_path,
                        created_at, updated_at
                    ) VALUES (
                        $1, $2, $3, TRUE, TRUE,
                        $4, '', 0, 'application/octet-stream', 'STANDARD', '',
                        $5::timestamptz, $6::timestamptz
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
                restore_status, restored_storage_path, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
            FROM s3_objects
            WHERE bucket = $1 AND key_name = $2 AND version_id = $3
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
                'DELETE FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
                [$bucket, $key, $versionId],
            );

            if (!empty($objectInfo->systemMetadata['isLatest'])) {
                $link->execute(
                    <<<'SQL'
                    UPDATE s3_objects
                    SET is_latest = TRUE
                    WHERE bucket = $1 AND key_name = $2
                      AND id = (
                          SELECT id FROM s3_objects
                          WHERE bucket = $3 AND key_name = $4
                          ORDER BY created_at DESC, id DESC
                          LIMIT 1
                      )
                      AND is_latest = FALSE
                    SQL,
                    [$bucket, $key, $bucket, $key],
                );
            }

            $effectiveVersionId = $versionId;
            $link->execute(
                'DELETE FROM s3_object_retention WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
                [$bucket, $key, $effectiveVersionId],
            );
            $link->execute(
                'DELETE FROM s3_object_legal_holds WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
                [$bucket, $key, $effectiveVersionId],
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
                'isTruncated' => false,
                'nextKeyMarker' => null,
                'nextVersionIdMarker' => null,
                'commonPrefixes' => [],
            ];
        }

        $sql = 'SELECT bucket, key_name, version_id, is_latest, is_delete_marker, owner_id, etag, size, '
            . 'content_type, content_encoding, content_disposition, cache_control, '
            . 'storage_class, storage_path, user_metadata, checksum_crc32, checksum_crc32c, '
            . 'checksum_sha1, checksum_sha256, created_at, updated_at '
            . 'FROM s3_objects WHERE bucket = $1';

        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Look up the monotonic id for the marker row — version_id is random hex
                // and cannot be compared lexicographically for correct ordering.
                $markerResult = $this->conn()->execute(
                    'SELECT id FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3 LIMIT 1',
                    [$bucket, $keyMarker, $versionIdMarker],
                );
                $markerRow = null;
                foreach ($markerResult as $r) {
                    $markerRow = $r;
                    break;
                }
                if ($markerRow !== null) {
                    $sql .= " AND (key_name > \${$paramIndex} OR (key_name = \${$paramIndex} AND id < \$" . ($paramIndex + 1) . '))';
                    $params[] = $keyMarker;
                    $params[] = (int) $markerRow['id'];
                    $paramIndex += 2;
                } else {
                    $sql .= " AND key_name > \${$paramIndex}";
                    $params[] = $keyMarker;
                    $paramIndex++;
                }
            } else {
                $sql .= " AND key_name > \${$paramIndex}";
                $params[] = $keyMarker;
                $paramIndex++;
            }
        }

        $sql .= ' ORDER BY key_name ASC, created_at DESC, id DESC';

        if ($delimiter === null || $delimiter === '') {
            $sql .= " LIMIT \${$paramIndex}";
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

            foreach ($rows as $row) {
                $info = $this->rowToObjectInfo($row);
                if ($row['is_delete_marker']) {
                    $deleteMarkers[] = $info;
                } else {
                    $versions[] = $info;
                }
            }

            $nextKeyMarker = null;
            $nextVersionIdMarker = null;
            if ($isTruncated && count($rows) > 0) {
                $lastRow = $rows[count($rows) - 1];
                $nextKeyMarker = self::str($lastRow['key_name']);
                $nextVersionIdMarker = self::str($lastRow['version_id']);
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

        $fetchLimit = $maxKeys * 20 + 1000;
        $sql .= " LIMIT \${$paramIndex}";
        $params[] = $fetchLimit;

        $result = $this->conn()->execute($sql, $params);
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }

        return $this->groupVersionsByDelimiter(
            rows: $rows,
            prefix: $prefix ?? '',
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            fetchedAll: count($rows) < $fetchLimit,
        );
    }

    /**
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

            $keyName = (string) $row['key_name'];
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
                if ($row['is_delete_marker']) {
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
            $nextKeyMarker = (string) $lastRow['key_name'];
            $nextVersionIdMarker = (string) $lastRow['version_id'];
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

    public function getObjectLockConfig(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years FROM s3_lock_configs WHERE bucket = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        $config = [
            'objectLockEnabled' => ((bool) $row['object_lock_enabled']) ? 'Enabled' : 'Disabled',
        ];

        if ($row['default_retention_mode'] !== null) {
            $retention = ['mode' => self::str($row['default_retention_mode'])];
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
        $objectLockEnabled = $config['objectLockEnabled'] === 'Enabled';
        $retentionMode = $config['rule']['defaultRetention']['mode'] ?? null;
        $retentionDays = $config['rule']['defaultRetention']['days'] ?? null;
        $retentionYears = $config['rule']['defaultRetention']['years'] ?? null;

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_lock_configs (bucket, object_lock_enabled, default_retention_mode, default_retention_days, default_retention_years)
            VALUES ($1, $2, $3, $4, $5)
            ON CONFLICT(bucket) DO UPDATE SET
                object_lock_enabled = EXCLUDED.object_lock_enabled,
                default_retention_mode = EXCLUDED.default_retention_mode,
                default_retention_days = EXCLUDED.default_retention_days,
                default_retention_years = EXCLUDED.default_retention_years,
                updated_at = NOW()
            SQL,
            [$bucket, $objectLockEnabled, $retentionMode, $retentionDays, $retentionYears],
        );

        $this->conn()->execute(
            'UPDATE s3_buckets SET object_lock_enabled = $1 WHERE name = $2',
            [$objectLockEnabled, $bucket],
        );
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        $effectiveVersionId = $versionId ?? 'null';
        $result = $this->conn()->execute(
            'SELECT mode, retain_until_date FROM s3_object_retention WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
            [$bucket, $key, $effectiveVersionId],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return ['mode' => self::str($row['mode']), 'retainUntilDate' => self::str($row['retain_until_date'])];
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_object_retention (bucket, key_name, version_id, mode, retain_until_date)
            VALUES ($1, $2, $3, $4, $5)
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                mode = EXCLUDED.mode,
                retain_until_date = EXCLUDED.retain_until_date,
                updated_at = NOW()
            SQL,
            [$bucket, $key, $effectiveVersionId, $mode, $retainUntilDate],
        );
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        $effectiveVersionId = $versionId ?? 'null';
        $result = $this->conn()->execute(
            'SELECT status FROM s3_object_legal_holds WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
            [$bucket, $key, $effectiveVersionId],
        );
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['status']) : null;
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $effectiveVersionId = $versionId ?? 'null';
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_object_legal_holds (bucket, key_name, version_id, status)
            VALUES ($1, $2, $3, $4)
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                status = EXCLUDED.status,
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
            'SELECT grantee_type, grantee_id, permission FROM s3_acls WHERE resource_type = $1 AND resource_name = $2 ORDER BY id ASC',
            [$resourceType, $resourceName],
        );

        $grants = [];
        foreach ($result as $row) {
            $grants[] = [
                'granteeType' => self::str($row['grantee_type']),
                'granteeId' => self::str($row['grantee_id']),
                'permission' => self::str($row['permission']),
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
                'DELETE FROM s3_acls WHERE resource_type = $1 AND resource_name = $2',
                [$resourceType, $resourceName],
            );

            foreach ($grants as $grant) {
                $link->execute(
                    'INSERT INTO s3_acls (resource_type, resource_name, grantee_type, grantee_id, permission) VALUES ($1, $2, $3, $4, $5)',
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
            "SELECT tag_key, tag_value FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = $1 AND key_name IS NULL ORDER BY id ASC",
            [$bucket],
        );

        $tags = [];
        foreach ($result as $row) {
            $tags[] = ['key' => self::str($row['tag_key']), 'value' => self::str($row['tag_value'])];
        }

        return $tags;
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute("DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = $1 AND key_name IS NULL", [$bucket]);
            foreach ($tags as $tag) {
                $link->execute(
                    "INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) VALUES ('bucket', $1, NULL, $2, $3)",
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
        $this->conn()->execute("DELETE FROM s3_tagging WHERE resource_type = 'bucket' AND bucket = $1 AND key_name IS NULL", [$bucket]);
    }

    public function getObjectTagging(string $bucket, string $key): array
    {
        $result = $this->conn()->execute(
            "SELECT tag_key, tag_value FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 ORDER BY id ASC",
            [$bucket, $key],
        );

        $tags = [];
        foreach ($result as $row) {
            $tags[] = ['key' => self::str($row['tag_key']), 'value' => self::str($row['tag_value'])];
        }

        return $tags;
    }

    public function putObjectTagging(string $bucket, string $key, array $tags): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute("DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2", [$bucket, $key]);
            foreach ($tags as $tag) {
                $link->execute(
                    "INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) VALUES ('object', $1, $2, $3, $4)",
                    [$bucket, $key, $tag['key'], $tag['value']],
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

    public function deleteObjectTagging(string $bucket, string $key): void
    {
        $this->conn()->execute("DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2", [$bucket, $key]);
    }

    // ===============================================================
    // Bucket Policies
    // ===============================================================

    public function getBucketPolicy(string $bucket): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json FROM s3_policies WHERE bucket = $1', [$bucket]);
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['policy_json']) : null;
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_policies (bucket, policy_json) VALUES ($1, $2)
            ON CONFLICT(bucket) DO UPDATE SET policy_json = EXCLUDED.policy_json, updated_at = NOW()
            SQL,
            [$bucket, $policyJson],
        );
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_policies WHERE bucket = $1', [$bucket]);
    }

    // ===============================================================
    // CORS
    // ===============================================================

    public function getBucketCors(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds FROM s3_cors_rules WHERE bucket = $1 ORDER BY rule_order ASC',
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
            $rules[] = [
                'allowedOrigins' => $allowedOrigins,
                'allowedMethods' => $allowedMethods,
                'allowedHeaders' => $allowedHeaders,
                'exposeHeaders' => $exposeHeaders,
                'maxAgeSeconds' => ((int) $row['max_age_seconds']) !== 0 ? (int) $row['max_age_seconds'] : null,
            ];
        }

        return $rules;
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute('DELETE FROM s3_cors_rules WHERE bucket = $1', [$bucket]);
            foreach ($rules as $index => $rule) {
                $link->execute(
                    'INSERT INTO s3_cors_rules (bucket, rule_order, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) VALUES ($1, $2, $3::jsonb, $4::jsonb, $5::jsonb, $6::jsonb, $7)',
                    [
                        $bucket, $index,
                        json_encode($rule['allowedOrigins'], JSON_THROW_ON_ERROR),
                        json_encode($rule['allowedMethods'], JSON_THROW_ON_ERROR),
                        json_encode($rule['allowedHeaders'], JSON_THROW_ON_ERROR),
                        json_encode($rule['exposeHeaders'], JSON_THROW_ON_ERROR),
                        $rule['maxAgeSeconds'] ?? 0,
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
        $this->conn()->execute('DELETE FROM s3_cors_rules WHERE bucket = $1', [$bucket]);
    }

    // ===============================================================
    // Encryption Config
    // ===============================================================

    public function getBucketEncryption(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT sse_algorithm, kms_master_key_id, bucket_key_enabled FROM s3_encryption_configs WHERE bucket = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return [
            'sseAlgorithm' => self::str($row['sse_algorithm']),
            'kmsMasterKeyId' => $row['kms_master_key_id'] !== null ? self::str($row['kms_master_key_id']) : null,
            'bucketKeyEnabled' => (bool) $row['bucket_key_enabled'],
        ];
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled)
            VALUES ($1, $2, $3, $4)
            ON CONFLICT(bucket) DO UPDATE SET
                sse_algorithm = EXCLUDED.sse_algorithm,
                kms_master_key_id = EXCLUDED.kms_master_key_id,
                bucket_key_enabled = EXCLUDED.bucket_key_enabled,
                updated_at = NOW()
            SQL,
            [$bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled],
        );
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_encryption_configs WHERE bucket = $1', [$bucket]);
    }

    // ===============================================================
    // Lifecycle Configuration
    // ===============================================================

    public function getBucketLifecycle(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT rule_id, status, prefix, filter_json, transitions_json, expiration_json, noncurrent_transitions_json, noncurrent_expiration_json, abort_incomplete_days FROM s3_lifecycle_rules WHERE bucket = $1 ORDER BY rule_id ASC',
            [$bucket],
        );

        $rules = [];
        foreach ($result as $row) {
            $rules[] = [
                'id' => self::str($row['rule_id']),
                'status' => self::str($row['status']),
                'prefix' => $row['prefix'] !== null ? self::str($row['prefix']) : null,
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

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute('DELETE FROM s3_lifecycle_rules WHERE bucket = $1', [$bucket]);
            foreach ($rules as $rule) {
                $link->execute(
                    'INSERT INTO s3_lifecycle_rules (bucket, rule_id, status, prefix, filter_json, transitions_json, expiration_json, noncurrent_transitions_json, noncurrent_expiration_json, abort_incomplete_days) VALUES ($1, $2, $3, $4, $5::jsonb, $6::jsonb, $7::jsonb, $8::jsonb, $9::jsonb, $10)',
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
        $this->conn()->execute('DELETE FROM s3_lifecycle_rules WHERE bucket = $1', [$bucket]);
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
        $result = $this->conn()->execute(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at
             ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
             RETURNING id',
            [
                $bucket,
                $key,
                $versionId,
                $sourceTier,
                $targetTier,
                $targetStorageClass,
                $sourceStoragePath,
                max(1, $maxAttempts),
                microtime(true),
            ],
        );
        $row = $result->fetchRow();

        return $row !== null ? (int) $row['id'] : 0;
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);

        $this->conn()->execute(
            "UPDATE s3_tier_transition_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now - 300],
        );

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_tier_transition_jobs
                 WHERE status = 'pending' AND next_attempt_at <= $1
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT $2
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 1), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_tier_transition_jobs SET status = 'processing', updated_at = NOW() WHERE id IN ({$placeholders})",
                    $ids,
                );
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }

        return array_map($this->rowToTierTransitionJob(...), $rows);
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
            ? 'UPDATE s3_tier_transition_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE($4, target_storage_path), updated_at = NOW() WHERE id = $5'
            : 'UPDATE s3_tier_transition_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), target_storage_path = COALESCE($4, target_storage_path), updated_at = NOW() WHERE id = $5';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $targetStoragePath, $id]);
    }

    public function getTierTransitionJob(int $id): ?array
    {
        $result = $this->conn()->execute('SELECT * FROM s3_tier_transition_jobs WHERE id = $1', [$id]);
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
        $result = $this->conn()->execute(
            'INSERT INTO s3_restore_jobs (bucket, key_name, version_id, source_tier, source_storage_path, restore_days, max_attempts, next_attempt_at)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
             RETURNING id',
            [$bucket, $key, $versionId, $sourceTier, $sourceStoragePath, max(1, $restoreDays), max(1, $maxAttempts), microtime(true)],
        );
        $row = $result->fetchRow();

        return $row !== null ? (int) $row['id'] : 0;
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);

        $this->conn()->execute(
            "UPDATE s3_restore_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now - 300],
        );

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_restore_jobs
                 WHERE status = 'pending' AND next_attempt_at <= $1
                 ORDER BY next_attempt_at ASC, id ASC
                 LIMIT $2
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 1), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_restore_jobs SET status = 'processing', updated_at = NOW() WHERE id IN ({$placeholders})",
                    $ids,
                );
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }

        return array_map($this->rowToRestoreJob(...), $rows);
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
            ? 'UPDATE s3_restore_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE($4, restored_storage_path), updated_at = NOW() WHERE id = $5'
            : 'UPDATE s3_restore_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), restored_storage_path = COALESCE($4, restored_storage_path), updated_at = NOW() WHERE id = $5';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $restoredStoragePath, $id]);
    }

    public function getRestoreJob(int $id): ?array
    {
        $result = $this->conn()->execute('SELECT * FROM s3_restore_jobs WHERE id = $1', [$id]);
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToRestoreJob($row) : null;
    }

    // ===============================================================
    // Notification Configuration
    // ===============================================================

    public function getBucketNotification(string $bucket): array
    {
        $result = $this->conn()->execute(
            'SELECT config_id, event_type, destination_type, destination_arn, filter_rules_json FROM s3_notification_configs WHERE bucket = $1 ORDER BY config_id ASC',
            [$bucket],
        );

        $configs = [];
        foreach ($result as $row) {
            /** @var list<array{name: string, value: string}>|null $filterRules */
            $filterRules = $row['filter_rules_json'] !== null ? $this->decodeJson($row['filter_rules_json']) : null;
            $configs[] = [
                'id' => self::str($row['config_id']),
                'events' => self::decodeEvents(self::str($row['event_type'])),
                'destinationType' => self::str($row['destination_type']),
                'destinationArn' => self::str($row['destination_arn']),
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
            $link->execute('DELETE FROM s3_notification_configs WHERE bucket = $1', [$bucket]);
            foreach ($configs as $config) {
                $link->execute(
                    'INSERT INTO s3_notification_configs (bucket, config_id, event_type, destination_type, destination_arn, filter_rules_json) VALUES ($1, $2, $3, $4, $5, $6::jsonb)',
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
            'SELECT index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json FROM s3_website_configs WHERE bucket = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        /** @var list<array<string, mixed>>|null $routingRules */
        $routingRules = $row['routing_rules_json'] !== null ? $this->decodeJson($row['routing_rules_json']) : null;

        return [
            'indexDocument' => self::str($row['index_document']),
            'errorDocument' => $row['error_document'] !== null ? self::str($row['error_document']) : null,
            'redirectAllHost' => $row['redirect_all_host'] !== null ? self::str($row['redirect_all_host']) : null,
            'redirectAllProtocol' => $row['redirect_all_protocol'] !== null ? self::str($row['redirect_all_protocol']) : null,
            'routingRules' => $routingRules,
        ];
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_website_configs (bucket, index_document, error_document, redirect_all_host, redirect_all_protocol, routing_rules_json)
            VALUES ($1, $2, $3, $4, $5, $6::jsonb)
            ON CONFLICT(bucket) DO UPDATE SET
                index_document = EXCLUDED.index_document,
                error_document = EXCLUDED.error_document,
                redirect_all_host = EXCLUDED.redirect_all_host,
                redirect_all_protocol = EXCLUDED.redirect_all_protocol,
                routing_rules_json = EXCLUDED.routing_rules_json,
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
        $this->conn()->execute('DELETE FROM s3_website_configs WHERE bucket = $1', [$bucket]);
    }

    // ===============================================================
    // Rate Limiting
    // ===============================================================

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        $now = microtime(true);

        $result = $this->conn()->execute(
            'INSERT INTO s3_rate_limit_buckets (ip, tokens, last_refill_at) VALUES ($1, $2, $3)
             ON CONFLICT (ip) DO UPDATE SET
                tokens = LEAST($2, s3_rate_limit_buckets.tokens + ($3 - s3_rate_limit_buckets.last_refill_at) * $4),
                last_refill_at = $3
             RETURNING tokens',
            [$ip, $maxTokens, $now, $refillRate],
        );

        $row = $result->fetchRow();
        if ($row === null || (float) $row['tokens'] < 1.0) {
            return false;
        }

        $this->conn()->execute(
            'UPDATE s3_rate_limit_buckets SET tokens = tokens - 1.0 WHERE ip = $1',
            [$ip],
        );

        return true;
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $cutoff = microtime(true) - $maxAgeSeconds;
        $this->conn()->execute(
            'DELETE FROM s3_rate_limit_buckets WHERE last_refill_at < $1',
            [$cutoff],
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
    ): void {
        $this->conn()->execute(
            'INSERT INTO s3_notification_queue (bucket, key_name, event_name, destination_url, payload_json, max_attempts, next_attempt_at)
             VALUES ($1, $2, $3, $4, $5, $6, $7)',
            [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, microtime(true)],
        );
    }

    public function dequeueNotifications(int $limit): array
    {
        $now = microtime(true);

        // Reset stale "processing" items (stuck > 5 minutes) — outside transaction.
        $this->conn()->execute(
            "UPDATE s3_notification_queue SET status = 'pending'
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now - 300],
        );

        // FOR UPDATE SKIP LOCKED requires a transaction to hold the lock.
        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts
                 FROM s3_notification_queue
                 WHERE status = 'pending' AND next_attempt_at <= $1
                 ORDER BY next_attempt_at ASC
                 LIMIT $2
                 FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );

            $rows = [];
            while ($row = $result->fetchRow()) {
                $rows[] = $row;
            }

            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 1), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_notification_queue SET status = 'processing' WHERE id IN ({$placeholders})",
                    array_map(fn($id) => (int) $id, $ids),
                );
            }

            $tx->commit();

            return array_map(
                static fn(array $row): array => [
                    'id' => (int) $row['id'],
                    'bucket' => self::str($row['bucket']),
                    'key_name' => self::str($row['key_name']),
                    'event_name' => self::str($row['event_name']),
                    'destination_url' => self::str($row['destination_url']),
                    'payload_json' => self::str($row['payload_json']),
                    'attempts' => (int) $row['attempts'],
                    'max_attempts' => (int) $row['max_attempts'],
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
            $stats[self::str($row['status'])] = (int) $row['count'];
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
        $sql = $incrementAttempts
            ? 'UPDATE s3_notification_queue SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1 WHERE id = $4'
            : 'UPDATE s3_notification_queue SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at) WHERE id = $4';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $id]);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = (new \DateTimeImmutable("-{$maxAgeSeconds} seconds", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $this->conn()->execute(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND created_at < $1",
            [$cutoff],
        );
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
            return; // Already in a transaction on this fiber — nested call, no-op.
        }
        $this->fiberTxMap[$fiber] = $this->pool->beginTransaction();
    }

    public function commit(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberTxMap[$fiber])) {
            $this->fiberTxMap[$fiber]->commit();
            unset($this->fiberTxMap[$fiber]);
        }
    }

    public function rollback(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberTxMap[$fiber])) {
            $this->fiberTxMap[$fiber]->rollback();
            unset($this->fiberTxMap[$fiber]);
        }
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $transaction = $this->fiberTransaction();
        if ($transaction === null) {
            throw new \LogicException('lockOwnerForUpdate() requires an active transaction.');
        }

        $transaction->execute(
            'INSERT INTO s3_owner_write_locks (owner_id) VALUES ($1) ON CONFLICT (owner_id) DO NOTHING',
            [$ownerId],
        );
        $transaction->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = $1 FOR UPDATE',
            [$ownerId],
        )->fetchRow();
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
            if ($ownTx) {
                $this->rollback();
            }

            throw $e;
        }
    }

    // ===============================================================
    // Internal helpers
    // ===============================================================

    private function conn(): PostgresLink
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberTxMap[$fiber])) {
            return $this->fiberTxMap[$fiber];
        }
        return $this->pool;
    }

    private function fiberTransaction(): ?PostgresTransaction
    {
        $fiber = \Fiber::getCurrent();
        return ($fiber !== null && isset($this->fiberTxMap[$fiber])) ? $this->fiberTxMap[$fiber] : null;
    }

    /** @param array<string, mixed> $row */
    private function rowToBucketInfo(array $row): BucketInfo
    {
        return new BucketInfo(
            name: self::str($row['name']),
            ownerId: self::str($row['owner_id']),
            region: self::str($row['region']),
            creationDate: new \DateTimeImmutable($this->formatTimestamp($row['created_at'])),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowToObjectInfo(array $row): ObjectInfo
    {
        $userMetadata = is_string($row['user_metadata'])
            ? json_decode($row['user_metadata'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['user_metadata'] ?? []);

        /** @var array<string, string> $userMetadata */
        $systemMetadata = [];

        if (isset($row['storage_path'])) {
            $systemMetadata['storagePath'] = self::str($row['storage_path']);
        }
        if (isset($row['content_encoding'])) {
            $systemMetadata['content-encoding'] = self::str($row['content_encoding']);
        }
        if (isset($row['content_disposition'])) {
            $systemMetadata['content-disposition'] = self::str($row['content_disposition']);
        }
        if (isset($row['cache_control'])) {
            $systemMetadata['cache-control'] = self::str($row['cache_control']);
        }
        if (isset($row['checksum_crc32'])) {
            $systemMetadata['checksum-crc32'] = self::str($row['checksum_crc32']);
        }
        if (isset($row['checksum_crc32c'])) {
            $systemMetadata['checksum-crc32c'] = self::str($row['checksum_crc32c']);
        }
        if (isset($row['checksum_sha1'])) {
            $systemMetadata['checksum-sha1'] = self::str($row['checksum_sha1']);
        }
        if (isset($row['checksum_sha256'])) {
            $systemMetadata['checksum-sha256'] = self::str($row['checksum_sha256']);
        }
        if (isset($row['is_latest'])) {
            $systemMetadata['isLatest'] = (bool) $row['is_latest'] ? '1' : '0';
        }

        $versionId = ($row['version_id'] ?? 'null') === 'null' ? null : self::str($row['version_id']);

        $lastModified = isset($row['updated_at'])
            ? new \DateTimeImmutable($this->formatTimestamp($row['updated_at']))
            : new \DateTimeImmutable();
        $restoreExpiresAt = isset($row['restore_expires_at']) && $row['restore_expires_at'] !== ''
            ? new \DateTimeImmutable($this->formatTimestamp($row['restore_expires_at']))
            : null;

        return new ObjectInfo(
            bucket: self::str($row['bucket']),
            key: self::str($row['key_name']),
            size: self::int($row['size']),
            etag: self::str($row['etag']),
            contentType: self::str($row['content_type'] ?? 'application/octet-stream'),
            storageClass: self::str($row['storage_class'] ?? 'STANDARD'),
            storageTier: self::str($row['storage_tier'] ?? ($row['storage_class'] ?? 'STANDARD')),
            transitionStatus: self::str($row['transition_status'] ?? 'available'),
            transitionTargetTier: isset($row['transition_target_tier']) ? self::str($row['transition_target_tier']) : null,
            transitionError: isset($row['transition_error']) ? self::str($row['transition_error']) : null,
            restoreStatus: isset($row['restore_status']) ? self::str($row['restore_status']) : null,
            restoredStoragePath: isset($row['restored_storage_path']) ? self::str($row['restored_storage_path']) : null,
            restoreExpiresAt: $restoreExpiresAt,
            ownerId: self::str($row['owner_id'] ?? ''),
            versionId: $versionId,
            isDeleteMarker: (bool) ($row['is_delete_marker'] ?? false),
            userMetadata: $userMetadata,
            systemMetadata: $systemMetadata,
            lastModified: $lastModified,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToTierTransitionJob(array $row): array
    {
        return [
            'id' => self::int($row['id']),
            'bucket' => self::str($row['bucket']),
            'key' => self::str($row['key_name']),
            'versionId' => $row['version_id'] !== null ? self::str($row['version_id']) : null,
            'sourceTier' => self::str($row['source_tier']),
            'targetTier' => self::str($row['target_tier']),
            'targetStorageClass' => self::str($row['target_storage_class']),
            'sourceStoragePath' => self::str($row['source_storage_path']),
            'targetStoragePath' => $row['target_storage_path'] !== null ? self::str($row['target_storage_path']) : null,
            'status' => self::str($row['status']),
            'attempts' => self::int($row['attempts']),
            'maxAttempts' => self::int($row['max_attempts']),
            'nextAttemptAt' => (float) self::str($row['next_attempt_at']),
            'lastError' => $row['last_error'] !== null ? self::str($row['last_error']) : null,
            'createdAt' => $this->formatTimestamp($row['created_at']),
            'updatedAt' => $this->formatTimestamp($row['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToRestoreJob(array $row): array
    {
        return [
            'id' => self::int($row['id']),
            'bucket' => self::str($row['bucket']),
            'key' => self::str($row['key_name']),
            'versionId' => $row['version_id'] !== null ? self::str($row['version_id']) : null,
            'sourceTier' => self::str($row['source_tier']),
            'sourceStoragePath' => self::str($row['source_storage_path']),
            'restoredStoragePath' => $row['restored_storage_path'] !== null ? self::str($row['restored_storage_path']) : null,
            'restoreDays' => self::int($row['restore_days']),
            'status' => self::str($row['status']),
            'attempts' => self::int($row['attempts']),
            'maxAttempts' => self::int($row['max_attempts']),
            'nextAttemptAt' => (float) self::str($row['next_attempt_at']),
            'lastError' => $row['last_error'] !== null ? self::str($row['last_error']) : null,
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

        return (string) $value;
    }

    /** @param list<mixed>|bool|int|float|string|null $value */
    private static function str(mixed $value): string
    {
        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    }

    /** @param list<mixed>|bool|int|float|string|null $value */
    private static function int(mixed $value): int
    {
        if (is_array($value)) {
            return 0;
        }

        return (int) $value;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode(self::str($value), true, 512, JSON_THROW_ON_ERROR);
    }

    // ===============================================================
    // Public Access Block
    // ===============================================================

    public function getPublicAccessBlock(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_public_access_blocks WHERE bucket = $1',
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
            <<<'SQL'
            INSERT INTO s3_public_access_blocks (bucket, block_public_acls, ignore_public_acls, block_public_policy, restrict_public_buckets)
            VALUES ($1, $2, $3, $4, $5)
            ON CONFLICT(bucket) DO UPDATE SET
                block_public_acls = EXCLUDED.block_public_acls,
                ignore_public_acls = EXCLUDED.ignore_public_acls,
                block_public_policy = EXCLUDED.block_public_policy,
                restrict_public_buckets = EXCLUDED.restrict_public_buckets
            SQL,
            [$bucket, $blockPublicAcls, $ignorePublicAcls, $blockPublicPolicy, $restrictPublicBuckets],
        );
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_public_access_blocks WHERE bucket = $1',
            [$bucket],
        );
    }

    // ===============================================================
    // Bucket Logging
    // ===============================================================

    public function getBucketLogging(string $bucket): ?array
    {
        $result = $this->conn()->execute(
            'SELECT * FROM s3_bucket_logging WHERE bucket = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        if ($row === null) {
            return null;
        }

        return [
            'targetBucket' => self::str($row['target_bucket']),
            'targetPrefix' => self::str($row['target_prefix']),
        ];
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_bucket_logging (bucket, target_bucket, target_prefix)
            VALUES ($1, $2, $3)
            ON CONFLICT(bucket) DO UPDATE SET
                target_bucket = EXCLUDED.target_bucket,
                target_prefix = EXCLUDED.target_prefix
            SQL,
            [$bucket, $targetBucket, $targetPrefix],
        );
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_bucket_logging WHERE bucket = $1',
            [$bucket],
        );
    }

    // ===============================================================
    // Distributed locks
    // ===============================================================

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        $ttlSeconds = max(1, $ttlSeconds);

        $result = $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_locks (lock_name, owner_id, expires_at, updated_at)
            VALUES ($1, $2, NOW() + ($3::TEXT || ' seconds')::INTERVAL, NOW())
            ON CONFLICT(lock_name) DO UPDATE SET
                owner_id = EXCLUDED.owner_id,
                expires_at = EXCLUDED.expires_at,
                updated_at = NOW()
            WHERE s3_locks.owner_id = EXCLUDED.owner_id OR s3_locks.expires_at <= NOW()
            SQL,
            [$lockName, $ownerId, $ttlSeconds],
        );

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_locks WHERE lock_name = $1 AND owner_id = $2',
            [$lockName, $ownerId],
        );
    }

    // ===============================================================
    // Lifecycle checkpoints
    // ===============================================================

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        $result = $this->conn()->execute(
            'SELECT cursor_key, cursor_version_id, cursor_upload_id FROM s3_lifecycle_checkpoints WHERE bucket = $1 AND rule_id = $2 AND action = $3',
            [$bucket, $ruleId, $action],
        );
        $row = $result->fetchRow();
        if ($row === null) {
            return null;
        }

        return [
            'cursorKey' => $row['cursor_key'] !== null ? self::str($row['cursor_key']) : null,
            'cursorVersionId' => $row['cursor_version_id'] !== null ? self::str($row['cursor_version_id']) : null,
            'cursorUploadId' => $row['cursor_upload_id'] !== null ? self::str($row['cursor_upload_id']) : null,
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
            <<<'SQL'
            INSERT INTO s3_lifecycle_checkpoints (bucket, rule_id, action, cursor_key, cursor_version_id, cursor_upload_id, updated_at)
            VALUES ($1, $2, $3, $4, $5, $6, NOW())
            ON CONFLICT(bucket, rule_id, action) DO UPDATE SET
                cursor_key = EXCLUDED.cursor_key,
                cursor_version_id = EXCLUDED.cursor_version_id,
                cursor_upload_id = EXCLUDED.cursor_upload_id,
                updated_at = NOW()
            SQL,
            [$bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId],
        );
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_lifecycle_checkpoints WHERE bucket = $1 AND rule_id = $2 AND action = $3',
            [$bucket, $ruleId, $action],
        );
    }

    // ===============================================================
    // Lifecycle query methods
    // ===============================================================

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        $sql = 'SELECT * FROM s3_objects WHERE bucket = $1 AND is_latest = TRUE AND is_delete_marker = FALSE AND created_at < $2';
        $params = [$bucket, $olderThan->format('Y-m-d\TH:i:s\Z')];
        $paramIndex = 3;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $sql .= " AND key_name > \${$paramIndex}";
            $params[] = $afterKey;
            $paramIndex++;
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 's3_objects', $tags);

        $sql .= " ORDER BY key_name ASC LIMIT \${$paramIndex}";
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
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT * FROM s3_objects WHERE bucket = $1 AND is_latest = FALSE AND is_delete_marker = FALSE AND created_at < $2';
        $params = [$bucket, $cutoff];
        $paramIndex = 3;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $versionParam = $paramIndex++;
            $sql .= " AND (key_name > \${$keyParam} OR (key_name = \${$sameKeyParam} AND version_id > \${$versionParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 's3_objects', $tags);

        $sql .= " ORDER BY key_name ASC, version_id ASC LIMIT \${$paramIndex}";
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);
        $objects = [];
        foreach ($result as $row) {
            $objects[] = $this->rowToObjectInfo($row);
        }

        return $objects;
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array
    {
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$daysAfterInitiation} days")
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT upload_id, bucket, key_name FROM s3_multipart_uploads WHERE bucket = $1 AND created_at < $2';
        $params = [$bucket, $cutoff];
        $paramIndex = 3;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE \${$paramIndex} ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $uploadParam = $paramIndex++;
            $sql .= " AND (key_name > \${$keyParam} OR (key_name = \${$sameKeyParam} AND upload_id > \${$uploadParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterUploadId ?? '';
        }

        $sql .= " ORDER BY key_name ASC, upload_id ASC LIMIT \${$paramIndex}";
        $params[] = max(1, min(10000, $limit));

        $result = $this->conn()->execute($sql, $params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string}> $uploads */
        $uploads = [];
        foreach ($result as $row) {
            $uploads[] = [
                'upload_id' => self::str($row['upload_id']),
                'bucket' => self::str($row['bucket']),
                'key_name' => self::str($row['key_name']),
            ];
        }

        return $uploads;
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        $sql = 'SELECT dm.* FROM s3_objects dm '
            . 'WHERE dm.bucket = $1 AND dm.is_delete_marker = TRUE AND dm.is_latest = TRUE '
            . 'AND NOT EXISTS (SELECT 1 FROM s3_objects o WHERE o.bucket = dm.bucket AND o.key_name = dm.key_name AND o.is_delete_marker = FALSE)';
        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND dm.key_name LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $versionParam = $paramIndex++;
            $sql .= " AND (dm.key_name > \${$keyParam} OR (dm.key_name = \${$sameKeyParam} AND dm.version_id > \${$versionParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 'dm', $tags);

        $sql .= " ORDER BY dm.key_name ASC, dm.version_id ASC LIMIT \${$paramIndex}";
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
            'SELECT * FROM s3_objects
             WHERE restore_status = $1 AND restored_storage_path IS NOT NULL AND restore_expires_at IS NOT NULL AND restore_expires_at <= $2
             ORDER BY restore_expires_at ASC, bucket ASC, key_name ASC, version_id ASC
             LIMIT $3',
            [
                'restored',
                $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
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
    private function appendLifecycleTagFilters(string $sql, array &$params, int &$paramIndex, string $objectAlias, array $tags): string
    {
        foreach ($tags as $key => $value) {
            $keyParam = $paramIndex++;
            $valueParam = $paramIndex++;
            $sql .= " AND EXISTS (
                SELECT 1 FROM s3_tagging lt
                WHERE lt.resource_type = 'object'
                  AND lt.bucket = {$objectAlias}.bucket
                  AND lt.key_name = {$objectAlias}.key_name
                  AND lt.tag_key = \${$keyParam}
                  AND lt.tag_value = \${$valueParam}
            )";
            $params[] = $key;
            $params[] = $value;
        }

        return $sql;
    }
}
