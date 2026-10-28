<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresLink;
use Amp\Postgres\PostgresQueryError;
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
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaAdmission;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaGuard;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * PostgreSQL-backed implementation of the S3 metadata store.
 *
 * Uses amphp/postgres for fully async database access. Suitable for
 * multi-node HA deployments.
 */
final class PostgresMetadataStore implements MetadataStore, MetadataWriteReadinessProbe, SharedAuthorizationLockStore, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartWriteIntentStore, FencedMultipartWriteIntentStore, LocalMultipartCompletionIntentStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, LocalMultipartFenceAdmissionStore, RecursiveCleanupAdmissionStore, MultipartPartWriteIntentInventoryStore, MultipartUploadTierStore, ObjectWriteIntentStore, PagedMultipartPartsStore, MultipartPartChecksumStore, FencedStorageGarbageStore, StorageGarbageLeaseStore, AwsS3GarbageClaimStore, FencedQueueStore, RestoredCopyTierStore, MetadataSchemaAdmission, MultipartCompletionCapacityReader, WriteIntentCapacityReader, StorageCleanupCapacityReader, RecoveryStorageGarbageStore, ObjectPartLayoutStore, AwsS3OperationalSummaryReader
{
    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, PostgresTransaction> */
    private \WeakMap $fiberTxMap;

    /** @var \WeakMap<object, array<int, true>> */
    private \WeakMap $objectPartLayoutPublicationIds;

    private ?string $creationDsnFingerprint = null;

    public function __construct(
        private readonly PostgresConnectionPool $pool,
    ) {
        $this->fiberTxMap = new \WeakMap();
        $this->objectPartLayoutPublicationIds = new \WeakMap();
    }

    public static function fromDsn(string $dsn): self
    {
        $pool = new PostgresConnectionPool(\Amp\Postgres\PostgresConfig::fromString($dsn));

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
        $this->conn()->execute('UPDATE s3_buckets SET region = region WHERE FALSE');
        MetadataSchemaGuard::assertPostgresWriteIntentTriggers($this->pool);
        MetadataSchemaGuard::assertPostgresSequenceUsage($this->pool);
    }

    public function assertCurrentSchema(): void
    {
        try {
            $row = $this->pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            $actual = $row !== null && $row['version'] !== null ? (int) $row['version'] : 0;
        } catch (\Throwable $error) {
            throw new \RuntimeException('Metadata schema cannot be verified. Run metadata:migrate before starting API nodes.', 0, $error);
        }

        MetadataSchemaGuard::assertCurrentVersion('postgres', $actual);
        $this->assertSupportedBucketEncryptionInventory();
        MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
        $this->assertWriteIntentCapacityConsistent();
        MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
        MetadataSchemaGuard::assertPostgresWriteIntentTriggers($this->pool);
        MetadataSchemaGuard::assertPostgresSequenceUsage($this->pool);
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
        } catch (PostgresQueryError $error) {
            if (($error->getDiagnostics()['sqlstate'] ?? null) !== '42P01') {
                throw $error;
            }
            // Undefined table: fresh database.
        }

        if ($currentVersion > PostgresSchema::VERSION) {
            throw new \RuntimeException("PostgreSQL metadata schema version {$currentVersion} is newer than the supported version " . PostgresSchema::VERSION . '.');
        }
        if ($currentVersion === PostgresSchema::VERSION) {
            $this->assertSupportedBucketEncryptionInventory();
            MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
            $this->assertWriteIntentCapacityConsistent();
            MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
            MetadataSchemaGuard::assertPostgresWriteIntentTriggers($this->pool);
            return;
        }

        if ($currentVersion > 0) {
            $this->assertSupportedBucketEncryptionInventory();
            // Existing tables must be migrated before current-schema indexes
            // are created because those indexes may reference new columns.
            foreach (PostgresSchema::getMigrationStatements($currentVersion) as $sql) {
                $this->pool->execute($sql);
            }
        }

        foreach (PostgresSchema::getCreateStatements() as $sql) {
            $this->pool->execute($sql);
        }
        if ($currentVersion > 0) {
            $this->backfillLegacyObjectPartLayouts();
        }
        $this->assertSupportedBucketEncryptionInventory();

        MetadataSchemaGuard::assertMultipartCompletionCapacityConsistent($this->pool);
        $this->assertWriteIntentCapacityConsistent();
        MetadataSchemaGuard::assertStorageCleanupCapacityConsistent($this->pool);
        MetadataSchemaGuard::assertPostgresWriteIntentTriggers($this->pool);

