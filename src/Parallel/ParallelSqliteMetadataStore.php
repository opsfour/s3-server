<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;

/**
 * Async MetadataStore decorator that offloads all SQLite PDO calls to
 * worker processes via amphp/parallel.
 *
 * Every public method delegates to a worker, so the event loop is never
 * blocked by synchronous PDO calls. Workers maintain a static
 * SqliteMetadataStore instance (cached per database path).
 *
 * Transaction affinity: beginTransaction/commit/rollback pin the
 * calling Fiber to a dedicated worker via WeakMap<Fiber, Worker>.
 * A TransactionGuard ensures abandoned fibers trigger a rollback.
 */
final class ParallelSqliteMetadataStore implements MetadataStore
{
    private readonly WorkerPool $pool;

    /** @var \WeakMap<\Fiber<mixed, mixed, mixed, mixed>, array{worker: Worker, guard: TransactionGuard}> Pins fibers to workers for transaction affinity. */
    private \WeakMap $fiberWorkerMap;

    public function __construct(
        private readonly string $databasePath,
        int $workerLimit = 8,
        private readonly ?MetricsCollector $metrics = null,
        private readonly string $poolName = 'sqlite_metadata',
    ) {
        $this->pool = new ContextWorkerPool($workerLimit);
        $this->metrics?->registerWorkerPool($this->poolName, $workerLimit);
        $this->fiberWorkerMap = new \WeakMap();
    }

    // ---------------------------------------------------------------
    // Internal dispatch
    // ---------------------------------------------------------------

