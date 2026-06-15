<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * TTL cache decorator for MetadataStore.
 *
 * Caches read-only bucket-level configuration that changes rarely
 * (owner, policy, CORS, PAB, encryption). Object-level data, listings,
 * and multipart operations are NOT cached.
 *
 * Write operations invalidate the relevant cache entries immediately.
 * The cache is per-process — multi-server deployments see up to TTL
 * seconds of staleness for cross-server changes.
 */
final class CachedMetadataStoreDecorator implements MetadataStore
{
    /** @var array<string, array{value: mixed, expires: float}> */
    private array $cache = [];

    private int $cacheSize = 0;

    private const int MAX_CACHE_ENTRIES = 10_000;

    public function __construct(
        private readonly MetadataStore $inner,
        private readonly float $ttlSeconds = 5.0,
    ) {}

    public function innerStore(): MetadataStore
    {
        return $this->inner;
    }

    // ---------------------------------------------------------------
    // Cached reads
    // ---------------------------------------------------------------

    public function getBucketOwner(string $bucket): ?string
    {
        return $this->cached("owner:{$bucket}", fn() => $this->inner->getBucketOwner($bucket));
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->inner->bucketExists($bucket);
    }

    public function getBucketPolicy(string $bucket): ?string
    {
        return $this->cached("policy:{$bucket}", fn() => $this->inner->getBucketPolicy($bucket));
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        return $this->cached("account-policy:{$ownerId}", fn() => $this->inner->getAccountPolicy($ownerId));
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        return $this->cached("named-policy:{$policyName}", fn() => $this->inner->getNamedPolicy($policyName));
    }

    public function getBucketCors(string $bucket): array
    {
        return $this->cached("cors:{$bucket}", fn() => $this->inner->getBucketCors($bucket));
    }

    public function getPublicAccessBlock(string $bucket): ?array
    {
        return $this->cached("pab:{$bucket}", fn() => $this->inner->getPublicAccessBlock($bucket));
    }

    public function getBucketEncryption(string $bucket): ?array
    {
        return $this->cached("enc:{$bucket}", fn() => $this->inner->getBucketEncryption($bucket));
    }

