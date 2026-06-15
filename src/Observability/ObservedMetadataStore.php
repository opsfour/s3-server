<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

use OpsFour\S3Server\Metadata\MetadataStore;

final readonly class ObservedMetadataStore implements MetadataStore
{
    public function __construct(
        private MetadataStore $inner,
        private MetricsCollector $metrics,
        private string $driver,
        private int $slowThresholdNs = 250_000_000,
    ) {}

    public function innerStore(): MetadataStore
    {
        return $this->inner;
    }

    public function initialize(): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->initialize());
    }

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->createBucket($ownerId, $bucket, $region));
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucket($ownerId, $bucket));
    }

    public function getBucketStats(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketStats($bucket));
    }

    public function getBucketStorageStats(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketStorageStats($bucket));
    }

    public function getAccountQuota(string $ownerId): ?\OpsFour\S3Server\Quota\QuotaConfig
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getAccountQuota($ownerId));
    }

    public function listAccountQuotas(): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listAccountQuotas());
    }

    public function putAccountQuota(string $ownerId, \OpsFour\S3Server\Quota\QuotaConfig $quota): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putAccountQuota($ownerId, $quota));
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteAccountQuota($ownerId));
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getAccountPolicy($ownerId));
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putAccountPolicy($ownerId, $policyJson));
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteAccountPolicy($ownerId));
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getNamedPolicy($policyName));
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putNamedPolicy($policyName, $policyJson));
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteNamedPolicy($policyName));
    }

    public function getBucket(string $bucket): ?\OpsFour\S3Server\Dto\BucketInfo
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucket($bucket));
    }

    public function listBuckets(string $ownerId): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listBuckets($ownerId));
    }

    public function listAllBuckets(): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listAllBuckets());
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->bucketExists($bucket));
    }

    public function getBucketOwner(string $bucket): ?string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketOwner($bucket));
    }

    public function putObjectMetadata(string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath, string $storageClass = 'STANDARD', ?string $contentEncoding = null, ?string $contentDisposition = null, ?string $cacheControl = null, array $userMetadata = [], ?string $checksumCrc32 = null, ?string $checksumCrc32c = null, ?string $checksumSha1 = null, ?string $checksumSha256 = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putObjectMetadata($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256));
    }

    public function getObjectMetadata(string $bucket, string $key): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectMetadata($bucket, $key));
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteObjectMetadata($bucket, $key));
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->objectExists($bucket, $key));
    }

    public function updateObjectPlacement(string $bucket, string $key, ?string $versionId, string $storageClass, string $storageTier, string $storagePath, string $transitionStatus = 'available', ?string $transitionTargetTier = null, ?string $transitionError = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->updateObjectPlacement($bucket, $key, $versionId, $storageClass, $storageTier, $storagePath, $transitionStatus, $transitionTargetTier, $transitionError));
    }

    public function updateObjectRestoreState(string $bucket, string $key, ?string $versionId, ?string $restoreStatus, ?string $restoredStoragePath = null, ?\DateTimeImmutable $restoreExpiresAt = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->updateObjectRestoreState($bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt));
    }

    public function countObjects(string $bucket): int
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->countObjects($bucket));
    }

    public function listObjects(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxKeys = 1000, ?string $startAfter = null, ?string $continuationToken = null): \OpsFour\S3Server\Dto\ListObjectsResult
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listObjects($bucket, $prefix, $delimiter, $maxKeys, $startAfter, $continuationToken));
    }

    public function createMultipartUpload(string $uploadId, string $bucket, string $key, string $ownerId, ?string $contentType = null, array $userMetadata = []): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->createMultipartUpload($uploadId, $bucket, $key, $ownerId, $contentType, $userMetadata));
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getMultipartUpload($uploadId));
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteMultipartUpload($uploadId));
    }

    public function listMultipartUploads(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxUploads = 1000, ?string $keyMarker = null, ?string $uploadIdMarker = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listMultipartUploads($bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker));
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putPart($uploadId, $partNumber, $etag, $size, $storagePath));
    }

    public function getParts(string $uploadId): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getParts($uploadId));
    }

    public function deleteParts(string $uploadId): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteParts($uploadId));
    }

    public function getBucketVersioning(string $bucket): string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketVersioning($bucket));
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->setBucketVersioning($bucket, $status));
    }

    public function putObjectVersioned(string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath, string $storageClass = 'STANDARD', ?string $contentEncoding = null, ?string $contentDisposition = null, ?string $cacheControl = null, array $userMetadata = [], ?string $checksumCrc32 = null, ?string $checksumCrc32c = null, ?string $checksumSha1 = null, ?string $checksumSha256 = null): string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->putObjectVersioned($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256));
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->deleteObjectVersioned($bucket, $key, $ownerId, $suspended));
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectMetadataByVersion($bucket, $key, $versionId));
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->deleteObjectVersion($bucket, $key, $versionId));
    }

    public function listObjectVersions(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxKeys = 1000, ?string $keyMarker = null, ?string $versionIdMarker = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listObjectVersions($bucket, $prefix, $delimiter, $maxKeys, $keyMarker, $versionIdMarker));
    }

    public function getObjectLockConfig(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectLockConfig($bucket));
    }

    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putObjectLockConfig($bucket, $config));
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectRetention($bucket, $key, $versionId));
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putObjectRetention($bucket, $key, $mode, $retainUntilDate, $versionId));
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectLegalHold($bucket, $key, $versionId));
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putObjectLegalHold($bucket, $key, $status, $versionId));
    }

    public function getAcl(string $resourceType, string $resourceName): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getAcl($resourceType, $resourceName));
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putAcl($resourceType, $resourceName, $ownerId, $grants));
    }

    public function getBucketTagging(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketTagging($bucket));
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketTagging($bucket, $tags));
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketTagging($bucket));
    }

    public function getObjectTagging(string $bucket, string $key): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getObjectTagging($bucket, $key));
    }

    public function putObjectTagging(string $bucket, string $key, array $tags): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putObjectTagging($bucket, $key, $tags));
    }

    public function deleteObjectTagging(string $bucket, string $key): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteObjectTagging($bucket, $key));
    }

    public function getBucketPolicy(string $bucket): ?string
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketPolicy($bucket));
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketPolicy($bucket, $policyJson));
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketPolicy($bucket));
    }

    public function getBucketCors(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketCors($bucket));
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketCors($bucket, $rules));
    }

    public function deleteBucketCors(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketCors($bucket));
    }

    public function getBucketEncryption(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketEncryption($bucket));
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketEncryption($bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled));
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketEncryption($bucket));
    }

    public function getBucketLifecycle(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketLifecycle($bucket));
    }

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketLifecycle($bucket, $rules));
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketLifecycle($bucket));
    }

    public function getBucketNotification(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketNotification($bucket));
    }

    public function putBucketNotification(string $bucket, array $configs): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketNotification($bucket, $configs));
    }

    public function getPublicAccessBlock(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getPublicAccessBlock($bucket));
    }

    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putPublicAccessBlock($bucket, $blockPublicAcls, $ignorePublicAcls, $blockPublicPolicy, $restrictPublicBuckets));
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deletePublicAccessBlock($bucket));
    }

    public function getBucketLogging(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketLogging($bucket));
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketLogging($bucket, $targetBucket, $targetPrefix));
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketLogging($bucket));
    }

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->acquireLock($lockName, $ownerId, $ttlSeconds));
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->releaseLock($lockName, $ownerId));
    }

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getLifecycleCheckpoint($bucket, $ruleId, $action));
    }

    public function putLifecycleCheckpoint(string $bucket, string $ruleId, string $action, ?string $cursorKey, ?string $cursorVersionId = null, ?string $cursorUploadId = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putLifecycleCheckpoint($bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId));
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteLifecycleCheckpoint($bucket, $ruleId, $action));
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
        return $this->observe(__FUNCTION__, fn () => $this->inner->enqueueTierTransitionJob(
            $bucket,
            $key,
            $versionId,
            $sourceTier,
            $targetTier,
            $targetStorageClass,
            $sourceStoragePath,
            $maxAttempts,
        ));
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->dequeueTierTransitionJobs($limit));
    }

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
    ): void {
        $this->observe(__FUNCTION__, fn () => $this->inner->updateTierTransitionJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath));
    }

    public function getTierTransitionJob(int $id): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getTierTransitionJob($id));
    }

    public function enqueueRestoreJob(string $bucket, string $key, ?string $versionId, string $sourceTier, string $sourceStoragePath, int $restoreDays, int $maxAttempts = 10): int
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->enqueueRestoreJob($bucket, $key, $versionId, $sourceTier, $sourceStoragePath, $restoreDays, $maxAttempts));
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->dequeueRestoreJobs($limit));
    }

    public function updateRestoreJobStatus(int $id, string $status, ?string $error = null, ?float $nextAttemptAt = null, bool $incrementAttempts = true, ?string $restoredStoragePath = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->updateRestoreJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath));
    }

    public function getRestoreJob(int $id): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getRestoreJob($id));
    }

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $afterKey));
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listExpiredNoncurrentVersions($bucket, $prefix, $noncurrentDays, $limit, $tags, $afterKey, $afterVersionId));
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listExpiredMultipartUploads($bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId));
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listOrphanedDeleteMarkers($bucket, $prefix, $limit, $tags, $afterKey, $afterVersionId));
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->listExpiredRestoredObjects($now, $limit));
    }

    public function getBucketWebsite(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getBucketWebsite($bucket));
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->putBucketWebsite($bucket, $indexDocument, $errorDocument, $redirectAllHost, $redirectAllProtocol, $routingRules));
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->deleteBucketWebsite($bucket));
    }

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->rateLimitCheck($ip, $maxTokens, $refillRate));
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->rateLimitCleanup($maxAgeSeconds));
    }

    public function enqueueNotification(string $bucket, string $key, string $eventName, string $destinationUrl, string $payloadJson, int $maxAttempts = 10): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->enqueueNotification($bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts));
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->dequeueNotifications($limit));
    }

    public function getNotificationQueueStats(): array
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->getNotificationQueueStats());
    }

    public function updateNotificationStatus(int $id, string $status, ?string $error = null, ?float $nextAttemptAt = null, bool $incrementAttempts = true): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->updateNotificationStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts));
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->cleanupOldNotifications($maxAgeSeconds));
    }

    public function beginTransaction(): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->beginTransaction());
    }

    public function commit(): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->commit());
    }

    public function rollback(): void
    {
        $this->observe(__FUNCTION__, fn () => $this->inner->rollback());
    }

    public function transaction(callable $callback): mixed
    {
        return $this->observe(__FUNCTION__, fn () => $this->inner->transaction($callback));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function observe(string $operation, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->metrics->recordBackendOperation(
                backend: 'metadata',
                driver: $this->driver,
                operation: $operation,
                durationNs: hrtime(true) - $startedAt,
                success: false,
                slowThresholdNs: $this->slowThresholdNs,
                exception: $e::class,
            );
            throw $e;
        }

        $this->metrics->recordBackendOperation(
            backend: 'metadata',
            driver: $this->driver,
            operation: $operation,
            durationNs: hrtime(true) - $startedAt,
            slowThresholdNs: $this->slowThresholdNs,
        );

        return $result;
    }
}
