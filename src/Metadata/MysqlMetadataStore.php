<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlLink;
use Amp\Mysql\MysqlTransaction;
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
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * MySQL-backed implementation of the S3 metadata store.
 *
 * Uses amphp/mysql for fully async database access. Suitable for
 * multi-node HA deployments.
 */
final class MysqlMetadataStore implements MetadataStore
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, MysqlTransaction> */
    private \WeakMap $fiberTxMap;

    public function __construct(
        private readonly MysqlConnectionPool $pool,
    ) {
        $this->fiberTxMap = new \WeakMap();
    }

    public static function fromDsn(string $dsn): self
    {
        $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));

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
            if ($currentVersion >= MysqlSchema::VERSION) {
                return;
            }
        } catch (\Throwable) {
            // Table doesn't exist yet.
        }

        if ($currentVersion > 0) {
            // Existing tables must be migrated before current-schema indexes
            // are created because those indexes may reference new columns.
            // MySQL DDL auto-commits. Duplicate schema objects are safe on a
            // partially applied retry; every other error must stop versioning.
            foreach (MysqlSchema::getMigrationStatements($currentVersion) as $sql) {
                try {
                    $this->pool->execute($sql);
                } catch (\Throwable $e) {
                    if (! self::isDuplicateSchemaObject($e)) {
                        throw $e;
                    }
                }
            }
        }

        foreach (MysqlSchema::getCreateStatements() as $sql) {
            $this->pool->execute($sql);
        }

        $this->pool->execute(
            'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
            [MysqlSchema::VERSION, 'Schema version ' . MysqlSchema::VERSION],
        );
    }

    // ===============================================================
    // Bucket operations
    // ===============================================================

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        try {
            $this->conn()->execute(
                'INSERT INTO s3_buckets (name, owner_id, region) VALUES (?, ?, ?)',
                [$bucket, $ownerId, $region],
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
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE name = ?',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToBucketInfo($row) : null;
    }

    public function listBuckets(string $ownerId): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at FROM s3_buckets WHERE owner_id = ? ORDER BY name ASC',
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
                restore_expires_at = NULL,
                user_metadata = VALUES(user_metadata),
                checksum_crc32 = VALUES(checksum_crc32),
                checksum_crc32c = VALUES(checksum_crc32c),
                checksum_sha1 = VALUES(checksum_sha1),
                checksum_sha256 = VALUES(checksum_sha256),
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
            WHERE bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0
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
            gmdate('Y-m-d H:i:s'),
            $bucket,
            $key,
        ];
        if ($versionId !== null) {
            $params[] = $versionId;
        }

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET storage_class = ?,
                storage_tier = ?,
                storage_path = ?,
                transition_status = ?,
                transition_target_tier = ?,
                transition_error = ?,
                updated_at = ?
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
            ? 'bucket = ? AND key_name = ? AND is_latest = 1 AND is_delete_marker = 0'
            : 'bucket = ? AND key_name = ? AND version_id = ?';
        $params = [
            $restoreStatus,
            $restoredStoragePath,
            $restoreExpiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
            $bucket,
            $key,
        ];
        if ($versionId !== null) {
            $params[] = $versionId;
        }

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET restore_status = ?,
                restored_storage_path = ?,
                restore_expires_at = ?,
                updated_at = ?
            WHERE {$where}
            SQL, $params);
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
        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_account_policies (owner_id, policy_json) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE policy_json = VALUES(policy_json), updated_at = NOW()
            SQL,
            [$ownerId, $policyJson],
        );
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->conn()->execute('DELETE FROM s3_account_policies WHERE owner_id = ?', [$ownerId]);
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json FROM s3_named_policies WHERE policy_name = ?', [$policyName]);
        $row = $result->fetchRow();

        return $row !== null ? (string) $row['policy_json'] : null;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
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

        if ($delimiter === null || $delimiter === '') {
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

        $fetchLimit = $maxKeys * 20 + 1000;
        $sql .= ' LIMIT ?';
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
            'INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, content_type, user_metadata) VALUES (?, ?, ?, ?, ?, ?)',
            [$uploadId, $bucket, $key, $ownerId, $contentType ?? 'application/octet-stream', json_encode($userMetadata, JSON_THROW_ON_ERROR)],
        );
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        $result = $this->conn()->execute(
            'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE upload_id = ?',
            [$uploadId],
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
            'key_name' => (string) $row['key_name'],
            'owner_id' => (string) $row['owner_id'],
            'content_type' => (string) $row['content_type'],
            'user_metadata' => $userMetadata,
            'created_at' => $this->formatTimestamp($row['created_at']),
        ];
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->deleteParts($uploadId);
        $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = ?', [$uploadId]);
    }

    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, created_at FROM s3_multipart_uploads WHERE bucket = ?';
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

        $result = $this->conn()->execute($sql, $params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, created_at: string}> $rows */
        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'upload_id' => (string) $row['upload_id'],
                'bucket' => (string) $row['bucket'],
                'key_name' => (string) $row['key_name'],
                'owner_id' => (string) $row['owner_id'],
                'content_type' => (string) $row['content_type'],
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

    public function getMultipartStorageStats(string $ownerId, ?string $bucket = null): array
    {
        $sql = 'SELECT COUNT(DISTINCT u.upload_id) AS upload_count, COALESCE(SUM(p.size), 0) AS bytes_used
                FROM s3_multipart_uploads u
                LEFT JOIN s3_parts p ON p.upload_id = u.upload_id
                WHERE u.owner_id = ?';
        $params = [$ownerId];
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
        $upload = $this->getMultipartUpload($uploadId);
        if ($upload === null) {
            throw new NoSuchUploadException('The specified upload does not exist.');
        }

        $this->conn()->execute(
            <<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                etag = VALUES(etag),
                size = VALUES(size),
                storage_path = VALUES(storage_path),
                created_at = NOW()
            SQL,
            [$uploadId, $partNumber, $etag, $size, $storagePath],
        );
    }

    public function getParts(string $uploadId): array
    {
        $result = $this->conn()->execute(
            'SELECT part_number, etag, size, storage_path, created_at FROM s3_parts WHERE upload_id = ? ORDER BY part_number ASC',
            [$uploadId],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => (int) $row['part_number'],
                'etag' => (string) $row['etag'],
                'size' => (int) $row['size'],
                'storage_path' => (string) $row['storage_path'],
                'created_at' => $this->formatTimestamp($row['created_at']),
            ];
        }

        return $parts;
    }

    public function deleteParts(string $uploadId): void
    {
        $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id = ?', [$uploadId]);
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
            $link->execute(
                'UPDATE s3_objects SET is_latest = 0 WHERE bucket = ? AND key_name = ? AND is_latest = 1',
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
            $link->execute(
                'UPDATE s3_objects SET is_latest = 0 WHERE bucket = ? AND key_name = ? AND is_latest = 1',
                [$bucket, $key],
            );

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
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
                        is_delete_marker = 1,
                        owner_id = VALUES(owner_id),
                        etag = '',
                        size = 0,
                        storage_path = '',
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
                restore_status, restored_storage_path, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, created_at, updated_at
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

            if (!empty($objectInfo->systemMetadata['isLatest'])) {
                // Promote the next most recent version to is_latest.
                // MySQL does not allow referencing the same table in a subquery
                // of an UPDATE, so we use a derived table workaround.
                $link->execute(
                    <<<'SQL'
                    UPDATE s3_objects
                    SET is_latest = 1
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
                    SQL,
                    [$bucket, $key, $bucket, $key],
                );
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
            . 'FROM s3_objects WHERE bucket = ?';

        $params = [$bucket];

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name LIKE ? ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Look up the monotonic id for the marker row — version_id is random hex
                // and cannot be compared lexicographically for correct ordering.
                $markerResult = $this->conn()->execute(
                    'SELECT id FROM s3_objects WHERE bucket = ? AND key_name = ? AND version_id = ? LIMIT 1',
                    [$bucket, $keyMarker, $versionIdMarker],
                );
                $markerRow = null;
                foreach ($markerResult as $r) {
                    $markerRow = $r;
                    break;
                }
                if ($markerRow !== null) {
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

            $versions = [];
            $deleteMarkers = [];

            foreach ($rows as $row) {
                $info = $this->rowToObjectInfo($row);
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
                'isTruncated' => $isTruncated,
                'nextKeyMarker' => $nextKeyMarker,
                'nextVersionIdMarker' => $nextVersionIdMarker,
                'commonPrefixes' => [],
            ];
        }

        $fetchLimit = $maxKeys * 20 + 1000;
        $sql .= ' LIMIT ?';
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
                if ((int) $row['is_delete_marker']) {
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
            'SELECT allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds FROM s3_cors_rules WHERE bucket = ? ORDER BY rule_order ASC',
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
            $link->execute('DELETE FROM s3_cors_rules WHERE bucket = ?', [$bucket]);
            foreach ($rules as $index => $rule) {
                $link->execute(
                    'INSERT INTO s3_cors_rules (bucket, rule_order, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)',
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

        return [
            'sseAlgorithm' => (string) $row['sse_algorithm'],
            'kmsMasterKeyId' => $row['kms_master_key_id'] !== null ? (string) $row['kms_master_key_id'] : null,
            'bucketKeyEnabled' => (bool) (int) $row['bucket_key_enabled'],
        ];
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
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
    ): int {
        $link = $this->conn();
        $result = $link->execute(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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

        return $result->getLastInsertId()
            ?? throw new \RuntimeException('MySQL did not return the inserted tier transition job ID.');
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);

        $this->conn()->execute(
            "UPDATE s3_tier_transition_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < ?",
            [$now],
        );

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_tier_transition_jobs
                 WHERE status = 'pending' AND next_attempt_at <= ?
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
                     SET status = 'processing', next_attempt_at = ?, updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    [QueueLease::expiresAt($now), ...$ids],
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
            ? 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE(?, target_storage_path), updated_at = NOW() WHERE id = ?'
            : 'UPDATE s3_tier_transition_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), target_storage_path = COALESCE(?, target_storage_path), updated_at = NOW() WHERE id = ?';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $targetStoragePath, $id]);
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_tier_transition_jobs
             SET next_attempt_at = ?, updated_at = NOW()
             WHERE id = ? AND status = 'processing'",
            [$leaseExpiresAt, $id],
        );

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
            [$bucket, $key, $versionId, $sourceTier, $sourceStoragePath, max(1, $restoreDays), max(1, $maxAttempts), microtime(true)],
        );

        return $result->getLastInsertId()
            ?? throw new \RuntimeException('MySQL did not return the inserted restore job ID.');
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $now = microtime(true);

        $this->conn()->execute(
            "UPDATE s3_restore_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < ?",
            [$now],
        );

        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT * FROM s3_restore_jobs
                 WHERE status = 'pending' AND next_attempt_at <= ?
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
                     SET status = 'processing', next_attempt_at = ?, updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    [QueueLease::expiresAt($now), ...$ids],
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
            ? 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE(?, restored_storage_path), updated_at = NOW() WHERE id = ?'
            : 'UPDATE s3_restore_jobs SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), restored_storage_path = COALESCE(?, restored_storage_path), updated_at = NOW() WHERE id = ?';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $restoredStoragePath, $id]);
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_restore_jobs
             SET next_attempt_at = ?, updated_at = NOW()
             WHERE id = ? AND status = 'processing'",
            [$leaseExpiresAt, $id],
        );

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
        $now = microtime(true);

        return $this->transaction(function () use ($ip, $maxTokens, $refillRate, $now): bool {
            $this->conn()->execute(
                'INSERT INTO s3_rate_limit_buckets (ip, tokens, last_refill_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE ip = VALUES(ip)',
                [$ip, $maxTokens, $now],
            );
            $row = $this->conn()->execute(
                'SELECT tokens, last_refill_at FROM s3_rate_limit_buckets WHERE ip = ? FOR UPDATE',
                [$ip],
            )->fetchRow();
            \assert($row !== null);

            $available = min(
                $maxTokens,
                (float) $row['tokens'] + max(0.0, $now - (float) $row['last_refill_at']) * $refillRate,
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
        $cutoff = microtime(true) - $maxAgeSeconds;
        $this->conn()->execute(
            'DELETE FROM s3_rate_limit_buckets WHERE last_refill_at < ?',
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
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, microtime(true)],
        );
    }

    public function dequeueNotifications(int $limit): array
    {
        $now = microtime(true);

        // Reset stale "processing" items (stuck > 5 minutes) — outside transaction.
        $this->conn()->execute(
            "UPDATE s3_notification_queue SET status = 'pending'
             WHERE status = 'processing' AND next_attempt_at < ?",
            [$now],
        );

        // FOR UPDATE SKIP LOCKED requires a transaction to hold the lock.
        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts
                 FROM s3_notification_queue
                 WHERE status = 'pending' AND next_attempt_at <= ?
                 ORDER BY next_attempt_at ASC
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
                     SET status = 'processing', next_attempt_at = ?
                     WHERE id IN ({$placeholders})",
                    [QueueLease::expiresAt($now), ...array_map(fn($id) => (int) $id, $ids)],
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

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
    ): void {
        $sql = $incrementAttempts
            ? 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at), attempts = attempts + 1 WHERE id = ?'
            : 'UPDATE s3_notification_queue SET status = ?, last_error = ?, next_attempt_at = COALESCE(?, next_attempt_at) WHERE id = ?';
        $this->conn()->execute($sql, [$status, $error, $nextAttemptAt, $id]);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = (new \DateTimeImmutable("-{$maxAgeSeconds} seconds", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->conn()->execute(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND created_at < ?",
            [$cutoff],
        );
    }

    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->conn()->execute(
            'INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at)
             VALUES (?, ?, ?, ?)',
            [$bucket, $storageTier, $storagePath, microtime(true)],
        );
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_storage_garbage
             WHERE bucket = ? AND storage_tier = ? AND storage_path = ?',
            [$bucket, $storageTier, $storagePath],
        );
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        return $this->transaction(function () use ($limit): array {
            $now = microtime(true);
            $this->conn()->execute(
                "UPDATE s3_storage_garbage SET status = 'pending'
                 WHERE status = 'processing' AND next_attempt_at < ?",
                [$now],
            );
            $result = $this->conn()->execute(
                "SELECT id, bucket, storage_tier, storage_path, attempts
                 FROM s3_storage_garbage
                 WHERE status = 'pending' AND next_attempt_at <= ?
                 ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED",
                [$now, $limit],
            );
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $this->conn()->execute(
                    "UPDATE s3_storage_garbage SET status = 'processing', next_attempt_at = ? WHERE id = ?",
                    [$now + 300, $row['id']],
                );
                $rows[] = [
                    'id' => (int) $row['id'],
                    'bucket' => (string) $row['bucket'],
                    'storage_tier' => (string) $row['storage_tier'],
                    'storage_path' => (string) $row['storage_path'],
                    'attempts' => (int) $row['attempts'],
                ];
            }
            return $rows;
        });
    }

    public function completeStorageGarbage(int $id): void
    {
        $this->conn()->execute('DELETE FROM s3_storage_garbage WHERE id = ?', [$id]);
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt): void
    {
        $this->conn()->execute(
            "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = ?, next_attempt_at = ?
             WHERE id = ?",
            [$error, $nextAttemptAt, $id],
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

    private function conn(): MysqlLink
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberTxMap[$fiber])) {
            return $this->fiberTxMap[$fiber];
        }
        return $this->pool;
    }

    private function fiberTransaction(): ?MysqlTransaction
    {
        $fiber = \Fiber::getCurrent();
        return ($fiber !== null && isset($this->fiberTxMap[$fiber])) ? $this->fiberTxMap[$fiber] : null;
    }

    private static function isDuplicateSchemaObject(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate column name')
            || str_contains($message, 'already exists')
            || str_contains($message, 'check that column/key exists');
    }

    /** @param array<string, int|float|string|null> $row */
    private function rowToBucketInfo(array $row): BucketInfo
    {
        return new BucketInfo(
            name: (string) $row['name'],
            ownerId: (string) $row['owner_id'],
            region: (string) $row['region'],
            creationDate: new \DateTimeImmutable($this->formatTimestamp($row['created_at'])),
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
        );
    }

    /**
     * @param array<string, mixed> $row
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
             WHERE restore_status = ? AND restored_storage_path IS NOT NULL AND restore_expires_at IS NOT NULL AND restore_expires_at <= ?
             ORDER BY restore_expires_at ASC, bucket ASC, key_name ASC, version_id ASC
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