    // ---------------------------------------------------------------
    // Write-through with invalidation
    // ---------------------------------------------------------------

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $this->inner->createBucket($ownerId, $bucket, $region);
        $this->invalidateBucket($bucket);
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $this->inner->deleteBucket($ownerId, $bucket);
        $this->invalidateBucket($bucket);
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->inner->putBucketPolicy($bucket, $policyJson);
        $this->invalidate("policy:{$bucket}");
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->inner->deleteBucketPolicy($bucket);
        $this->invalidate("policy:{$bucket}");
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $this->inner->putBucketCors($bucket, $rules);
        $this->invalidate("cors:{$bucket}");
    }

    public function deleteBucketCors(string $bucket): void
    {
        $this->inner->deleteBucketCors($bucket);
        $this->invalidate("cors:{$bucket}");
    }

    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void
    {
        $this->inner->putPublicAccessBlock($bucket, $blockPublicAcls, $ignorePublicAcls, $blockPublicPolicy, $restrictPublicBuckets);
        $this->invalidate("pab:{$bucket}");
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->inner->deletePublicAccessBlock($bucket);
        $this->invalidate("pab:{$bucket}");
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->inner->putBucketEncryption($bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled);
        $this->invalidate("enc:{$bucket}");
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->inner->deleteBucketEncryption($bucket);
        $this->invalidate("enc:{$bucket}");
    }

    // ---------------------------------------------------------------
    // Pure delegation (not cached)
    // ---------------------------------------------------------------

    public function initialize(): void
    {
        $this->inner->initialize();
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        return $this->inner->getBucket($bucket);
    }

    public function getBucketStats(string $bucket): array
    {
        return $this->inner->getBucketStats($bucket);
    }

    public function getBucketStorageStats(string $bucket): array
    {
        return $this->inner->getBucketStorageStats($bucket);
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        return $this->inner->getAccountQuota($ownerId);
    }

    public function listAccountQuotas(): array
    {
        return $this->inner->listAccountQuotas();
    }

    public function putAccountQuota(string $ownerId, QuotaConfig $quota): void
    {
        $this->inner->putAccountQuota($ownerId, $quota);
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->inner->deleteAccountQuota($ownerId);
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        $this->inner->putAccountPolicy($ownerId, $policyJson);
        $this->invalidate("account-policy:{$ownerId}");
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->inner->deleteAccountPolicy($ownerId);
        $this->invalidate("account-policy:{$ownerId}");
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->inner->putNamedPolicy($policyName, $policyJson);
        $this->invalidate("named-policy:{$policyName}");
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->inner->deleteNamedPolicy($policyName);
        $this->invalidate("named-policy:{$policyName}");
    }

    public function listBuckets(string $ownerId): array
    {
        return $this->inner->listBuckets($ownerId);
    }

    public function listAllBuckets(): array
    {
        return $this->inner->listAllBuckets();
    }

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
        $this->inner->putObjectMetadata($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256);
    }

    public function getObjectMetadata(string $bucket, string $key): ?ObjectInfo
    {
        return $this->inner->getObjectMetadata($bucket, $key);
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->inner->deleteObjectMetadata($bucket, $key);
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return $this->inner->objectExists($bucket, $key);
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
        $this->inner->updateObjectPlacement($bucket, $key, $versionId, $storageClass, $storageTier, $storagePath, $transitionStatus, $transitionTargetTier, $transitionError);
    }

    public function updateObjectRestoreState(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
    ): void {
        $this->inner->updateObjectRestoreState($bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt);
    }

    public function countObjects(string $bucket): int
    {
        return $this->inner->countObjects($bucket);
    }

    public function listObjects(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $startAfter = null,
        ?string $continuationToken = null,
    ): ListObjectsResult {
        return $this->inner->listObjects($bucket, $prefix, $delimiter, $maxKeys, $startAfter, $continuationToken);
    }

    public function createMultipartUpload(string $uploadId, string $bucket, string $key, string $ownerId, ?string $contentType = null, array $userMetadata = []): void
    {
        $this->inner->createMultipartUpload($uploadId, $bucket, $key, $ownerId, $contentType, $userMetadata);
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        return $this->inner->getMultipartUpload($uploadId);
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->inner->deleteMultipartUpload($uploadId);
    }

    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        return $this->inner->listMultipartUploads($bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker);
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->inner->putPart($uploadId, $partNumber, $etag, $size, $storagePath);
    }

    public function getParts(string $uploadId): array
    {
        return $this->inner->getParts($uploadId);
    }

    public function deleteParts(string $uploadId): void
    {
        $this->inner->deleteParts($uploadId);
    }

    public function getBucketVersioning(string $bucket): string
    {
        return $this->cached("ver:{$bucket}", fn() => $this->inner->getBucketVersioning($bucket));
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        $this->inner->setBucketVersioning($bucket, $status);
        $this->invalidate("ver:{$bucket}");
    }

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
        return $this->inner->putObjectVersioned($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256);
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        return $this->inner->deleteObjectVersioned($bucket, $key, $ownerId, $suspended);
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        return $this->inner->getObjectMetadataByVersion($bucket, $key, $versionId);
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        return $this->inner->deleteObjectVersion($bucket, $key, $versionId);
    }

    public function listObjectVersions(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $keyMarker = null,
        ?string $versionIdMarker = null,
    ): array {
        return $this->inner->listObjectVersions($bucket, $prefix, $delimiter, $maxKeys, $keyMarker, $versionIdMarker);
    }

    public function getObjectLockConfig(string $bucket): ?array
    {
        return $this->inner->getObjectLockConfig($bucket);
    }

    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $this->inner->putObjectLockConfig($bucket, $config);
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        return $this->inner->getObjectRetention($bucket, $key, $versionId);
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $this->inner->putObjectRetention($bucket, $key, $mode, $retainUntilDate, $versionId);
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        return $this->inner->getObjectLegalHold($bucket, $key, $versionId);
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $this->inner->putObjectLegalHold($bucket, $key, $status, $versionId);
    }

    public function getAcl(string $resourceType, string $resourceName): array
    {
        return $this->inner->getAcl($resourceType, $resourceName);
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $this->inner->putAcl($resourceType, $resourceName, $ownerId, $grants);
    }

    public function getBucketTagging(string $bucket): array
    {
        return $this->inner->getBucketTagging($bucket);
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $this->inner->putBucketTagging($bucket, $tags);
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $this->inner->deleteBucketTagging($bucket);
    }

    public function getObjectTagging(string $bucket, string $key): array
    {
        return $this->inner->getObjectTagging($bucket, $key);
    }

    public function putObjectTagging(string $bucket, string $key, array $tags): void
    {
        $this->inner->putObjectTagging($bucket, $key, $tags);
    }

    public function deleteObjectTagging(string $bucket, string $key): void
    {
        $this->inner->deleteObjectTagging($bucket, $key);
    }

    public function getBucketLifecycle(string $bucket): array
    {
        return $this->inner->getBucketLifecycle($bucket);
    }

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $this->inner->putBucketLifecycle($bucket, $rules);
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $this->inner->deleteBucketLifecycle($bucket);
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
        return $this->inner->enqueueTierTransitionJob(
            $bucket,
            $key,
            $versionId,
            $sourceTier,
            $targetTier,
            $targetStorageClass,
            $sourceStoragePath,
            $maxAttempts,
        );
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        return $this->inner->dequeueTierTransitionJobs($limit);
    }

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
    ): void {
        $this->inner->updateTierTransitionJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath);
    }

    public function getTierTransitionJob(int $id): ?array
    {
        return $this->inner->getTierTransitionJob($id);
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
        return $this->inner->enqueueRestoreJob($bucket, $key, $versionId, $sourceTier, $sourceStoragePath, $restoreDays, $maxAttempts);
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        return $this->inner->dequeueRestoreJobs($limit);
    }

    public function updateRestoreJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
    ): void {
        $this->inner->updateRestoreJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath);
    }

    public function getRestoreJob(int $id): ?array
    {
        return $this->inner->getRestoreJob($id);
    }

    public function getBucketNotification(string $bucket): array
    {
        return $this->inner->getBucketNotification($bucket);
    }

    public function putBucketNotification(string $bucket, array $configs): void
    {
        $this->inner->putBucketNotification($bucket, $configs);
    }

    public function getBucketLogging(string $bucket): ?array
    {
        return $this->inner->getBucketLogging($bucket);
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->inner->putBucketLogging($bucket, $targetBucket, $targetPrefix);
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->inner->deleteBucketLogging($bucket);
    }

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        return $this->inner->acquireLock($lockName, $ownerId, $ttlSeconds);
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->inner->releaseLock($lockName, $ownerId);
    }

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        return $this->inner->getLifecycleCheckpoint($bucket, $ruleId, $action);
    }

    public function putLifecycleCheckpoint(
        string $bucket,
        string $ruleId,
        string $action,
        ?string $cursorKey,
        ?string $cursorVersionId = null,
        ?string $cursorUploadId = null,
    ): void {
        $this->inner->putLifecycleCheckpoint($bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId);
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->inner->deleteLifecycleCheckpoint($bucket, $ruleId, $action);
    }

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        return $this->inner->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $afterKey);
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->inner->listExpiredNoncurrentVersions($bucket, $prefix, $noncurrentDays, $limit, $tags, $afterKey, $afterVersionId);
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array
    {
        return $this->inner->listExpiredMultipartUploads($bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId);
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->inner->listOrphanedDeleteMarkers($bucket, $prefix, $limit, $tags, $afterKey, $afterVersionId);
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        return $this->inner->listExpiredRestoredObjects($now, $limit);
    }

    public function getBucketWebsite(string $bucket): ?array
    {
        return $this->inner->getBucketWebsite($bucket);
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->inner->putBucketWebsite($bucket, $indexDocument, $errorDocument, $redirectAllHost, $redirectAllProtocol, $routingRules);
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $this->inner->deleteBucketWebsite($bucket);
    }

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        return $this->inner->rateLimitCheck($ip, $maxTokens, $refillRate);
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->inner->rateLimitCleanup($maxAgeSeconds);
    }

    public function enqueueNotification(
        string $bucket,
        string $key,
        string $eventName,
        string $destinationUrl,
        string $payloadJson,
        int $maxAttempts = 10,
    ): void {
        $this->inner->enqueueNotification($bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts);
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->inner->dequeueNotifications($limit);
    }

    public function getNotificationQueueStats(): array
    {
        return $this->inner->getNotificationQueueStats();
    }

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
    ): void {
        $this->inner->updateNotificationStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->inner->cleanupOldNotifications($maxAgeSeconds);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->inner->transaction($callback);
    }

    // ---------------------------------------------------------------
    // Cache internals
    // ---------------------------------------------------------------

    /**
     * @template T
     * @param  string  $key  Cache key.
     * @param  callable(): T  $loader  Callback to load on cache miss.
     * @return T
     */
    private function cached(string $key, callable $loader): mixed
    {
        $now = microtime(true);

        if (isset($this->cache[$key]) && $this->cache[$key]['expires'] > $now) {
            return $this->cache[$key]['value'];
        }

        $value = $loader();

        // Enforce hard cap to prevent memory exhaustion.
        if ($this->cacheSize >= self::MAX_CACHE_ENTRIES && !isset($this->cache[$key])) {
            $this->evictExpired($now);
        }

        if (!isset($this->cache[$key])) {
            $this->cacheSize++;
        }

        $this->cache[$key] = [
            'value' => $value,
            'expires' => $now + $this->ttlSeconds,
        ];

        return $value;
    }

    private function invalidate(string $key): void
    {
        if (isset($this->cache[$key])) {
            unset($this->cache[$key]);
            $this->cacheSize--;
        }
    }

    private function invalidateBucket(string $bucket): void
    {
        $prefixes = ["owner:{$bucket}", "policy:{$bucket}", "cors:{$bucket}", "pab:{$bucket}", "enc:{$bucket}", "ver:{$bucket}"];

        foreach ($prefixes as $key) {
            $this->invalidate($key);
        }
    }

    private function evictExpired(float $now): void
    {
        foreach ($this->cache as $key => $entry) {
            if ($entry['expires'] <= $now) {
                unset($this->cache[$key]);
                $this->cacheSize--;
            }
        }
    }
}