        $this->pool->execute(
            'INSERT INTO s3_schema_version (version, description) VALUES ($1, $2)',
            [PostgresSchema::VERSION, 'Schema version ' . PostgresSchema::VERSION],
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
                $lastId = self::int($checkpoint['last_object_id']);
                $targetId = self::int($checkpoint['target_object_id']);
                if ($lastId > $targetId) {
                    throw new \RuntimeException('Object part layout migration checkpoint exceeds its fixed target.');
                }
                $result = $transaction->execute('SELECT id, etag, size, is_delete_marker FROM s3_objects WHERE id > $1 AND id <= $2 ORDER BY id LIMIT 10000', [$lastId, $targetId]);
                $rows = [];
                while (($row = $result->fetchRow()) !== null) {
                    $rows[] = $row;
                }
                if ($rows === []) {
                    if ($transaction->execute('SELECT 1 FROM s3_objects WHERE id > $1 LIMIT 1', [$targetId])->fetchRow() !== null) {
                        throw new \RuntimeException('Object rows were created beyond the fixed object-layout migration target.');
                    }
                    $transaction->commit();
                    return;
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    $this->writeLegacyObjectPartLayoutBatch($transaction, $chunk);
                }
                $lastRow = $rows[array_key_last($rows)];
                $lastId = self::int($lastRow['id']);
                $transaction->execute("UPDATE s3_migration_checkpoints SET last_object_id = $1 WHERE migration_name = 'object_part_layouts'", [$lastId]);
                $transaction->commit();
            } catch (\Throwable $error) {
                $transaction->rollback();
                throw $error;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeLegacyObjectPartLayoutBatch(PostgresTransaction $transaction, array $rows): void
    {
        $parentValues = [];
        $parentParams = [];
        $objectIds = [];
        $knownSingles = [];

        foreach ($rows as $row) {
            $objectId = self::int($row['id']);
            $objectIds[] = $objectId;
            if ((bool) $row['is_delete_marker']) {
                $kind = null;
                $partCount = null;
                $compatibilityPartCount = null;
                $digest = null;
            } else {
                $layout = ObjectPartLayout::legacy(self::str($row['etag']), self::int($row['size']));
                $summary = $layout->summary($objectId);
                $kind = $summary->kind;
                $partCount = $summary->partCount;
                $compatibilityPartCount = $summary->compatibilityPartCount;
                $digest = $summary->digest;
                if ($summary->kind === ObjectPartLayout::KNOWN_SINGLE) {
                    $knownSingles[] = [$objectId, self::int($row['size'])];
                }
            }

            $offset = count($parentParams);
            $parentValues[] = sprintf(
                '($%d::BIGINT, $%d::VARCHAR, $%d::INTEGER, $%d::INTEGER, $%d::CHAR(64))',
                $offset + 1,
                $offset + 2,
                $offset + 3,
                $offset + 4,
                $offset + 5,
            );
            array_push($parentParams, $objectId, $kind, $partCount, $compatibilityPartCount, $digest);
        }

        $transaction->execute(
            'UPDATE s3_objects AS objects '
            . 'SET part_layout_kind = migrated.kind, part_layout_count = migrated.part_count, '
            . 'part_layout_compat_count = migrated.compatibility_part_count, part_layout_digest = migrated.digest '
            . 'FROM (VALUES ' . implode(', ', $parentValues) . ') AS migrated(id, kind, part_count, compatibility_part_count, digest) '
            . 'WHERE objects.id = migrated.id',
            $parentParams,
        );

        $deletePlaceholders = [];
        foreach (array_keys($objectIds) as $index) {
            $deletePlaceholders[] = '$' . ($index + 1);
        }
        $transaction->execute(
            'DELETE FROM s3_object_parts WHERE object_id IN (' . implode(', ', $deletePlaceholders) . ')',
            $objectIds,
        );

        if ($knownSingles === []) {
            return;
        }

        $childValues = [];
        $childParams = [];
        foreach ($knownSingles as [$objectId, $size]) {
            $offset = count($childParams);
            $childValues[] = sprintf('($%d::BIGINT, 1, NULL, 0, $%d::BIGINT, NULL, NULL)', $offset + 1, $offset + 2);
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
                $transaction->execute("INSERT INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) VALUES ('object_part_layouts', 0, $1)", [self::int($maximum['target_object_id'])]);
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
            'object' => "SELECT COUNT(*) FROM s3_object_write_intents WHERE NOT seal_reserved OR state <> 'sealed'",
            'object_seal' => 'SELECT COUNT(*) FROM s3_object_write_intents WHERE seal_reserved',
            'part' => "SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'",
        ] as $kind => $countSql) {
            try {
                $row = $this->pool->execute(
                    "SELECT retained_count, ({$countSql}) AS actual_count FROM s3_write_intent_capacity WHERE kind = $1",
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
        if ($this->pool->query(\OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::invalidRowQuery('postgres'))->fetchRow() !== null) {
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
                'INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES ($1, $2, $3, $4)',
                [$bucket, $ownerId, $region, bin2hex(random_bytes(16))],
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
        $this->transaction(function () use ($ownerId, $bucket): void {
            $result = $this->conn()->execute(
                'SELECT owner_id FROM s3_buckets WHERE name = $1 FOR UPDATE',
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

            $this->conn()->execute('DELETE FROM s3_parts WHERE upload_id IN (SELECT upload_id FROM s3_multipart_uploads WHERE bucket = $1)', [$bucket]);
            $this->conn()->execute('DELETE FROM s3_multipart_uploads WHERE bucket = $1', [$bucket]);
            foreach ($bucketTables as $table) {
                $this->conn()->execute("DELETE FROM {$table} WHERE bucket = \$1", [$bucket]);
            }
            $this->conn()->execute(
                "DELETE FROM s3_acls
                 WHERE (resource_type = 'bucket' AND resource_name = \$1)
                    OR (resource_type = 'object' AND resource_name LIKE \$2 ESCAPE '\\')",
                [$bucket, $this->escapeLikePattern($bucket . '/') . '%'],
            );
            $this->conn()->execute('DELETE FROM s3_buckets WHERE name = $1 AND owner_id = $2', [$bucket, $ownerId]);
        });
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE name = $1',
            [$bucket],
        );
        $row = $result->fetchRow();

        return $row !== null ? $this->rowToBucketInfo($row) : null;
    }

    public function listBuckets(string $ownerId): array
    {
        $result = $this->conn()->execute(
            'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE owner_id = $1 ORDER BY name ASC',
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
        $row = $this->conn()->execute('SELECT COUNT(*) AS bucket_count FROM s3_buckets WHERE owner_id = $1', [$ownerId])->fetchRow();

        return $row !== null ? (int) $row['bucket_count'] : 0;
    }

    public function listBucketsPage(string $ownerId, ?string $after, ?string $prefix, ?string $region, int $maxBuckets): array
    {
        $pageSize = max(1, min($maxBuckets, 10_000));
        $sql = 'SELECT name, owner_id, region, created_at, generation FROM s3_buckets WHERE owner_id = $1';
        $params = [$ownerId];
        if ($after !== null) {
            $params[] = $after;
            $sql .= ' AND name > $' . count($params);
        }
        if ($prefix !== null) {
            $params[] = $prefix;
            $sql .= ' AND name >= $' . count($params);
            $params[] = $prefix;
            $lengthParam = count($params);
            $params[] = $prefix;
            $sql .= ' AND LEFT(name, length($' . $lengthParam . ')) = $' . count($params);
        }
        if ($region !== null) {
            $params[] = $region;
            $sql .= ' AND region = $' . count($params);
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
            // Preserve the numbered latest version when a suspended bucket
            // writes its replacement null version.
            $this->conn()->execute(
                'UPDATE s3_objects SET is_latest = FALSE, noncurrent_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND is_latest = TRUE',
                [$now, $bucket, $key],
            );

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
                $1, $2, 'null', TRUE, FALSE,
                $3, $4, $5, $6, $7,
                $8, $9, $10, $11,
                $12::jsonb, $13, $14, $15,
                $16, $17::timestamptz, $18::timestamptz
            )
            ON CONFLICT(bucket, key_name, version_id) DO UPDATE SET
                is_latest = TRUE,
                noncurrent_at = NULL,
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
                restored_storage_tier = NULL,
                restore_expires_at = NULL,
                user_metadata = EXCLUDED.user_metadata,
                checksum_crc32 = EXCLUDED.checksum_crc32,
                checksum_crc32c = EXCLUDED.checksum_crc32c,
                checksum_sha1 = EXCLUDED.checksum_sha1,
                checksum_sha256 = EXCLUDED.checksum_sha256,
                created_at = EXCLUDED.created_at,
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
            $objectRow = $this->conn()->execute("SELECT id FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'", [$bucket, $key])->fetchRow();
            if ($objectRow === null) {
                throw new \RuntimeException('New object incarnation cannot be resolved for its part layout.');
            }
            $this->authorizeObjectPartLayoutPublication(self::int($objectRow['id']));
            $this->putObjectPartLayout($bucket, $key, 'null', ObjectPartLayout::knownSingle($size));
        });
    }

    private function clearNullVersionMetadata(string $bucket, string $key, ?PostgresLink $link = null): void
    {
        $link ??= $this->conn();
        $link->execute("DELETE FROM s3_object_parts WHERE object_id IN (SELECT id FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = 'null')", [$bucket, $key]);
        $link->execute("UPDATE s3_objects SET part_layout_kind = NULL, part_layout_count = NULL, part_layout_compat_count = NULL, part_layout_digest = NULL WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'", [$bucket, $key]);
        $link->execute(
            "DELETE FROM s3_object_retention WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            "DELETE FROM s3_object_legal_holds WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 AND version_id = 'null'",
            [$bucket, $key],
        );
        $link->execute(
            'DELETE FROM s3_acls WHERE resource_type = $1 AND resource_name IN ($2, $3)',
            [
                'object',
                $bucket . '/' . $key,
                \OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName($bucket, $key, 'null'),
            ],
        );
        $link->execute("DELETE FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = 'null'", [$bucket, $key]);
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
            WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE AND is_delete_marker = FALSE
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
            WHERE bucket = $1 AND key_name = $2 AND is_latest = TRUE
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

    public function supportsObjectPartLayouts(): bool
    {
        return true;
    }

    public function putObjectPartLayout(string $bucket, string $key, string $versionId, ObjectPartLayout $layout): void
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('Object part layouts must be published inside the object metadata transaction.');
        }
        $row = $this->conn()->execute('SELECT id, size, is_delete_marker FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3', [$bucket, $key, $versionId])->fetchRow();
        if ($row === null || (bool) $row['is_delete_marker'] || self::int($row['size']) !== $layout->objectSize) {
            throw new \RuntimeException('Object part layout does not match a concrete object row.');
        }
        $objectId = self::int($row['id']);
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException('Object part layouts must be published inside the object metadata transaction.');
        }
        $publicationIds = $this->objectPartLayoutPublicationIds[$fiber] ?? [];
        if (! isset($publicationIds[$objectId])) {
            throw new \LogicException('Published object part layouts are immutable.');
        }
        $this->conn()->execute('DELETE FROM s3_object_parts WHERE object_id = $1', [$objectId]);
        $summary = $layout->summary($objectId);
        $this->conn()->execute('UPDATE s3_objects SET part_layout_kind = $1, part_layout_count = $2, part_layout_compat_count = $3, part_layout_digest = $4 WHERE id = $5', [$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $objectId]);
        foreach (array_chunk($layout->parts, 500) as $parts) {
            $values = [];
            $params = [];
            foreach ($parts as $part) {
                $offset = count($params);
                $values[] = sprintf(
                    '($%d::BIGINT, $%d::INTEGER, $%d::INTEGER, $%d::BIGINT, $%d::BIGINT, $%d::VARCHAR, $%d::TEXT)',
                    $offset + 1,
                    $offset + 2,
                    $offset + 3,
                    $offset + 4,
                    $offset + 5,
                    $offset + 6,
                    $offset + 7,
                );
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
        $row = $this->conn()->execute('SELECT id, size, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3', [$object->bucket, $object->key, $object->versionId ?? 'null'])->fetchRow();
        if ($row === null) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        if ($row['part_layout_count'] === null || $row['part_layout_digest'] === null) {
            throw new \RuntimeException('Stored object part layout summary is incomplete.');
        }
        if (! hash_equals($expected->digest, self::str($row['part_layout_digest']))) {
            throw new \RuntimeException('Object part layout snapshot no longer matches the object incarnation.');
        }
        $parts = [];
        $result = $this->conn()->execute('SELECT ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value FROM s3_object_parts WHERE object_id = $1 ORDER BY ordinal', [self::int($row['id'])]);
        while (($part = $result->fetchRow()) !== null) {
            $parts[] = new ObjectPart(self::int($part['ordinal']), $part['upload_part_number'] === null ? null : self::int($part['upload_part_number']), self::int($part['plaintext_offset']), self::int($part['plaintext_size']), $part['checksum_algorithm'] === null ? null : self::str($part['checksum_algorithm']), $part['checksum_value'] === null ? null : self::str($part['checksum_value']));
        }
        if (self::int($row['part_layout_count']) !== $expected->partCount || self::int($row['part_layout_count']) !== count($parts)) {
            throw new \RuntimeException('Stored object part layout count is inconsistent.');
        }
        $layout = new ObjectPartLayout(self::str($row['part_layout_kind']), self::int($row['size']), $parts, $row['part_layout_compat_count'] === null ? null : self::int($row['part_layout_compat_count']));
        if (! hash_equals($expected->digest, $layout->digestForObject(self::int($row['id'])))) {
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
            ? 'bucket = $7 AND key_name = $8 AND is_latest = TRUE AND is_delete_marker = FALSE'
            : 'bucket = $7 AND key_name = $8 AND version_id = $9';
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

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET storage_class = $1,
                storage_tier = $2,
                storage_path = $3,
                transition_status = $4,
                transition_target_tier = $5,
                transition_error = $6
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
            ? 'bucket = $5 AND key_name = $6 AND is_latest = TRUE AND is_delete_marker = FALSE'
            : 'bucket = $5 AND key_name = $6 AND version_id = $7';
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

        $this->conn()->execute(<<<SQL
            UPDATE s3_objects
            SET restore_status = $1,
                restored_storage_path = $2,
                restored_storage_tier = $3,
                restore_expires_at = $4::timestamptz
            WHERE {$where}
        SQL, $params);
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

    public function getOwnerStorageStats(string $ownerId): array
    {
        $row = $this->conn()->execute(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(size), 0) AS total_size FROM s3_objects WHERE owner_id = $1 AND is_delete_marker = FALSE',
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
            'SELECT * FROM s3_account_quotas WHERE owner_id = $1',
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
            'SELECT * FROM s3_account_quotas ORDER BY owner_id COLLATE "C" ASC',
        );

        $quotas = [];
        while (($row = $result->fetchRow()) !== null) {
            $quotas[self::str($row['owner_id'])] = new QuotaConfig(
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
            ? $this->conn()->execute('SELECT * FROM s3_account_quotas ORDER BY owner_id COLLATE "C" ASC LIMIT $1', [$limit])
            : $this->conn()->execute('SELECT * FROM s3_account_quotas WHERE owner_id COLLATE "C" > $1 ORDER BY owner_id COLLATE "C" ASC LIMIT $2', [$after, $limit]);
        $page = [];
        while (($row = $result->fetchRow()) !== null) {
            $ownerId = self::str($row['owner_id']);
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
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
            ON CONFLICT(owner_id) DO UPDATE SET
                max_buckets_per_owner = excluded.max_buckets_per_owner,
                max_objects_per_bucket = excluded.max_objects_per_bucket,
                max_bytes_per_bucket = excluded.max_bytes_per_bucket,
                max_bytes_per_owner = excluded.max_bytes_per_owner,
                max_multipart_uploads_per_bucket = excluded.max_multipart_uploads_per_bucket,
                max_multipart_uploads_per_owner = excluded.max_multipart_uploads_per_owner,
                max_multipart_bytes_per_bucket = excluded.max_multipart_bytes_per_bucket,
                max_multipart_bytes_per_owner = excluded.max_multipart_bytes_per_owner,
                updated_at = excluded.updated_at
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
        \OpsFour\S3Server\Policy\PolicyDocumentValidator::validateIdentityPolicy($policyJson);
        $this->transaction(function () use ($ownerId, $policyJson): void {
            $this->lockOwnerForUpdate($ownerId);
            $this->conn()->execute(
                <<<'SQL'
                INSERT INTO s3_account_policies (owner_id, policy_json) VALUES ($1, $2::jsonb)
                ON CONFLICT(owner_id) DO UPDATE SET policy_json = EXCLUDED.policy_json, updated_at = NOW()
                SQL,
                [$ownerId, $policyJson],
            );
        });
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->transaction(function () use ($ownerId): void {
            $this->lockOwnerForUpdate($ownerId);
            $this->conn()->execute('DELETE FROM s3_account_policies WHERE owner_id = $1', [$ownerId]);
        });
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        $result = $this->conn()->execute('SELECT policy_json::text AS policy_json FROM s3_named_policies WHERE policy_name = $1', [$policyName]);
        $row = $result->fetchRow();

        return $row !== null ? self::str($row['policy_json']) : null;
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
            $row = $this->conn()->execute('SELECT policy_json::text AS policy_json FROM s3_named_policies WHERE policy_name = $1 FOR UPDATE', [$name])->fetchRow();
            if ($row !== null) {
                $policies[$name] = self::str($row['policy_json']);
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
            $row = $this->conn()->execute('SELECT policy_json::text AS policy_json FROM s3_named_policies WHERE policy_name = $1 FOR SHARE', [$name])->fetchRow();
            if ($row !== null) {
                $policies[$name] = self::str($row['policy_json']);
            }
        }

        return $policies;
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        \OpsFour\S3Server\Policy\PolicyDocumentValidator::validateIdentityPolicy($policyJson);
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
            . 'FROM s3_objects WHERE bucket = $1 AND is_latest = TRUE AND is_delete_marker = FALSE';

        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($delimiter === null || $delimiter === '') {
            if ($effectiveStartAfter !== null && $effectiveStartAfter !== '') {
                $sql .= ' AND key_name COLLATE "C" > $' . $paramIndex;
                $params[] = $effectiveStartAfter;
                $paramIndex++;
            }

            $sql .= ' ORDER BY key_name COLLATE "C" ASC';
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

        return DelimitedObjectListing::page(
            fetchRows: function (?string $cursor, int $limit, ?string $afterPrefix) use ($sql, $params, $paramIndex): array {
                $pageSql = $sql;
                $pageParams = $params;
                $limitIndex = $paramIndex;
                if ($afterPrefix !== null) {
                    $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                    if ($upperBound === null) {
                        return [];
                    }
                    $pageSql .= ' AND key_name COLLATE "C" >= $' . $paramIndex;
                    $pageParams[] = $upperBound;
                    $limitIndex++;
                } elseif ($cursor !== null && $cursor !== '') {
                    $pageSql .= ' AND key_name COLLATE "C" > $' . $paramIndex;
                    $pageParams[] = $cursor;
                    $limitIndex++;
                }
                $pageSql .= ' ORDER BY key_name COLLATE "C" ASC LIMIT $' . $limitIndex;
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
            'INSERT INTO s3_multipart_uploads (upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata) VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb)',
            [$uploadId, $bucket, $bucketInfo->generation, $key, $ownerId, $storageTier, $contentType ?? 'application/octet-stream', json_encode($userMetadata, JSON_THROW_ON_ERROR)],
        );
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        self::validateObjectWriteIntent($intent);
        self::validateObjectWriteLease($leaseSeconds);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write admission requires its own transaction.');
        }

        return $this->transaction(function () use ($intent, $leaseSeconds): ObjectWriteIntent {
            // Lock the exact counter row before the owner scan. READ COMMITTED
            // gives a fresh owner snapshot after concurrent admissions finish.
            $this->conn()->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $global = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object' FOR UPDATE")->fetchRow();
            if ($global === null || (int) $global['retained_count'] >= self::MAX_OUTSTANDING_GLOBAL) {
                throw new SlowDownException('Physical write recovery capacity is exhausted.');
            }
            $owner = $this->conn()->execute(
                "SELECT COUNT(*) AS intent_count FROM s3_object_write_intents WHERE owner_id = $1 AND (NOT seal_reserved OR state <> 'sealed')",
                [$intent->ownerId],
            )->fetchRow();
            if ((int) ($owner['intent_count'] ?? 0) >= self::MAX_OUTSTANDING_PER_OWNER) {
                throw new SlowDownException('Physical write recovery capacity is exhausted.');
            }
            if ($intent->sealReserved) {
                $sealGlobal = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object_seal' FOR UPDATE")->fetchRow();
                if ($sealGlobal === null || (int) $sealGlobal['retained_count'] >= self::MAX_SEALS_GLOBAL) {
                    throw new SlowDownException('Physical write seal capacity is exhausted.');
                }
                $sealOwner = $this->conn()->execute('SELECT COUNT(*) AS intent_count FROM s3_object_write_intents WHERE owner_id = $1 AND seal_reserved', [$intent->ownerId])->fetchRow();
                if ((int) ($sealOwner['intent_count'] ?? 0) >= self::MAX_SEALS_PER_OWNER) {
                    throw new SlowDownException('Physical write seal capacity is exhausted.');
                }
            }

            $row = $this->conn()->execute(<<<'SQL'
                INSERT INTO s3_object_write_intents
                    (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                     path_digest, purpose, state, lease_until, dispatch_id, expected_size, expected_sha256, seal_reserved)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'active',
                    EXTRACT(EPOCH FROM clock_timestamp()) + $9::double precision, NULL, NULL, NULL, $10)
                RETURNING *
                SQL, [
                $intent->attemptId, $intent->bucket, $intent->bucketGeneration, $intent->ownerId,
                $intent->storageTier, $intent->storagePath, hash('sha256', $intent->storagePath),
                $intent->purpose, $leaseSeconds, $intent->sealReserved,
            ])->fetchRow();

            return $row === null
                ? throw new \RuntimeException('Reserved physical write intent disappeared.')
                : self::objectWriteIntentFromRow($row);
        });
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        $row = $this->conn()->execute('SELECT * FROM s3_object_write_intents WHERE attempt_id = $1', [$attemptId])->fetchRow();

        return $row === null ? null : self::objectWriteIntentFromRow($row);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        $row = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = EXTRACT(EPOCH FROM clock_timestamp()) + $1::double precision
            WHERE attempt_id = $2 AND state = 'active' AND lease_until = $3::double precision
              AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
              AND EXTRACT(EPOCH FROM clock_timestamp()) + $1::double precision > lease_until
            RETURNING *
            SQL, [$leaseSeconds, $attemptId, sprintf('%.17F', $expectedLeaseUntil)])->fetchRow();

        return $row === null ? null : self::objectWriteIntentFromRow($row);
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
            $row = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET dispatch_id = $1, expected_size = $2, expected_sha256 = $3,
                    lease_until = GREATEST(lease_until, EXTRACT(EPOCH FROM clock_timestamp()) + $4::double precision)
                WHERE attempt_id = $5 AND state = 'active' AND dispatch_id IS NULL
                  AND ((NOT seal_reserved AND $2::bigint IS NULL AND $3::text IS NULL)
                    OR (seal_reserved AND $2::bigint IS NOT NULL AND $3::text IS NOT NULL))
                  AND lease_until = $6::double precision
                  AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
                RETURNING *
                SQL, [$dispatchId, $expectedSize, $expectedSha256, $leaseSeconds, $attemptId, sprintf('%.17F', $expectedLeaseUntil)])->fetchRow();

            return $row === null ? null : self::objectWriteIntentFromRow($row);
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
            $row = $this->conn()->execute(<<<'SQL'
                UPDATE s3_object_write_intents
                SET lease_until = GREATEST(lease_until, EXTRACT(EPOCH FROM clock_timestamp()) + $1::double precision)
                WHERE attempt_id = $2 AND state = 'active' AND dispatch_id = $3
                  AND lease_until = $4::double precision
                  AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
                RETURNING *
                SQL, [$leaseSeconds, $attemptId, $dispatchId, sprintf('%.17F', $expectedLeaseUntil)])->fetchRow();

            return $row === null ? null : self::objectWriteIntentFromRow($row);
        });
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_object_write_intents WHERE attempt_id = $1 AND state = 'active' AND dispatch_id IS NULL",
            [$attemptId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Physical write retirement requires its own committed transaction.');
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_object_write_intents WHERE attempt_id = $1 AND state = 'active' AND dispatch_id = $2",
            [$attemptId, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        $result = $this->conn()->execute(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = $1 AND state = 'active'
              AND (dispatch_id IS NULL OR dispatch_id = '00000000000000000000000000000000')
              AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
            SQL, [$attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        self::validateObjectWriteDispatchId($dispatchId);
        $result = $this->conn()->execute(<<<'SQL'
            DELETE FROM s3_object_write_intents
            WHERE attempt_id = $1 AND state = 'active' AND dispatch_id = $2
              AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
            SQL, [$attemptId, $dispatchId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        $result = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = EXTRACT(EPOCH FROM clock_timestamp())
            WHERE attempt_id = $1 AND state = 'active'
            SQL, [$attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
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
                WITH due AS (
                    SELECT attempt_id FROM s3_object_write_intents
                    WHERE storage_tier = $1 AND state IN ('active', 'reconciling')
                      AND dispatch_id IS NOT NULL
                      AND lease_until <= EXTRACT(EPOCH FROM clock_timestamp())
                    ORDER BY lease_until, attempt_id LIMIT $2 FOR UPDATE SKIP LOCKED
                )
                UPDATE s3_object_write_intents AS intent
                SET state = 'reconciling', claim_generation = intent.claim_generation + 1,
                    lease_until = EXTRACT(EPOCH FROM clock_timestamp()) + $3::double precision
                FROM due WHERE intent.attempt_id = due.attempt_id AND intent.storage_tier = $1
                RETURNING intent.*
                SQL, [$storageTier, $limit, $leaseSeconds]);
            $claimed = [];
            while (($row = $result->fetchRow()) !== null) {
                $claimed[] = self::objectWriteIntentFromRow($row);
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
        $result = $this->conn()->execute(<<<'SQL'
            WITH due AS (
                SELECT attempt_id FROM s3_object_write_intents
                WHERE storage_tier = $1 AND state = 'active' AND dispatch_id IS NULL
                  AND lease_until <= EXTRACT(EPOCH FROM clock_timestamp())
                ORDER BY lease_until, attempt_id LIMIT $2 FOR UPDATE SKIP LOCKED
            )
            DELETE FROM s3_object_write_intents AS intent USING due
            WHERE intent.attempt_id = due.attempt_id
            SQL, [$storageTier, $limit]);

        return (int) ($result->getRowCount() ?? 0);
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        self::validateObjectWriteLease($leaseSeconds);
        $row = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents
            SET lease_until = EXTRACT(EPOCH FROM clock_timestamp()) + $1::double precision
            WHERE attempt_id = $2 AND state = 'reconciling' AND claim_generation = $3
              AND lease_until = $4::double precision
              AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
              AND EXTRACT(EPOCH FROM clock_timestamp()) + $1::double precision > lease_until
            RETURNING *
            SQL, [$leaseSeconds, $attemptId, $claimGeneration, sprintf('%.17F', $expectedLeaseUntil)])->fetchRow();

        return $row === null ? null : self::objectWriteIntentFromRow($row);
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        $result = $this->conn()->execute(<<<'SQL'
            UPDATE s3_object_write_intents SET state = 'sealed'
            WHERE attempt_id = $1 AND state = 'reconciling' AND claim_generation = $2
              AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())
            SQL, [$attemptId, $claimGeneration]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function objectWriteIntentFromRow(array $row): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: self::str($row['attempt_id']),
            bucket: self::str($row['bucket']),
            bucketGeneration: self::str($row['bucket_generation']),
            ownerId: self::str($row['owner_id']),
            storageTier: self::str($row['storage_tier']),
            storagePath: self::str($row['storage_path']),
            purpose: self::str($row['purpose']),
            state: self::str($row['state']),
            leaseUntil: (float) $row['lease_until'],
            claimGeneration: (int) $row['claim_generation'],
            dispatchId: $row['dispatch_id'] === null ? null : self::str($row['dispatch_id']),
            expectedSize: $row['expected_size'] === null ? null : (int) $row['expected_size'],
            expectedSha256: $row['expected_sha256'] === null ? null : self::str($row['expected_sha256']),
            sealReserved: (bool) $row['seal_reserved'],
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
                'SELECT 1 FROM s3_multipart_uploads WHERE upload_id = $1 AND bucket = $2 AND bucket_generation = $3 AND owner_id = $4 AND storage_tier = $5 FOR UPDATE',
                [$intent->uploadId, $intent->bucket, $intent->bucketGeneration, $intent->ownerId, $intent->storageTier],
            )->fetchRow();
            if ($upload === null) {
                throw new \OpsFour\S3Server\Exception\NoSuchUploadException();
            }
            $count = $this->conn()->execute(
                'SELECT COUNT(*) AS intent_count FROM s3_multipart_write_intents WHERE owner_id = $1',
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
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, 'active', $12, $13, NULL)
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
        $row = $this->conn()->query('SELECT EXTRACT(EPOCH FROM clock_timestamp()) AS database_now')->fetchRow();
        $value = $row['database_now'] ?? null;
        if (! is_numeric($value)) {
            throw new \RuntimeException('Metadata database clock is unavailable.');
        }

        return (float) $value;
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        $row = $this->conn()->execute('SELECT * FROM s3_multipart_write_intents WHERE attempt_id = $1', [$attemptId])->fetchRow();

        return $row === null ? null : self::multipartWriteIntentFromRow($row);
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents
            SET lease_until = $1, next_attempt_at = $1
            WHERE attempt_id = $2 AND state = 'active' AND lease_until = $3
            AND lease_until > EXTRACT(EPOCH FROM clock_timestamp()) AND $1 > lease_until",
            [$newLeaseUntil, $attemptId, $expectedLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        $result = $this->conn()->execute("UPDATE s3_multipart_write_intents SET state = 'abandoned',
            active_upload_id = NULL, next_attempt_at = $1 WHERE attempt_id = $2 AND state = 'active'", [$nextAttemptAt, $attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        $result = $this->conn()->execute("UPDATE s3_multipart_write_intents SET state = 'abandoned',
            active_upload_id = NULL, next_attempt_at = $1 WHERE upload_id = $2 AND state = 'active'", [$nextAttemptAt, $uploadId]);

        return $result->getRowCount() ?? 0;
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        $result = $this->conn()->execute("DELETE FROM s3_multipart_write_intents
            WHERE attempt_id = $1 AND state = 'active'
            AND dispatch_id IS NULL
            AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())", [$attemptId]);

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('Invalid multipart completion dispatch ID.');
        }
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET dispatch_id = $1, lease_until = $2, next_attempt_at = $2
             WHERE attempt_id = $3 AND state = 'active' AND dispatch_id IS NULL AND lease_until = $4
               AND lease_until > EXTRACT(EPOCH FROM clock_timestamp()) AND $2 > lease_until",
            [$dispatchId, $newLeaseUntil, $attemptId, $expectedLeaseUntil],
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
             WHERE attempt_id = $1 AND state = 'active' AND (dispatch_id IS NULL OR dispatch_id = $2)",
            [$attemptId, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_multipart_write_intents WHERE attempt_id IN (
                SELECT attempt_id FROM s3_multipart_write_intents
                WHERE storage_tier = $1 AND dispatch_id IS NULL
                  AND ((state = 'active' AND lease_until <= EXTRACT(EPOCH FROM clock_timestamp()))
                    OR (state = 'abandoned' AND next_attempt_at <= EXTRACT(EPOCH FROM clock_timestamp())))
                ORDER BY next_attempt_at, attempt_id LIMIT $2 FOR UPDATE SKIP LOCKED)",
            [$storageTier, max(1, $limit)],
        );

        return $result->getRowCount() ?? 0;
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1 AND state = 'active' AND dispatch_id = $2
               AND lease_until > EXTRACT(EPOCH FROM clock_timestamp())",
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
                WHERE (state = 'active' AND lease_until <= $1)
                   OR (state = 'abandoned' AND next_attempt_at <= $2)
                ORDER BY next_attempt_at, attempt_id LIMIT $3 FOR UPDATE SKIP LOCKED",
                [$databaseNow, $databaseNow, $limit],
            );
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $rows[] = $row;
            }
            $claimed = [];
            foreach ($rows as $row) {
                $this->conn()->execute("UPDATE s3_multipart_write_intents
                    SET state = 'abandoned', active_upload_id = NULL, next_attempt_at = $1, claim_generation = claim_generation + 1
                    WHERE attempt_id = $2", [$databaseLeaseUntil, $row['attempt_id']]);
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
            "UPDATE s3_multipart_write_intents SET next_attempt_at = $1
            WHERE attempt_id = $2 AND state = 'abandoned' AND next_attempt_at = $3",
            [$nextAttemptAt, $attemptId, $expectedLeaseUntil],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = $1
             WHERE attempt_id = $2 AND state = 'abandoned' AND next_attempt_at = $3 AND claim_generation = $4
               AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp()) AND $1 > next_attempt_at",
            [$newLeaseUntil, $attemptId, $expectedLeaseUntil, $claimGeneration],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        $result = $this->conn()->execute(
            "UPDATE s3_multipart_write_intents SET next_attempt_at = $1
             WHERE attempt_id = $2 AND state = 'abandoned' AND next_attempt_at = $3 AND claim_generation = $4
               AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())",
            [$nextAttemptAt, $attemptId, $expectedLeaseUntil, $claimGeneration],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function multipartWriteIntentFromRow(array $row): MultipartWriteIntent
    {
        return new MultipartWriteIntent(
            attemptId: self::str($row['attempt_id']),
            uploadId: self::str($row['upload_id']),
            bucket: self::str($row['bucket']),
            bucketGeneration: self::str($row['bucket_generation']),
            ownerId: self::str($row['owner_id']),
            storageTier: self::str($row['storage_tier']),
            assemblyPath: self::str($row['assembly_path']),
            finalPath: self::str($row['final_path']),
            partDigest: self::str($row['part_digest']),
            sseMode: $row['sse_mode'] === null ? null : self::str($row['sse_mode']),
            leaseUntil: (float) $row['lease_until'],
            nextAttemptAt: (float) $row['next_attempt_at'],
            state: self::str($row['state']),
            claimGeneration: (int) $row['claim_generation'],
            dispatchId: $row['dispatch_id'] === null ? null : self::str($row['dispatch_id']),
        );
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        $result = $this->conn()->execute(
            'SELECT upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE upload_id = $1',
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
            'bucket_generation' => self::str($row['bucket_generation']),
            'key_name' => self::str($row['key_name']),
            'owner_id' => self::str($row['owner_id']),
            'storage_tier' => $row['storage_tier'] === null ? null : self::str($row['storage_tier']),
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

        $sql = 'SELECT upload_id, bucket, key_name, owner_id, content_type, user_metadata, created_at FROM s3_multipart_uploads WHERE bucket = $1';
        $params = [$bucket];
        $paramIndex = 2;
        if ($observedBucket !== null) {
            $generationParam = $paramIndex++;
            $ownerParam = $paramIndex++;
            $sql .= " AND (bucket_generation = \${$generationParam} OR (bucket_generation = '' AND owner_id = \${$ownerParam}))";
            $params[] = $observedBucket->generation;
            $params[] = $observedBucket->ownerId;
        }

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        // S3 orders uploads with the same key by initiation time. The upload ID
        // is only a bytewise tie-breaker for equal timestamps.
        if ($keyMarker !== null && $keyMarker !== '') {
            if ($uploadIdMarker !== null && $uploadIdMarker !== '') {
                $markerSql = 'SELECT created_at FROM s3_multipart_uploads '
                    . 'WHERE bucket = $1 AND key_name COLLATE "C" = $2 AND upload_id COLLATE "C" = $3';
                $markerParams = [$bucket, $keyMarker, $uploadIdMarker];
                if ($observedBucket !== null) {
                    $markerSql .= " AND (bucket_generation = $4 OR (bucket_generation = '' AND owner_id = $5))";
                    $markerParams[] = $observedBucket->generation;
                    $markerParams[] = $observedBucket->ownerId;
                }
                $markerRow = $this->conn()->execute($markerSql . ' LIMIT 1', $markerParams)->fetchRow();
                if ($markerRow !== null) {
                    $keyParam = $paramIndex++;
                    $sameKeyParam = $paramIndex++;
                    $dateParam = $paramIndex++;
                    $sameDateParam = $paramIndex++;
                    $uploadParam = $paramIndex++;
                    $markerCreatedAt = $this->listingTimestamp($markerRow['created_at']);
                    $sql .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$sameKeyParam} AND (created_at > \${$dateParam} OR (created_at = \${$sameDateParam} AND upload_id COLLATE \"C\" > \${$uploadParam}))))";
                    array_push($params, $keyMarker, $keyMarker, $markerCreatedAt, $markerCreatedAt, $uploadIdMarker);
                } else {
                    $keyParam = $paramIndex++;
                    $sameKeyParam = $paramIndex++;
                    $uploadParam = $paramIndex++;
                    // The marker may refer to an upload that was already
                    // removed. Preserve S3's lexicographic same-key fallback.
                    $sql .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$sameKeyParam} AND upload_id COLLATE \"C\" > \${$uploadParam}))";
                    array_push($params, $keyMarker, $keyMarker, $uploadIdMarker);
                }
            } else {
                $sql .= " AND key_name COLLATE \"C\" > \${$paramIndex}";
                $params[] = $keyMarker;
                $paramIndex++;
            }
        }

        $effectiveMax = max(1, min(1000, $maxUploads));
        if ($delimiter !== null && $delimiter !== '') {
            return DelimitedMultipartListing::page(
                fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use ($sql, $params, $paramIndex): array {
                    $query = $sql;
                    $queryParams = $params;
                    $nextParam = $paramIndex;
                    if ($afterPrefix !== null) {
                        $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                        if ($upperBound === null) {
                            return [];
                        }
                        $query .= " AND key_name COLLATE \"C\" >= \${$nextParam}";
                        $queryParams[] = $upperBound;
                        $nextParam++;
                    } elseif ($cursor !== null) {
                        $keyParam = $nextParam++;
                        $sameKeyParam = $nextParam++;
                        $dateParam = $nextParam++;
                        $sameDateParam = $nextParam++;
                        $uploadParam = $nextParam++;
                        $query .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$sameKeyParam} AND (created_at > \${$dateParam} OR (created_at = \${$sameDateParam} AND upload_id COLLATE \"C\" > \${$uploadParam}))))";
                        array_push($queryParams, $cursor['key'], $cursor['key'], $cursor['createdAt'], $cursor['createdAt'], $cursor['uploadId']);
                    }
                    $query .= " ORDER BY key_name COLLATE \"C\" ASC, created_at ASC, upload_id COLLATE \"C\" ASC LIMIT \${$nextParam}";
                    $queryParams[] = $limit;
                    $rows = [];
                    foreach ($this->conn()->execute($query, $queryParams) as $row) {
                        $rows[] = [
                            'upload_id' => self::str($row['upload_id']),
                            'bucket' => self::str($row['bucket']),
                            'key_name' => self::str($row['key_name']),
                            'owner_id' => self::str($row['owner_id']),
                            'content_type' => self::str($row['content_type']),
                            'user_metadata' => self::str($row['user_metadata']),
                            'created_at' => $this->formatTimestamp($row['created_at']),
                            '_listing_created_at' => $this->listingTimestamp($row['created_at']),
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
        $sql .= " ORDER BY key_name COLLATE \"C\" ASC, created_at ASC, upload_id COLLATE \"C\" ASC LIMIT \${$paramIndex}";
        $params[] = $effectiveMax + 1; // Fetch one extra to detect truncation.

        $result = $this->conn()->execute($sql, $params);

        /** @var list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata: string, created_at: string}> $rows */
        $rows = [];
        foreach ($result as $row) {
            $rows[] = [
                'upload_id' => self::str($row['upload_id']),
                'bucket' => self::str($row['bucket']),
                'key_name' => self::str($row['key_name']),
                'owner_id' => self::str($row['owner_id']),
                'content_type' => self::str($row['content_type']),
                'user_metadata' => self::str($row['user_metadata']),
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
                LEFT JOIN s3_parts p ON p.upload_id = u.upload_id
                WHERE 1 = 1';
        $params = [];
        if ($ownerId !== null) {
            $params[] = $ownerId;
            $sql .= ' AND u.owner_id = $' . count($params);
        }
        if ($bucket !== null) {
            $params[] = $bucket;
            $sql .= ' AND u.bucket = $' . count($params);
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
            VALUES ($1, $2, $3, $4, $5, $6, $7)
            ON CONFLICT(upload_id, part_number) DO UPDATE SET
                etag = EXCLUDED.etag,
                size = EXCLUDED.size,
                storage_path = EXCLUDED.storage_path,
                checksum_algorithm = EXCLUDED.checksum_algorithm,
                checksum_value = EXCLUDED.checksum_value,
                created_at = NOW()
            SQL,
            [$uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue],
        );
    }

    public function getParts(string $uploadId): array
    {
        $result = $this->conn()->execute(
            'SELECT part_number, etag, size, storage_path, checksum_algorithm, checksum_value, created_at FROM s3_parts WHERE upload_id = $1 ORDER BY part_number ASC',
            [$uploadId],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => self::int($row['part_number']),
                'etag' => self::str($row['etag']),
                'size' => self::int($row['size']),
                'storage_path' => self::str($row['storage_path']),
                'checksum_algorithm' => $row['checksum_algorithm'] === null ? null : self::str($row['checksum_algorithm']),
                'checksum_value' => $row['checksum_value'] === null ? null : self::str($row['checksum_value']),
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
            . 'FROM s3_parts WHERE upload_id = $1 AND part_number > $2::bigint '
            . 'ORDER BY part_number ASC LIMIT $3',
            [$uploadId, $afterPartNumber, $limit],
        );

        $parts = [];
        foreach ($result as $row) {
            $parts[] = [
                'part_number' => self::int($row['part_number']),
                'etag' => self::str($row['etag']),
                'size' => self::int($row['size']),
                'storage_path' => self::str($row['storage_path']),
                'checksum_algorithm' => $row['checksum_algorithm'] === null ? null : self::str($row['checksum_algorithm']),
                'checksum_value' => $row['checksum_value'] === null ? null : self::str($row['checksum_value']),
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
                'UPDATE s3_objects SET is_latest = FALSE, noncurrent_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND is_latest = TRUE',
                [$now, $bucket, $key],
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
            $layout = ObjectPartLayout::knownSingle($size);
            $objectRow = $link->execute('SELECT id FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3', [$bucket, $key, $versionId])->fetchRow();
            if ($objectRow === null) {
                throw new \RuntimeException('New object version cannot be resolved for its part layout.');
            }
            $objectId = self::int($objectRow['id']);
            if ($existingTx !== null) {
                $this->authorizeObjectPartLayoutPublication($objectId);
            }
            $summary = $layout->summary($objectId);
            $link->execute('UPDATE s3_objects SET part_layout_kind = $1, part_layout_count = $2, part_layout_compat_count = $3, part_layout_digest = $4 WHERE id = $5', [$summary->kind, $summary->partCount, $summary->compatibilityPartCount, $summary->digest, $objectId]);
            $link->execute('INSERT INTO s3_object_parts (object_id, ordinal, upload_part_number, plaintext_offset, plaintext_size, checksum_algorithm, checksum_value) VALUES ($1, 1, NULL, 0, $2, NULL, NULL)', [$objectId, $size]);

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
                'UPDATE s3_objects SET is_latest = FALSE, noncurrent_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND is_latest = TRUE',
                [$now, $bucket, $key],
            );

            if ($suspended) {
                // Suspended: upsert delete marker with version_id='null'.
                $this->clearNullVersionMetadata($bucket, $key, $link);
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
                        noncurrent_at = NULL,
                        is_delete_marker = TRUE,
                        owner_id = EXCLUDED.owner_id,
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
                        user_metadata = '{}'::jsonb,
                        checksum_crc32 = NULL,
                        checksum_crc32c = NULL,
                        checksum_sha1 = NULL,
                        checksum_sha256 = NULL,
                        created_at = EXCLUDED.created_at,
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
                restore_status, restored_storage_path, restored_storage_tier, restore_expires_at, storage_path,
                user_metadata, checksum_crc32, checksum_crc32c, checksum_sha1,
                checksum_sha256, part_layout_kind, part_layout_count, part_layout_compat_count, part_layout_digest, created_at, noncurrent_at, updated_at
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

            if (! empty($objectInfo->systemMetadata['isLatest'])) {
                $link->execute(
                    <<<'SQL'
                    UPDATE s3_objects
                    SET is_latest = TRUE, noncurrent_at = NULL
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
            $link->execute(
                "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 AND version_id = $3",
                [$bucket, $key, $effectiveVersionId],
            );
            $link->execute(
                "DELETE FROM s3_acls WHERE resource_type = 'object' AND resource_name = $1",
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
            . 'FROM s3_objects WHERE bucket = $1';

        $params = [$bucket];
        $paramIndex = 2;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($keyMarker !== null && $keyMarker !== '') {
            if ($versionIdMarker !== null && $versionIdMarker !== '') {
                // Resolve the marker row; version_id is random and does not define listing order.
                $markerResult = $this->conn()->execute(
                    'SELECT id, is_latest, created_at FROM s3_objects WHERE bucket = $1 AND key_name = $2 AND version_id = $3 LIMIT 1',
                    [$bucket, $keyMarker, $versionIdMarker],
                );
                $markerRow = null;
                foreach ($markerResult as $r) {
                    $markerRow = $r;
                    break;
                }
                if ($markerRow !== null) {
                    $latestParam = $paramIndex + 1;
                    $dateParam = $paramIndex + 2;
                    $idParam = $paramIndex + 3;
                    $sql .= " AND (key_name COLLATE \"C\" > \${$paramIndex} OR (key_name COLLATE \"C\" = \${$paramIndex} AND (is_latest < \${$latestParam} OR (is_latest = \${$latestParam} AND (created_at < \${$dateParam} OR (created_at = \${$dateParam} AND id < \${$idParam}))))))";
                    $params[] = $keyMarker;
                    $params[] = $markerRow['is_latest'];
                    $params[] = $markerRow['created_at'];
                    $params[] = (int) $markerRow['id'];
                    $paramIndex += 4;
                } else {
                    $sql .= " AND key_name COLLATE \"C\" > \${$paramIndex}";
                    $params[] = $keyMarker;
                    $paramIndex++;
                }
            } else {
                $sql .= " AND key_name COLLATE \"C\" > \${$paramIndex}";
                $params[] = $keyMarker;
                $paramIndex++;
            }
        }

        if ($delimiter === null || $delimiter === '') {
            $sql .= " ORDER BY key_name COLLATE \"C\" ASC, is_latest DESC, created_at DESC, id DESC LIMIT \${$paramIndex}";
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
                'entries' => $entries,
                'isTruncated' => $isTruncated,
                'nextKeyMarker' => $nextKeyMarker,
                'nextVersionIdMarker' => $nextVersionIdMarker,
                'commonPrefixes' => [],
            ];
        }

        return DelimitedVersionListing::page(
            fetchRows: function (?array $cursor, int $limit, ?string $afterPrefix) use ($sql, $params, $paramIndex): array {
                $query = $sql;
                $queryParams = $params;
                $nextParam = $paramIndex;
                if ($afterPrefix !== null) {
                    $upperBound = ListingPrefixRange::exclusiveUpperBound($afterPrefix);
                    if ($upperBound === null) {
                        return [];
                    }
                    $query .= " AND key_name COLLATE \"C\" >= \${$nextParam}";
                    $queryParams[] = $upperBound;
                    $nextParam++;
                } elseif ($cursor !== null) {
                    $keyParam = $nextParam++;
                    $latestParam = $nextParam++;
                    $dateParam = $nextParam++;
                    $idParam = $nextParam++;
                    $query .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$keyParam} AND (is_latest < \${$latestParam} OR (is_latest = \${$latestParam} AND (created_at < \${$dateParam} OR (created_at = \${$dateParam} AND id < \${$idParam}))))))";
                    array_push($queryParams, $cursor['key'], $cursor['isLatest'], $cursor['createdAt'], $cursor['id']);
                }
                $query .= " ORDER BY key_name COLLATE \"C\" ASC, is_latest DESC, created_at DESC, id DESC LIMIT \${$nextParam}";
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

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_object_retention WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
            [$bucket, $key, $versionId ?? 'null'],
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

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        $result = $this->conn()->execute(
            "SELECT tag_key, tag_value FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 AND version_id = $3 ORDER BY id ASC",
            [$bucket, $key, $versionId ?? 'null'],
        );

        $tags = [];
        foreach ($result as $row) {
            $tags[] = ['key' => self::str($row['tag_key']), 'value' => self::str($row['tag_value'])];
        }

        return $tags;
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $existingTx = $this->fiberTransaction();
        $ownTx = ($existingTx === null);
        $link = $ownTx ? $this->pool->beginTransaction() : $existingTx;

        try {
            $link->execute("DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 AND version_id = $3", [$bucket, $key, $versionId ?? 'null']);
            foreach ($tags as $tag) {
                $link->execute(
                    "INSERT INTO s3_tagging (resource_type, bucket, key_name, version_id, tag_key, tag_value) VALUES ('object', $1, $2, $3, $4, $5)",
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
            "DELETE FROM s3_tagging WHERE resource_type = 'object' AND bucket = $1 AND key_name = $2 AND version_id = $3",
            [$bucket, $key, $versionId ?? 'null'],
        );
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
            'SELECT rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds FROM s3_cors_rules WHERE bucket = $1 ORDER BY rule_order ASC',
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
                $rule['id'] = self::str($row['rule_id']);
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
            $link->execute('DELETE FROM s3_cors_rules WHERE bucket = $1', [$bucket]);
            foreach ($rules as $index => $rule) {
                $link->execute(
                    'INSERT INTO s3_cors_rules (bucket, rule_order, rule_id, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds) VALUES ($1, $2, $3, $4::jsonb, $5::jsonb, $6::jsonb, $7::jsonb, $8)',
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

        $config = [
            'sseAlgorithm' => self::str($row['sse_algorithm']),
            'kmsMasterKeyId' => $row['kms_master_key_id'] !== null ? self::str($row['kms_master_key_id']) : null,
            'bucketKeyEnabled' => (bool) $row['bucket_key_enabled'],
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

    public function listReferencedStorageTiers(): array
    {
        return StorageTierReferences::collect(
            $this->conn()->query(StorageTierReferences::TIER_SQL),
            $this->conn()->query(StorageTierReferences::LIFECYCLE_SQL),
        );
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
        string $origin = 'direct',
        ?string $lifecycleRuleId = null,
        ?bool $expectedIsLatest = null,
    ): int {
        $result = $this->conn()->execute(
            'INSERT INTO s3_tier_transition_jobs (
                bucket, key_name, version_id, source_tier, target_tier,
                target_storage_class, source_storage_path, max_attempts, next_attempt_at,
                origin, lifecycle_rule_id, expected_is_latest
             ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
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
                $this->multipartWriteIntentNow(),
                $origin,
                $lifecycleRuleId,
                $expectedIsLatest === null ? null : (int) $expectedIsLatest,
            ],
        );
        $row = $result->fetchRow();

        return $row !== null ? (int) $row['id'] : 0;
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        $this->conn()->execute(
            "UPDATE s3_tier_transition_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now],
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
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 2), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_tier_transition_jobs
                     SET status = 'processing', next_attempt_at = $1, lease_generation = lease_generation + 1, updated_at = NOW()
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
            ? 'UPDATE s3_tier_transition_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1, target_storage_path = COALESCE($4, target_storage_path), updated_at = NOW() WHERE id = $5'
            : 'UPDATE s3_tier_transition_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), target_storage_path = COALESCE($4, target_storage_path), updated_at = NOW() WHERE id = $5';
        $params = [$status, $error, $nextAttemptAt, $targetStoragePath, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = $6 AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = $' . (count($params) + 1);
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_tier_transition_jobs
             SET next_attempt_at = $1, updated_at = NOW()
             WHERE id = $2 AND status = 'processing'";
        $params = [$leaseExpiresAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= ' AND next_attempt_at = $3 AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())';
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = $' . (count($params) + 1);
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
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
            [$bucket, $key, $versionId, $sourceTier, $sourceStoragePath, max(1, $restoreDays), max(1, $maxAttempts), $this->multipartWriteIntentNow()],
        );
        $row = $result->fetchRow();

        return $row !== null ? (int) $row['id'] : 0;
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = max(1, min(1000, $limit));
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        $this->conn()->execute(
            "UPDATE s3_restore_jobs SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now],
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
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 2), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_restore_jobs
                     SET status = 'processing', next_attempt_at = $1, lease_generation = lease_generation + 1, updated_at = NOW()
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
            ? 'UPDATE s3_restore_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1, restored_storage_path = COALESCE($4, restored_storage_path), updated_at = NOW() WHERE id = $5'
            : 'UPDATE s3_restore_jobs SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), restored_storage_path = COALESCE($4, restored_storage_path), updated_at = NOW() WHERE id = $5';
        $params = [$status, $error, $nextAttemptAt, $restoredStoragePath, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = $6 AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = $' . (count($params) + 1);
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null, ?int $expectedLeaseGeneration = null): bool
    {
        $sql
            = "UPDATE s3_restore_jobs
             SET next_attempt_at = $1, updated_at = NOW()
             WHERE id = $2 AND status = 'processing'";
        $params = [$leaseExpiresAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= ' AND next_attempt_at = $3 AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())';
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = $' . (count($params) + 1);
            $params[] = $expectedLeaseGeneration;
        }
        $result = $this->conn()->execute($sql, $params);

        return ($result->getRowCount() ?? 0) > 0;
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
        return $this->transaction(function () use ($ip, $maxTokens, $refillRate): bool {
            $this->conn()->execute(
                'INSERT INTO s3_rate_limit_buckets (ip, tokens, last_refill_at)
                 VALUES ($1, $2, EXTRACT(EPOCH FROM clock_timestamp()))
                 ON CONFLICT (ip) DO NOTHING',
                [$ip, $maxTokens],
            );
            $row = $this->conn()->execute(
                'SELECT tokens, last_refill_at, EXTRACT(EPOCH FROM clock_timestamp()) AS database_now
                 FROM s3_rate_limit_buckets WHERE ip = $1 FOR UPDATE',
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
                'UPDATE s3_rate_limit_buckets SET tokens = $1, last_refill_at = $2 WHERE ip = $3',
                [$allowed ? $available - 1.0 : $available, $now, $ip],
            );

            return $allowed;
        });
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->conn()->execute(
            'DELETE FROM s3_rate_limit_buckets
             WHERE last_refill_at < EXTRACT(EPOCH FROM clock_timestamp()) - $1',
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
        $row = $this->conn()->execute(
            'INSERT INTO s3_notification_queue (bucket, key_name, event_name, destination_url, payload_json, max_attempts, next_attempt_at)
             VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id',
            [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts, $this->multipartWriteIntentNow()],
        )->fetchRow();

        return (int) ($row['id'] ?? 0);
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->conn()->execute('UPDATE s3_notification_queue SET payload_json = $1 WHERE id = $2', [$payloadJson, $id]);
    }

    public function dequeueNotifications(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        $now = $this->multipartWriteIntentNow();
        $leaseExpiresAt = QueueLease::expiresAt($now);

        // Reset stale "processing" items (stuck > 5 minutes) — outside transaction.
        $this->conn()->execute(
            "UPDATE s3_notification_queue SET status = 'pending'
             WHERE status = 'processing' AND next_attempt_at < $1",
            [$now],
        );

        // FOR UPDATE SKIP LOCKED requires a transaction to hold the lock.
        $tx = $this->pool->beginTransaction();

        try {
            $result = $tx->execute(
                "SELECT id, bucket, key_name, event_name, destination_url, payload_json, attempts, max_attempts, lease_generation
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
                $placeholders = implode(',', array_map(fn(int $i) => '$' . ($i + 2), range(0, count($ids) - 1)));
                $tx->execute(
                    "UPDATE s3_notification_queue
                     SET status = 'processing', next_attempt_at = $1, lease_generation = lease_generation + 1
                     WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...array_map(fn($id) => (int) $id, $ids)],
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
            $stats[self::str($row['status'])] = (int) $row['count'];
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
            $counts[self::str($row['kind'])] = (int) $row['retained_count'];
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

    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier === '') {
            throw new \InvalidArgumentException('AWS operational summary requires an exact storage tier.');
        }
        $row = $this->conn()->execute(<<<'SQL'
            WITH object_rows AS (
                SELECT owner_id, state, seal_reserved, dispatch_id, created_at
                FROM s3_object_write_intents WHERE storage_tier = $1
            ), part_rows AS (
                SELECT owner_id, attempts, created_at FROM s3_storage_garbage
                WHERE storage_tier = $1 AND operation = 'part_write_intent'
            ), cleanup_rows AS (
                SELECT attempts, created_at FROM s3_storage_garbage
                WHERE storage_tier = $1 AND operation <> 'part_write_intent'
            ), multipart_rows AS (
                SELECT created_at FROM s3_multipart_write_intents WHERE storage_tier = $1
            ), owner_object AS (
                SELECT owner_id,
                       COUNT(*) FILTER (WHERE NOT seal_reserved OR state <> 'sealed') AS open_count,
                       COUNT(*) FILTER (WHERE seal_reserved) AS seal_count
                FROM object_rows GROUP BY owner_id
            ), owner_part AS (
                SELECT owner_id, COUNT(*) AS part_count FROM part_rows
                WHERE owner_id IS NOT NULL GROUP BY owner_id
            ), oldest AS (
                SELECT MIN(created_at) AS created_at FROM (
                    SELECT created_at FROM object_rows WHERE NOT (seal_reserved AND state = 'sealed')
                    UNION ALL SELECT created_at FROM part_rows
                    UNION ALL SELECT created_at FROM cleanup_rows
                    UNION ALL SELECT created_at FROM multipart_rows
                ) retained
            )
            SELECT
                (SELECT COUNT(*) FROM object_rows WHERE NOT seal_reserved OR state <> 'sealed') AS open_count,
                (SELECT COUNT(*) FROM object_rows
                    WHERE state = 'reconciling' OR (state = 'active' AND dispatch_id IS NOT NULL)) AS ambiguous_count,
                (SELECT COUNT(*) FROM object_rows WHERE seal_reserved) AS seal_reserved_count,
                (SELECT COUNT(*) FROM object_rows WHERE seal_reserved AND state = 'sealed') AS sealed_count,
                (SELECT COUNT(*) FROM multipart_rows) AS multipart_count,
                (SELECT COUNT(*) FROM part_rows) AS part_count,
                (SELECT COUNT(*) FROM cleanup_rows) AS cleanup_count,
                (SELECT COUNT(*) FROM cleanup_rows WHERE attempts > 0) +
                    (SELECT COUNT(*) FROM part_rows WHERE attempts > 0) AS failure_count,
                COALESCE(GREATEST(0, EXTRACT(EPOCH FROM clock_timestamp() - (SELECT created_at FROM oldest))), 0) AS oldest_age_seconds,
                COALESCE((SELECT MAX(open_count) FROM owner_object), 0) AS max_owner_open,
                COALESCE((SELECT MAX(seal_count) FROM owner_object), 0) AS max_owner_sealed,
                COALESCE((SELECT MAX(part_count) FROM owner_part), 0) AS max_owner_part
            SQL, [$storageTier])->fetchRow();
        if ($row === null) {
            throw new \RuntimeException('AWS operational summary is unavailable.');
        }

        return [
            'open' => self::int($row['open_count']),
            'ambiguous' => self::int($row['ambiguous_count']),
            'seal_reserved' => self::int($row['seal_reserved_count']),
            'sealed' => self::int($row['sealed_count']),
            'multipart' => self::int($row['multipart_count']),
            'part' => self::int($row['part_count']),
            'cleanup' => self::int($row['cleanup_count']),
            'failures' => self::int($row['failure_count']),
            'oldest_age_seconds' => self::float($row['oldest_age_seconds']),
            'max_owner_open' => self::int($row['max_owner_open']),
            'max_owner_sealed' => self::int($row['max_owner_sealed']),
            'max_owner_part' => self::int($row['max_owner_part']),
        ];
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
            ? 'UPDATE s3_notification_queue SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at), attempts = attempts + 1 WHERE id = $4'
            : 'UPDATE s3_notification_queue SET status = $1, last_error = $2, next_attempt_at = COALESCE($3, next_attempt_at) WHERE id = $4';
        $params = [$status, $error, $nextAttemptAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = $5 AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())";
            $params[] = $expectedLeaseExpiresAt;
        }
        if ($expectedLeaseGeneration !== null) {
            $sql .= ' AND lease_generation = $' . (count($params) + 1);
            $params[] = $expectedLeaseGeneration;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $cutoff = $this->multipartWriteIntentNow() - $maxAgeSeconds;
        $this->conn()->execute(
            "DELETE FROM s3_notification_queue WHERE status IN ('sent', 'dead_letter') AND next_attempt_at < $1",
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
                 VALUES ($1, $2, $3, $4, $5)',
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
                 VALUES ($1, $2, $3, $4, TRUE, $5)',
                [$bucket, $storageTier, $storagePath, $ownerId, $this->multipartWriteIntentNow()],
            );
        } catch (\Throwable $error) {
            $this->translateStorageCleanupCapacity($error);
        }
    }

    public function listMultipartPartWriteIntents(string $ownerId, int $afterId, int $limit): array
    {
        $result = $this->conn()->execute(
            "SELECT id, bucket, storage_tier, storage_path, status, attempts, created_at::text AS created_at, next_attempt_at
             FROM s3_storage_garbage
             WHERE operation = 'part_write_intent' AND owner_id = $1 AND id > $2
             ORDER BY id LIMIT $3",
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
            $this->conn()->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            // Part commits hold the owner lock before their DELETE trigger
            // touches this counter. Keep the same order for admission.
            $this->lockOwnerForUpdate($ownerId);
            $global = $this->conn()->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part' FOR UPDATE")->fetchRow();
            if ($global === null || (int) $global['retained_count'] >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $countRow = $this->conn()->execute(
                "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = $1",
                [$ownerId],
            )->fetchRow();
            if ((int) ($countRow['n'] ?? 0) >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS) {
                throw new \OpsFour\S3Server\Exception\SlowDownException('Multipart part recovery capacity is exhausted.');
            }
            $existing = $this->conn()->execute(
                "SELECT 1 FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = $1 AND upload_id = $2 AND storage_path = $3 LIMIT 1",
                [$ownerId, $uploadId, $storagePath],
            )->fetchRow();
            $committed = $this->conn()->execute(
                'SELECT 1 FROM s3_parts WHERE upload_id = $1 AND storage_path = $2 LIMIT 1',
                [$uploadId, $storagePath],
            )->fetchRow();
            if ($existing !== null || $committed !== null) {
                throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart part path has already been used.');
            }
            $due = $this->multipartWriteIntentNow() + 43_200;
            $result = $this->conn()->execute(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
             SELECT bucket, storage_tier, $1, 'part_write_intent', key_name, upload_id, owner_id, $2, $3
             FROM s3_multipart_uploads
             WHERE upload_id = $4 AND bucket = $5 AND bucket_generation = $6 AND owner_id = $7 AND storage_tier = $8",
                [$storagePath, $due, $localReservation ? null : ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $uploadId, $bucket, $bucketGeneration, $ownerId, $storageTier],
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
             WHERE g.id = $1 AND g.storage_path = $2 AND g.operation = 'part_write_intent'
               AND g.status = 'processing' AND g.lease_generation = $3
               AND g.next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())
               AND g.upload_id IS NOT NULL AND g.owner_id IS NOT NULL
               AND g.dispatch_id IS NOT NULL AND g.dispatch_id != $4
               AND NOT EXISTS (
                   SELECT 1 FROM s3_parts AS p
                   WHERE p.upload_id = g.upload_id AND p.storage_path = g.storage_path
               )
               AND NOT EXISTS (
                   SELECT 1 FROM s3_storage_garbage AS other
                   WHERE other.id != g.id AND other.operation = 'part_write_intent'
                     AND other.owner_id = g.owner_id AND other.upload_id = g.upload_id
                     AND other.storage_path = g.storage_path
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
            "UPDATE s3_storage_garbage SET dispatch_id = $1 WHERE bucket = $2 AND upload_id = $3 AND storage_path = $4
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id IS NULL
               AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())",
            [$dispatchId, $bucket, $uploadId, $storagePath],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE bucket = $1 AND upload_id = $2 AND storage_path = $3
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id = $4
               AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())",
            [$bucket, $uploadId, $storagePath, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE bucket = $1 AND upload_id = $2 AND storage_path = $3
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id IS NULL",
            [$bucket, $uploadId, $storagePath],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        if (! preg_match('/^[0-9a-f]{32}$/D', $dispatchId) || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            return false;
        }
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage AS g WHERE g.bucket = $1 AND g.upload_id = $2 AND g.storage_path = $3
               AND g.operation = 'part_write_intent' AND g.status = 'pending' AND g.dispatch_id = $4
               AND NOT EXISTS (
                   SELECT 1 FROM s3_parts AS p WHERE p.upload_id = g.upload_id AND p.storage_path = g.storage_path
               )",
            [$bucket, $uploadId, $storagePath, $dispatchId],
        );

        return ($result->getRowCount() ?? 0) === 1;
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        $result = $this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE id IN (
                SELECT id FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND storage_tier = $1
                  AND status = 'pending' AND dispatch_id IS NULL
                  AND next_attempt_at <= EXTRACT(EPOCH FROM clock_timestamp()) ORDER BY id LIMIT $2)",
            [$storageTier, max(1, $limit)],
        );

        return $result->getRowCount() ?? 0;
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        $legacy = ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH;
        if ($this->conn()->execute("SELECT 1 FROM s3_storage_garbage WHERE storage_tier = $1 AND operation = 'part_write_intent' AND dispatch_id = $2 LIMIT 1", [$storageTier, $legacy])->fetchRow() !== null) {
            throw new \RuntimeException('Legacy multipart part intent blocks local fence admission.');
        }
        if ($this->conn()->execute('SELECT 1 FROM s3_multipart_write_intents WHERE storage_tier = $1 AND dispatch_id = $2 LIMIT 1', [$storageTier, $legacy])->fetchRow() !== null) {
            throw new \RuntimeException('Legacy multipart completion intent blocks local fence admission.');
        }
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        $row = $this->conn()->execute(
            "SELECT operation FROM s3_storage_garbage
             WHERE storage_tier = $1
               AND CASE WHEN operation IN ('delete_path', 'part_write_intent') THEN 0 ELSE 1 END = 1
             LIMIT 1",
            [$storageTier],
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
             WHERE bucket = $1 AND upload_id = $2 AND storage_path = $3
               AND operation = 'part_write_intent' AND status = 'pending' AND dispatch_id = $4 AND next_attempt_at > $5",
            [$bucket, $uploadId, $storagePath, ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $this->multipartWriteIntentNow()],
        );

        return $result->getRowCount() === 1;
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        if ($this->fiberTransaction() === null) {
            throw new \LogicException('Bucket multipart cleanup must be staged inside a transaction.');
        }
        $unknown = $this->conn()->execute(
            'SELECT 1 FROM s3_multipart_uploads WHERE bucket = $1 AND (storage_tier IS NULL OR storage_tier = $2) LIMIT 1',
            [$bucket, ''],
        )->fetchRow();
        if ($unknown !== null) {
            throw new \RuntimeException('Legacy multipart upload has no pinned storage tier.');
        }
        try {
            $this->conn()->execute(
                "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at)
             SELECT u.bucket, u.storage_tier, p.storage_path, 'delete_path', u.key_name, u.upload_id, u.owner_id, $1
             FROM s3_multipart_uploads AS u
             JOIN s3_parts AS p ON p.upload_id = u.upload_id
             WHERE u.bucket = $2",
                [$this->multipartWriteIntentNow(), $bucket],
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
            "SELECT 1 FROM s3_multipart_uploads WHERE bucket = $1
               AND NOT (bucket_generation = $2 OR (bucket_generation = '' AND owner_id = $3)) LIMIT 1",
            [$bucket->name, $bucket->generation, $bucket->ownerId],
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
             WHERE bucket = $1 AND storage_tier = $2 AND storage_path = $3 AND operation = 'delete_path'
               AND status = 'pending'",
            [$bucket, $storageTier, $storagePath],
        );
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('Queue claims require their own committed transaction.');
        }
        $limit = QueueLease::boundedBatchSize($limit);
        return $this->transaction(function () use ($limit): array {
            $now = $this->multipartWriteIntentNow();
            $leaseExpiresAt = QueueLease::expiresAt($now);
            $this->conn()->execute(
                "UPDATE s3_storage_garbage SET status = 'pending'
                 WHERE status = 'processing' AND next_attempt_at < $1",
                [$now],
            );
            $result = $this->conn()->execute(
                "SELECT id, bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, attempts, lease_generation, dispatch_id
                 FROM s3_storage_garbage
                 WHERE status = 'pending' AND next_attempt_at <= $1
                   AND NOT (operation = 'part_write_intent' AND dispatch_id IS NULL)
                 ORDER BY id FOR UPDATE SKIP LOCKED LIMIT $2",
                [$now, $limit],
            );
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                $id = $row['id'] ?? null;
                $bucket = $row['bucket'] ?? null;
                $storageTier = $row['storage_tier'] ?? null;
                $storagePath = $row['storage_path'] ?? null;
                $operation = $row['operation'] ?? null;
                $keyName = $row['key_name'] ?? null;
                $uploadId = $row['upload_id'] ?? null;
                $ownerId = $row['owner_id'] ?? null;
                $attempts = $row['attempts'] ?? null;
                $leaseGeneration = $row['lease_generation'] ?? null;
                $dispatchId = $row['dispatch_id'] ?? null;
                if (! is_scalar($id) || ! is_scalar($bucket) || ! is_scalar($storageTier) || ! is_scalar($storagePath)
                    || ! is_scalar($operation) || ($keyName !== null && ! is_scalar($keyName))
                    || ($uploadId !== null && ! is_scalar($uploadId)) || ($ownerId !== null && ! is_scalar($ownerId)) || ! is_scalar($attempts) || ! is_scalar($leaseGeneration)
                    || ($dispatchId !== null && ! is_scalar($dispatchId))) {
                    throw new \RuntimeException('Storage garbage queue returned an invalid row.');
                }
                $rows[] = [
                    'id' => (int) $id,
                    'bucket' => (string) $bucket,
                    'storage_tier' => (string) $storageTier,
                    'storage_path' => (string) $storagePath,
                    'operation' => (string) $operation,
                    'key_name' => $keyName === null ? null : (string) $keyName,
                    'upload_id' => $uploadId === null ? null : (string) $uploadId,
                    'owner_id' => $ownerId === null ? null : (string) $ownerId,
                    'attempts' => (int) $attempts,
                    'lease_expires_at' => $leaseExpiresAt,
                    'lease_generation' => (int) $leaseGeneration + 1,
                    'dispatch_id' => $dispatchId === null ? null : (string) $dispatchId,
                ];
            }
            if ($rows !== []) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_map(fn(int $index): string => '$' . ($index + 2), array_keys($ids)));
                $updated = $this->conn()->execute(
                    "UPDATE s3_storage_garbage SET status = 'processing', next_attempt_at = $1,
                     lease_generation = lease_generation + 1 WHERE id IN ({$placeholders})",
                    [$leaseExpiresAt, ...$ids],
                )->getRowCount();
                if ($updated !== count($rows)) {
                    throw new \RuntimeException('Storage garbage queue claim changed while locked.');
                }
            }

            return $rows;
        });
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        $sql = 'DELETE FROM s3_storage_garbage WHERE id = $1';
        $params = [$id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = $2";
            $params[] = $expectedLeaseExpiresAt;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "DELETE FROM s3_storage_garbage WHERE id = $1 AND status = 'processing'
             AND next_attempt_at = $2 AND lease_generation = $3",
            [$id, $leaseExpiresAt, $leaseGeneration],
        )->getRowCount() ?? 0) === 1;
    }

    private function canDeleteAwsGarbageClaim(int $id, string $bucket, string $tier, string $path, string $ownerId, int $generation): bool
    {
        if ($ownerId === '' || $path === '' || $generation < 1) {
            return false;
        }
        $row = $this->conn()->execute(
            "SELECT 1 FROM s3_storage_garbage AS g
             WHERE g.id = $1 AND g.bucket = $2 AND g.storage_tier = $3
               AND g.storage_path = $4 AND g.owner_id = $5
               AND g.operation = 'delete_path' AND g.status = 'processing'
               AND g.lease_generation = $6
               AND g.next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp())
               AND NOT EXISTS (SELECT 1 FROM s3_objects AS o WHERE o.storage_path = g.storage_path OR o.restored_storage_path = g.storage_path)
               AND NOT EXISTS (SELECT 1 FROM s3_parts AS p WHERE p.storage_path = g.storage_path)
               AND NOT EXISTS (
                   SELECT 1 FROM s3_storage_garbage AS part_intent
                   WHERE part_intent.id != g.id
                     AND part_intent.operation = 'part_write_intent'
                     AND part_intent.storage_path = g.storage_path
               )
               AND NOT EXISTS (SELECT 1 FROM s3_object_write_intents AS i WHERE i.storage_path = g.storage_path)
               AND NOT EXISTS (SELECT 1 FROM s3_multipart_write_intents AS m WHERE m.assembly_path = g.storage_path OR m.final_path = g.storage_path)
               AND NOT EXISTS (SELECT 1 FROM s3_tier_transition_jobs AS t WHERE (t.source_storage_path = g.storage_path OR t.target_storage_path = g.storage_path) AND t.status IN ('pending', 'processing'))
               AND NOT EXISTS (SELECT 1 FROM s3_restore_jobs AS r WHERE (r.source_storage_path = g.storage_path OR r.restored_storage_path = g.storage_path) AND r.status IN ('pending', 'processing'))
             LIMIT 1",
            [$id, $bucket, $tier, $path, $ownerId, $generation],
        )->fetchRow();

        return $row !== null;
    }

    public function deleteAwsGarbageClaim(
        int $id,
        string $bucket,
        string $tier,
        string $path,
        string $ownerId,
        int $generation,
        \Closure $currentLeaseUntil,
        \Closure $stopRenewal,
        \OpsFour\S3Server\Storage\AwsS3GarbageDeletion $deletion,
    ): bool {
        if ($ownerId === '' || ! $deletion->matches($bucket, $tier, $path, $ownerId)) {
            return false;
        }
        return $this->transaction(function () use ($id, $bucket, $tier, $path, $ownerId, $generation, $currentLeaseUntil, $stopRenewal, $deletion): bool {
            // Every supported publication and reference mutation takes this
            // owner lock before writing metadata. Retain it until provider
            // absence and the final queue acknowledgement are durable.
            $this->lockOwnerForUpdate($ownerId);
            if (! $this->canDeleteAwsGarbageClaim($id, $bucket, $tier, $path, $ownerId, $generation)) {
                return false;
            }
            $deletion->execute();
            // Provider I/O yields to the heartbeat. Stop it before reading the
            // final deadline so no renewal can invalidate the acknowledgement.
            $stopRenewal();
            $leaseUntil = $currentLeaseUntil();
            if (! $this->canDeleteAwsGarbageClaim($id, $bucket, $tier, $path, $ownerId, $generation)) {
                return false;
            }

            return $this->completeStorageGarbageLease($id, $leaseUntil, $generation);
        });
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "UPDATE s3_storage_garbage SET next_attempt_at = $1
             WHERE id = $2 AND status = 'processing' AND next_attempt_at = $3 AND lease_generation = $4
               AND next_attempt_at > EXTRACT(EPOCH FROM clock_timestamp()) AND $1 > next_attempt_at",
            [$newLeaseExpiresAt, $id, $expectedLeaseExpiresAt, $leaseGeneration],
        )->getRowCount() ?? 0) === 1;
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        $sql
            = "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = $1, next_attempt_at = $2
             WHERE id = $3";
        $params = [$error, $nextAttemptAt, $id];
        if ($expectedLeaseExpiresAt !== null) {
            $sql .= " AND status = 'processing' AND next_attempt_at = $4";
            $params[] = $expectedLeaseExpiresAt;
        }

        return ($this->conn()->execute($sql, $params)->getRowCount() ?? 0) > 0;
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return ($this->conn()->execute(
            "UPDATE s3_storage_garbage
             SET status = 'pending', attempts = attempts + 1, last_error = $1, next_attempt_at = $2
             WHERE id = $3 AND status = 'processing' AND next_attempt_at = $4 AND lease_generation = $5",
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
            'INSERT INTO s3_owner_write_locks (owner_id) VALUES ($1) ON CONFLICT (owner_id) DO NOTHING',
            [$ownerId],
        );
        $transaction->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = $1 FOR UPDATE',
            [$ownerId],
        )->fetchRow();
    }

    public function prepareOwnerReadLock(string $ownerId): void
    {
        if ($this->fiberTransaction() !== null) {
            throw new \LogicException('prepareOwnerReadLock() must be called outside an active transaction.');
        }

        $row = $this->pool->execute(
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = $1',
            [$ownerId],
        )->fetchRow();
        if ($row !== null) {
            return;
        }

        $this->pool->execute(
            'INSERT INTO s3_owner_write_locks (owner_id) VALUES ($1) ON CONFLICT (owner_id) DO NOTHING',
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
            'SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = $1 FOR SHARE',
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

    private function conn(): PostgresLink
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

    private function fiberTransaction(): ?PostgresTransaction
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

    /** @param array<string, mixed> $row */
    private function rowToBucketInfo(array $row): BucketInfo
    {
        return new BucketInfo(
            name: self::str($row['name']),
            ownerId: self::str($row['owner_id']),
            region: self::str($row['region']),
            creationDate: new \DateTimeImmutable($this->formatTimestamp($row['created_at'])),
            generation: self::str($row['generation']),
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
        if (isset($row['bucket_generation'])) {
            $systemMetadata['bucketGeneration'] = self::str($row['bucket_generation']);
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
        if (isset($row['created_at'])) {
            $systemMetadata['createdAt'] = $this->formatTimestamp($row['created_at']);
        }
        if (isset($row['noncurrent_at'])) {
            $systemMetadata['noncurrentAt'] = $this->formatTimestamp($row['noncurrent_at']);
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
            restoredStorageTier: isset($row['restored_storage_tier']) ? self::str($row['restored_storage_tier']) : null,
            partLayout: $this->rowToObjectPartLayoutSummary($row),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowToObjectPartLayoutSummary(array $row): ?ObjectPartLayoutSummary
    {
        if ((bool) ($row['is_delete_marker'] ?? false)) {
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

        return new ObjectPartLayoutSummary(self::str($row['part_layout_kind']), self::int($row['part_layout_count']), $row['part_layout_compat_count'] === null ? null : self::int($row['part_layout_compat_count']), self::str($row['part_layout_digest']));
    }

    /**
     * @param  array<string, mixed>  $row
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
            'origin' => self::str($row['origin']),
            'lifecycleRuleId' => $row['lifecycle_rule_id'] !== null ? self::str($row['lifecycle_rule_id']) : null,
            'expectedIsLatest' => $row['expected_is_latest'] !== null ? (bool) self::int($row['expected_is_latest']) : null,
            'targetStoragePath' => $row['target_storage_path'] !== null ? self::str($row['target_storage_path']) : null,
            'status' => self::str($row['status']),
            'attempts' => self::int($row['attempts']),
            'maxAttempts' => self::int($row['max_attempts']),
            'nextAttemptAt' => self::float($row['next_attempt_at']),
            'leaseGeneration' => self::int($row['lease_generation']),
            'lastError' => $row['last_error'] !== null ? self::str($row['last_error']) : null,
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
            'nextAttemptAt' => self::float($row['next_attempt_at']),
            'leaseGeneration' => self::int($row['lease_generation']),
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
            $timestamp = \DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value) && $value !== '') {
            try {
                $timestamp = new \DateTimeImmutable($value);
            } catch (\Exception $error) {
                throw new \UnexpectedValueException('PostgreSQL returned an invalid timestamp.', 0, $error);
            }
        } else {
            throw new \UnexpectedValueException('PostgreSQL returned an invalid timestamp.');
        }

        return $timestamp
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /** Preserve sub-second precision for keyset pagination comparisons. */
    private function listingTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
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

    /** @param list<mixed>|bool|int|float|string|null $value */
    private static function float(mixed $value): float
    {
        if (is_array($value)) {
            return 0.0;
        }

        return (float) $value;
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

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        $row = $this->conn()->execute('SELECT object_ownership FROM s3_bucket_ownership_controls WHERE bucket = $1', [$bucket])->fetchRow();

        return $row === null ? null : self::str($row['object_ownership']);
    }

    public function putBucketOwnershipControls(string $bucket, string $ownership): void
    {
        $this->conn()->execute(
            'INSERT INTO s3_bucket_ownership_controls (bucket, object_ownership) VALUES ($1, $2) ON CONFLICT(bucket) DO UPDATE SET object_ownership = EXCLUDED.object_ownership',
            [$bucket, $ownership],
        );
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $this->conn()->execute('DELETE FROM s3_bucket_ownership_controls WHERE bucket = $1', [$bucket]);
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
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $sql .= " AND key_name COLLATE \"C\" > \${$paramIndex}";
            $params[] = $afterKey;
            $paramIndex++;
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 's3_objects', $tags);

        $sql .= " ORDER BY key_name COLLATE \"C\" ASC LIMIT \${$paramIndex}";
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

        $sql = 'SELECT * FROM s3_objects WHERE bucket = $1 AND is_latest = FALSE AND is_delete_marker = FALSE AND noncurrent_at < $2';
        $params = [$bucket, $cutoff];
        $paramIndex = 3;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $versionParam = $paramIndex++;
            $sql .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$sameKeyParam} AND version_id COLLATE \"C\" > \${$versionParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 's3_objects', $tags);

        $sql .= " ORDER BY key_name COLLATE \"C\" ASC, version_id COLLATE \"C\" ASC LIMIT \${$paramIndex}";
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
            ->format('Y-m-d\TH:i:s\Z');

        $sql = 'SELECT upload_id, bucket, key_name FROM s3_multipart_uploads WHERE bucket = $1 AND created_at < $2';
        $params = [$bucket, $cutoff];
        $paramIndex = 3;

        if ($prefix !== null && $prefix !== '') {
            $sql .= " AND key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";
            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $uploadParam = $paramIndex++;
            $sql .= " AND (key_name COLLATE \"C\" > \${$keyParam} OR (key_name COLLATE \"C\" = \${$sameKeyParam} AND upload_id COLLATE \"C\" > \${$uploadParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterUploadId ?? '';
        }

        $sql .= " ORDER BY key_name COLLATE \"C\" ASC, upload_id COLLATE \"C\" ASC LIMIT \${$paramIndex}";
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
            $sql .= " AND dm.key_name COLLATE \"C\" LIKE \${$paramIndex} ESCAPE '\\'";

            $params[] = $this->escapeLikePattern($prefix) . '%';
            $paramIndex++;
        }

        if ($afterKey !== null) {
            $keyParam = $paramIndex++;
            $sameKeyParam = $paramIndex++;
            $versionParam = $paramIndex++;
            $sql .= " AND (dm.key_name COLLATE \"C\" > \${$keyParam} OR (dm.key_name COLLATE \"C\" = \${$sameKeyParam} AND dm.version_id COLLATE \"C\" > \${$versionParam}))";
            $params[] = $afterKey;
            $params[] = $afterKey;
            $params[] = $afterVersionId ?? '';
        }

        $sql = $this->appendLifecycleTagFilters($sql, $params, $paramIndex, 'dm', $tags);

        $sql .= " ORDER BY dm.key_name COLLATE \"C\" ASC, dm.version_id COLLATE \"C\" ASC LIMIT \${$paramIndex}";
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
             WHERE o.restore_status = $1 AND o.restored_storage_path IS NOT NULL AND o.restore_expires_at IS NOT NULL AND o.restore_expires_at <= $2
             ORDER BY o.restore_expires_at ASC, o.bucket ASC, o.key_name ASC, o.version_id ASC
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
                  AND lt.version_id = {$objectAlias}.version_id
                  AND lt.tag_key = \${$keyParam}
                  AND lt.tag_value = \${$valueParam}
            )";
            $params[] = $key;
            $params[] = $value;
        }

        return $sql;
    }
}