    /** @param list<mixed> $args */
    private function submit(string $method, array $args = []): mixed
    {
        $task = new SqliteMetadataTask($this->databasePath, $method, $args);
        $worker = $this->getPinnedWorker() ?? $this->pool;

        try {
            $result = $worker->submit($task)->await();
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method);

            return $result;
        } catch (TaskFailureThrowable $e) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method, false, $e->getOriginalClassName());
            $this->rethrowS3Exception($e);
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method, false, $e::class);
            throw $e;
        }
    }

    private function getPinnedWorker(): ?Worker
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return null;
        }

        $entry = $this->fiberWorkerMap[$fiber] ?? null;

        return $entry !== null ? $entry['worker'] : null;
    }

    /**
     * Re-throw S3 exceptions that crossed the worker boundary.
     *
     * amphp/parallel wraps worker exceptions in TaskFailureException/TaskFailureError
     * with the original class name preserved. We instantiate the original S3Exception
     * so handlers get the correct HTTP status code and error XML.
     *
     * @throws \Throwable Always throws.
     * @return never
     */
    private function rethrowS3Exception(TaskFailureThrowable $e): never
    {
        $className = $e->getOriginalClassName();

        if (is_subclass_of($className, \Throwable::class) && class_exists($className)) {
            try {
                $refl = new \ReflectionClass($className);
                $ctor = $refl->getConstructor();

                if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
                    $original = $refl->newInstance($e->getOriginalMessage());
                } else {
                    $original = $refl->newInstance($e->getOriginalMessage());
                }

                throw $original;
            } catch (TaskFailureThrowable) {
                // Fall through to re-throw the wrapper.
            } catch (\Throwable $rethrown) {
                throw $rethrown;
            }
        }

        throw $e;
    }

    // ---------------------------------------------------------------
    // Initialization
    // ---------------------------------------------------------------

    public function initialize(): void
    {
        $this->submit('initialize');
    }

    // ---------------------------------------------------------------
    // Bucket operations
    // ---------------------------------------------------------------

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $this->submit('createBucket', [$ownerId, $bucket, $region]);
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $this->submit('deleteBucket', [$ownerId, $bucket]);
    }

    public function getBucketStats(string $bucket): array
    {
        return $this->submit('getBucketStats', [$bucket]);
    }

    public function getBucketStorageStats(string $bucket): array
    {
        return $this->submit('getBucketStorageStats', [$bucket]);
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        return $this->submit('getAccountQuota', [$ownerId]);
    }

    public function listAccountQuotas(): array
    {
        return $this->submit('listAccountQuotas');
    }

    public function putAccountQuota(string $ownerId, QuotaConfig $quota): void
    {
        $this->submit('putAccountQuota', [$ownerId, $quota]);
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->submit('deleteAccountQuota', [$ownerId]);
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        return $this->submit('getAccountPolicy', [$ownerId]);
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        $this->submit('putAccountPolicy', [$ownerId, $policyJson]);
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->submit('deleteAccountPolicy', [$ownerId]);
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        return $this->submit('getNamedPolicy', [$policyName]);
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->submit('putNamedPolicy', [$policyName, $policyJson]);
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->submit('deleteNamedPolicy', [$policyName]);
    }

    public function getBucket(string $bucket): ?BucketInfo
    {
        return $this->submit('getBucket', [$bucket]);
    }

    public function listBuckets(string $ownerId): array
    {
        return $this->submit('listBuckets', [$ownerId]);
    }

    public function listAllBuckets(): array
    {
        return $this->submit('listAllBuckets');
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->submit('bucketExists', [$bucket]);
    }

    public function getBucketOwner(string $bucket): ?string
    {
        return $this->submit('getBucketOwner', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Object operations
    // ---------------------------------------------------------------

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
        $this->submit('putObjectMetadata', [
            $bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath,
            $storageClass, $contentEncoding, $contentDisposition, $cacheControl,
            $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256,
        ]);
    }

    public function getObjectMetadata(string $bucket, string $key): ?ObjectInfo
    {
        return $this->submit('getObjectMetadata', [$bucket, $key]);
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->submit('deleteObjectMetadata', [$bucket, $key]);
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return $this->submit('objectExists', [$bucket, $key]);
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
        $this->submit('updateObjectPlacement', [$bucket, $key, $versionId, $storageClass, $storageTier, $storagePath, $transitionStatus, $transitionTargetTier, $transitionError]);
    }

    public function updateObjectRestoreState(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
    ): void {
        $this->submit('updateObjectRestoreState', [$bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt]);
    }

    public function countObjects(string $bucket): int
    {
        return $this->submit('countObjects', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Listing
    // ---------------------------------------------------------------

    public function listObjects(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $startAfter = null,
        ?string $continuationToken = null,
    ): ListObjectsResult {
        return $this->submit('listObjects', [
            $bucket, $prefix, $delimiter, $maxKeys, $startAfter, $continuationToken,
        ]);
    }

    // ---------------------------------------------------------------
    // Multipart upload operations
    // ---------------------------------------------------------------

    public function createMultipartUpload(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void {
        $this->submit('createMultipartUpload', [
            $uploadId, $bucket, $key, $ownerId, $contentType, $userMetadata,
        ]);
    }

    public function getMultipartUpload(string $uploadId): ?array
    {
        return $this->submit('getMultipartUpload', [$uploadId]);
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->submit('deleteMultipartUpload', [$uploadId]);
    }

    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        return $this->submit('listMultipartUploads', [
            $bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker,
        ]);
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->submit('putPart', [$uploadId, $partNumber, $etag, $size, $storagePath]);
    }

    public function getParts(string $uploadId): array
    {
        return $this->submit('getParts', [$uploadId]);
    }

    public function deleteParts(string $uploadId): void
    {
        $this->submit('deleteParts', [$uploadId]);
    }

    // ---------------------------------------------------------------
    // Versioning
    // ---------------------------------------------------------------

    public function getBucketVersioning(string $bucket): string
    {
        return $this->submit('getBucketVersioning', [$bucket]);
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        $this->submit('setBucketVersioning', [$bucket, $status]);
    }

    // ---------------------------------------------------------------
    // Versioning-aware object operations
    // ---------------------------------------------------------------

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
        return $this->submit('putObjectVersioned', [
            $bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath,
            $storageClass, $contentEncoding, $contentDisposition, $cacheControl,
            $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256,
        ]);
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        return $this->submit('deleteObjectVersioned', [$bucket, $key, $ownerId, $suspended]);
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        return $this->submit('getObjectMetadataByVersion', [$bucket, $key, $versionId]);
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?ObjectInfo
    {
        return $this->submit('deleteObjectVersion', [$bucket, $key, $versionId]);
    }

    public function listObjectVersions(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $keyMarker = null,
        ?string $versionIdMarker = null,
    ): array {
        return $this->submit('listObjectVersions', [
            $bucket, $prefix, $delimiter, $maxKeys, $keyMarker, $versionIdMarker,
        ]);
    }

    // ---------------------------------------------------------------
    // Object Lock, Retention, Legal Hold
    // ---------------------------------------------------------------

    public function getObjectLockConfig(string $bucket): ?array
    {
        return $this->submit('getObjectLockConfig', [$bucket]);
    }

    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $this->submit('putObjectLockConfig', [$bucket, $config]);
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        return $this->submit('getObjectRetention', [$bucket, $key, $versionId]);
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $this->submit('putObjectRetention', [$bucket, $key, $mode, $retainUntilDate, $versionId]);
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        return $this->submit('getObjectLegalHold', [$bucket, $key, $versionId]);
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $this->submit('putObjectLegalHold', [$bucket, $key, $status, $versionId]);
    }

    // ---------------------------------------------------------------
    // ACLs
    // ---------------------------------------------------------------

    public function getAcl(string $resourceType, string $resourceName): array
    {
        return $this->submit('getAcl', [$resourceType, $resourceName]);
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $this->submit('putAcl', [$resourceType, $resourceName, $ownerId, $grants]);
    }

    // ---------------------------------------------------------------
    // Tagging
    // ---------------------------------------------------------------

    public function getBucketTagging(string $bucket): array
    {
        return $this->submit('getBucketTagging', [$bucket]);
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $this->submit('putBucketTagging', [$bucket, $tags]);
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $this->submit('deleteBucketTagging', [$bucket]);
    }

    public function getObjectTagging(string $bucket, string $key): array
    {
        return $this->submit('getObjectTagging', [$bucket, $key]);
    }

    public function putObjectTagging(string $bucket, string $key, array $tags): void
    {
        $this->submit('putObjectTagging', [$bucket, $key, $tags]);
    }

    public function deleteObjectTagging(string $bucket, string $key): void
    {
        $this->submit('deleteObjectTagging', [$bucket, $key]);
    }

    // ---------------------------------------------------------------
    // Bucket Policies
    // ---------------------------------------------------------------

    public function getBucketPolicy(string $bucket): ?string
    {
        return $this->submit('getBucketPolicy', [$bucket]);
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->submit('putBucketPolicy', [$bucket, $policyJson]);
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->submit('deleteBucketPolicy', [$bucket]);
    }

    // ---------------------------------------------------------------
    // CORS
    // ---------------------------------------------------------------

    public function getBucketCors(string $bucket): array
    {
        return $this->submit('getBucketCors', [$bucket]);
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $this->submit('putBucketCors', [$bucket, $rules]);
    }

    public function deleteBucketCors(string $bucket): void
    {
        $this->submit('deleteBucketCors', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Encryption Config
    // ---------------------------------------------------------------

    public function getBucketEncryption(string $bucket): ?array
    {
        return $this->submit('getBucketEncryption', [$bucket]);
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->submit('putBucketEncryption', [$bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled]);
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->submit('deleteBucketEncryption', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Lifecycle Configuration
    // ---------------------------------------------------------------

    public function getBucketLifecycle(string $bucket): array
    {
        return $this->submit('getBucketLifecycle', [$bucket]);
    }

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $this->submit('putBucketLifecycle', [$bucket, $rules]);
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $this->submit('deleteBucketLifecycle', [$bucket]);
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
        return $this->submit('enqueueTierTransitionJob', [
            $bucket,
            $key,
            $versionId,
            $sourceTier,
            $targetTier,
            $targetStorageClass,
            $sourceStoragePath,
            $maxAttempts,
        ]);
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        return $this->submit('dequeueTierTransitionJobs', [$limit]);
    }

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
    ): void {
        $this->submit('updateTierTransitionJobStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath]);
    }

    public function getTierTransitionJob(int $id): ?array
    {
        return $this->submit('getTierTransitionJob', [$id]);
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
        return $this->submit('enqueueRestoreJob', [$bucket, $key, $versionId, $sourceTier, $sourceStoragePath, $restoreDays, $maxAttempts]);
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        return $this->submit('dequeueRestoreJobs', [$limit]);
    }

    public function updateRestoreJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
    ): void {
        $this->submit('updateRestoreJobStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath]);
    }

    public function getRestoreJob(int $id): ?array
    {
        return $this->submit('getRestoreJob', [$id]);
    }

    // ---------------------------------------------------------------
    // Notification Configuration
    // ---------------------------------------------------------------

    public function getBucketNotification(string $bucket): array
    {
        return $this->submit('getBucketNotification', [$bucket]);
    }

    public function putBucketNotification(string $bucket, array $configs): void
    {
        $this->submit('putBucketNotification', [$bucket, $configs]);
    }

    // ---------------------------------------------------------------
    // Public Access Block
    // ---------------------------------------------------------------

    public function getPublicAccessBlock(string $bucket): ?array
    {
        return $this->submit('getPublicAccessBlock', [$bucket]);
    }

    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void
    {
        $this->submit('putPublicAccessBlock', [$bucket, $blockPublicAcls, $ignorePublicAcls, $blockPublicPolicy, $restrictPublicBuckets]);
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->submit('deletePublicAccessBlock', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Bucket Logging
    // ---------------------------------------------------------------

    public function getBucketLogging(string $bucket): ?array
    {
        return $this->submit('getBucketLogging', [$bucket]);
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->submit('putBucketLogging', [$bucket, $targetBucket, $targetPrefix]);
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->submit('deleteBucketLogging', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Distributed locks
    // ---------------------------------------------------------------

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        return $this->submit('acquireLock', [$lockName, $ownerId, $ttlSeconds]);
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->submit('releaseLock', [$lockName, $ownerId]);
    }

    // ---------------------------------------------------------------
    // Lifecycle checkpoints
    // ---------------------------------------------------------------

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        return $this->submit('getLifecycleCheckpoint', [$bucket, $ruleId, $action]);
    }

    public function putLifecycleCheckpoint(
        string $bucket,
        string $ruleId,
        string $action,
        ?string $cursorKey,
        ?string $cursorVersionId = null,
        ?string $cursorUploadId = null,
    ): void {
        $this->submit('putLifecycleCheckpoint', [$bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId]);
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->submit('deleteLifecycleCheckpoint', [$bucket, $ruleId, $action]);
    }

    // ---------------------------------------------------------------
    // Lifecycle query methods
    // ---------------------------------------------------------------

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        return $this->submit('listExpiredObjects', [$bucket, $prefix, $olderThan, $limit, $tags, $afterKey]);
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->submit('listExpiredNoncurrentVersions', [$bucket, $prefix, $noncurrentDays, $limit, $tags, $afterKey, $afterVersionId]);
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array
    {
        return $this->submit('listExpiredMultipartUploads', [$bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId]);
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->submit('listOrphanedDeleteMarkers', [$bucket, $prefix, $limit, $tags, $afterKey, $afterVersionId]);
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        return $this->submit('listExpiredRestoredObjects', [$now, $limit]);
    }

    // ---------------------------------------------------------------
    // Website Configuration
    // ---------------------------------------------------------------

    public function getBucketWebsite(string $bucket): ?array
    {
        return $this->submit('getBucketWebsite', [$bucket]);
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->submit('putBucketWebsite', [$bucket, $indexDocument, $errorDocument, $redirectAllHost, $redirectAllProtocol, $routingRules]);
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $this->submit('deleteBucketWebsite', [$bucket]);
    }

    // ---------------------------------------------------------------
    // Rate Limiting
    // ---------------------------------------------------------------

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        return $this->submit('rateLimitCheck', [$ip, $maxTokens, $refillRate]);
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->submit('rateLimitCleanup', [$maxAgeSeconds]);
    }

    // ---------------------------------------------------------------
    // Notification Queue
    // ---------------------------------------------------------------

    public function enqueueNotification(
        string $bucket,
        string $key,
        string $eventName,
        string $destinationUrl,
        string $payloadJson,
        int $maxAttempts = 10,
    ): void {
        $this->submit('enqueueNotification', [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts]);
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->submit('dequeueNotifications', [$limit]);
    }

    public function getNotificationQueueStats(): array
    {
        return $this->submit('getNotificationQueueStats', []);
    }

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
    ): void {
        $this->submit('updateNotificationStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts]);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->submit('cleanupOldNotifications', [$maxAgeSeconds]);
    }

    // ---------------------------------------------------------------
    // Transaction support
    // ---------------------------------------------------------------

    public function beginTransaction(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new \LogicException(
                'ParallelSqliteMetadataStore::beginTransaction() must be called from within a Fiber. '
                . 'Transaction pinning requires a Fiber to track worker affinity.',
            );
        }
        /** @var \Fiber<mixed, mixed, mixed, mixed> $fiber */

        if (isset($this->fiberWorkerMap[$fiber])) {
            // Already in a transaction on this fiber — no-op (nested call guard).
            return;
        }

        $worker = $this->pool->getWorker();
        $guard = new TransactionGuard($worker, $this->databasePath);
        $this->fiberWorkerMap[$fiber] = ['worker' => $worker, 'guard' => $guard];

        $this->submit('beginTransaction');
    }

    public function commit(): void
    {
        $this->submit('commit');
        $this->unpinFiber();
    }

    public function rollback(): void
    {
        $this->submit('rollback');
        $this->unpinFiber();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------

    public function shutdown(): void
    {
        try {
            $this->pool->shutdown();
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolShutdownFailure($this->poolName, $e::class);
            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function unpinFiber(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null && isset($this->fiberWorkerMap[$fiber])) {
            $this->fiberWorkerMap[$fiber]['guard']->disarm();
            unset($this->fiberWorkerMap[$fiber]);
        }
    }
}

/**
 * Safety net: if a Fiber holding a transaction is garbage-collected
 * without committing or rolling back, the destructor sends a best-effort
 * rollback to the pinned worker. This prevents permanently blocking
 * SQLite's single-writer WAL lock.
 */
final class TransactionGuard
{
    private bool $armed = true;

    public function __construct(
        private readonly Worker $worker,
        private readonly string $databasePath,
    ) {}

    public function disarm(): void
    {
        $this->armed = false;
    }

    public function __destruct()
    {
        if (!$this->armed) {
            return;
        }

        try {
            $task = new SqliteMetadataTask($this->databasePath, 'rollback', []);
            $this->worker->submit($task)->await();
        } catch (\Throwable) {
            // Best-effort — worker may already be shut down.
        }
    }
}
