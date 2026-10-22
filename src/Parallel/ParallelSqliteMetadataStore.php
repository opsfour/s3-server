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
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MetadataWriteReadinessProbe;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Metadata\GenerationBoundBucketMultipartCleanupStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectRetentionDeletionStore;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\FencedMultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\StorageGarbageLeaseStore;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartCompletionCapacityReader;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartPartSealGuard;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\PagedMultipartPartsStore;
use OpsFour\S3Server\Metadata\MultipartPartChecksumStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Quota\QuotaConfig;

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
final class ParallelSqliteMetadataStore implements MetadataStore, MetadataWriteReadinessProbe, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartWriteIntentStore, FencedMultipartWriteIntentStore, LocalMultipartCompletionIntentStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, \OpsFour\S3Server\Metadata\LocalMultipartFenceAdmissionStore, \OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore, MultipartUploadTierStore, ObjectWriteIntentStore, PagedMultipartPartsStore, MultipartPartChecksumStore, FencedStorageGarbageStore, StorageGarbageLeaseStore, FencedQueueStore, RestoredCopyTierStore, MultipartCompletionCapacityReader, WriteIntentCapacityReader
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

    public function assertMetadataWritable(): void
    {
        $this->submit('assertMetadataWritable');
    }

    public function assertSupportedBucketEncryptionInventory(): void
    {
        $this->submit('assertSupportedBucketEncryptionInventory');
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

    public function getOwnerStorageStats(string $ownerId): array
    {
        return $this->submit('getOwnerStorageStats', [$ownerId]);
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        return $this->submit('getAccountQuota', [$ownerId]);
    }

    public function listAccountQuotas(): array
    {
        return $this->submit('listAccountQuotas');
    }

    public function listAccountQuotasPage(?string $after, int $limit): array
    {
        return $this->submit('listAccountQuotasPage', [$after, $limit]);
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

    public function lockNamedPoliciesForUpdate(array $policyNames): array
    {
        return $this->submit('lockNamedPoliciesForUpdate', [$policyNames]);
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

    public function countBuckets(string $ownerId): int
    {
        return $this->submit('countBuckets', [$ownerId]);
    }

    public function listBucketsPage(string $ownerId, ?string $after, ?string $prefix, ?string $region, int $maxBuckets): array
    {
        return $this->submit('listBucketsPage', [$ownerId, $after, $prefix, $region, $maxBuckets]);
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

    public function getCurrentObjectVersion(string $bucket, string $key): ?ObjectInfo
    {
        return $this->submit('getCurrentObjectVersion', [$bucket, $key]);
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

    public function updateObjectRestoreStateWithTier(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
        ?string $restoredStorageTier = null,
    ): void {
        $this->submit('updateObjectRestoreStateWithTier', [$bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt, $restoredStorageTier]);
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

    public function createMultipartUploadWithTier(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        string $storageTier,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void {
        $this->submit('createMultipartUploadWithTier', [
            $uploadId, $bucket, $key, $ownerId, $storageTier, $contentType, $userMetadata,
        ]);
    }

    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void
    {
        $this->submit('createMultipartWriteIntent', [$intent]);
    }

    public function multipartWriteIntentNow(): float
    {
        return $this->submit('multipartWriteIntentNow', []);
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        return $this->submit('getMultipartWriteIntent', [$attemptId]);
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        return $this->submit('renewMultipartWriteIntent', [$attemptId, $expectedLeaseUntil, $newLeaseUntil]);
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        return $this->submit('abandonMultipartWriteIntent', [$attemptId, $nextAttemptAt]);
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        return $this->submit('abandonMultipartWriteIntentsForUpload', [$uploadId, $nextAttemptAt]);
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        return $this->submit('consumeMultipartWriteIntent', [$attemptId]);
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        return $this->submit('armMultipartWriteIntent', [$attemptId, $dispatchId, $expectedLeaseUntil, $newLeaseUntil]);
    }

    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool
    {
        return $this->submit('retireLocalMultipartWriteIntentBeforeDispatch', [$attemptId, $dispatchId]);
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        return $this->submit('retireDueUndispatchedLocalMultipartWriteIntents', [$storageTier, $limit]);
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        return $this->submit('consumeMultipartWriteIntentWithDispatch', [$attemptId, $dispatchId]);
    }

    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        return $this->submit('claimDueMultipartWriteIntents', [$limit, $now, $leaseUntil]);
    }

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        return $this->submit('retryMultipartWriteIntent', [$attemptId, $expectedLeaseUntil, $nextAttemptAt]);
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        return $this->submit('renewMultipartCollectorClaim', [$attemptId, $newLeaseUntil, $expectedLeaseUntil, $claimGeneration]);
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        return $this->submit('retryMultipartCollectorClaim', [$attemptId, $nextAttemptAt, $expectedLeaseUntil, $claimGeneration]);
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

    public function listMultipartUploadsForBucket(
        BucketInfo $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        return $this->submit('listMultipartUploadsForBucket', [
            $bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker,
        ]);
    }

    public function getMultipartStorageStats(?string $ownerId, ?string $bucket = null): array
    {
        return $this->submit('getMultipartStorageStats', [$ownerId, $bucket]);
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->submit('putPart', [$uploadId, $partNumber, $etag, $size, $storagePath]);
    }

    public function supportsMultipartPartChecksums(): bool
    {
        return true;
    }

    public function putPartWithChecksum(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath, ?string $checksumAlgorithm, ?string $checksumValue): void
    {
        $this->submit('putPartWithChecksum', [$uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue]);
    }

    public function getParts(string $uploadId): array
    {
        return $this->submit('getParts', [$uploadId]);
    }

    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array
    {
        return $this->submit('listPartsPage', [$uploadId, $afterPartNumber, $limit]);
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

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->submit('deleteObjectRetention', [$bucket, $key, $versionId]);
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

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        return $this->submit('getObjectTagging', [$bucket, $key, $versionId]);
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $this->submit('putObjectTagging', [$bucket, $key, $tags, $versionId]);
    }

    public function deleteObjectTagging(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->submit('deleteObjectTagging', [$bucket, $key, $versionId]);
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

    public function listReferencedStorageTiers(): array
    {
        return $this->submit('listReferencedStorageTiers', []);
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
        string $origin = 'direct',
        ?string $lifecycleRuleId = null,
        ?bool $expectedIsLatest = null,
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
            $origin,
            $lifecycleRuleId,
            $expectedIsLatest,
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
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->submit('updateTierTransitionJobStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $expectedLeaseExpiresAt]);
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->submit('renewTierTransitionJobLease', [$id, $leaseExpiresAt, $expectedLeaseExpiresAt]);
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
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->submit('updateRestoreJobStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $expectedLeaseExpiresAt]);
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->submit('renewRestoreJobLease', [$id, $leaseExpiresAt, $expectedLeaseExpiresAt]);
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

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        return $this->submit('getBucketOwnershipControls', [$bucket]);
    }

    public function putBucketOwnershipControls(string $bucket, string $objectOwnership): void
    {
        $this->submit('putBucketOwnershipControls', [$bucket, $objectOwnership]);
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $this->submit('deleteBucketOwnershipControls', [$bucket]);
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

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null, ?\DateTimeImmutable $createdBefore = null): array
    {
        return $this->submit('listExpiredMultipartUploads', [$bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId, $createdBefore]);
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
    ): int {
        return $this->submit('enqueueNotification', [$bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts]);
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->submit('updateNotificationPayload', [$id, $payloadJson]);
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->submit('dequeueNotifications', [$limit]);
    }

    public function getNotificationQueueStats(): array
    {
        return $this->submit('getNotificationQueueStats', []);
    }

    public function getMultipartCompletionIntentCount(): ?int
    {
        return $this->submit('getMultipartCompletionIntentCount', []);
    }

    public function getWriteIntentCapacityCounts(): ?array
    {
        return $this->submit('getWriteIntentCapacityCounts', []);
    }

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->submit('updateNotificationStatus', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $expectedLeaseExpiresAt]);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->submit('cleanupOldNotifications', [$maxAgeSeconds]);
    }

    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->submit('enqueueStorageGarbage', [$bucket, $storageTier, $storagePath]);
    }

    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
    ): void {
        $this->submit('createMultipartPartWriteIntent', [$bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath]);
    }

    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        return $this->submit('consumeMultipartPartWriteIntent', [$bucket, $uploadId, $storagePath]);
    }

    public function reserveLocalMultipartPartWriteIntent(string $bucket, string $bucketGeneration, string $storageTier, string $uploadId, string $storagePath): void
    {
        $this->submit('reserveLocalMultipartPartWriteIntent', [$bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath]);
    }

    public function canSealLocalMultipartPartClaim(int $garbageId, string $storagePath, int $leaseGeneration): bool
    {
        return $this->submit('canSealLocalMultipartPartClaim', [$garbageId, $storagePath, $leaseGeneration]);
    }

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->submit('armLocalMultipartPartWriteIntent', [$bucket, $uploadId, $storagePath, $dispatchId]);
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->submit('consumeLocalMultipartPartWriteIntent', [$bucket, $uploadId, $storagePath, $dispatchId]);
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        return $this->submit('retireUndispatchedLocalMultipartPartWriteIntent', [$bucket, $uploadId, $storagePath]);
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->submit('retireTerminalLocalMultipartPartWriteIntent', [$bucket, $uploadId, $storagePath, $dispatchId]);
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        return $this->submit('retireExpiredUndispatchedLocalMultipartPartWriteIntents', [$storageTier, $limit]);
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        $this->submit(__FUNCTION__, [$storageTier]);
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        $this->submit(__FUNCTION__, [$storageTier]);
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        return $this->submit('reserveObjectWriteIntent', [$intent, $leaseSeconds]);
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->submit('getObjectWriteIntent', [$attemptId]);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->submit('renewObjectWriteIntent', [$attemptId, $expectedLeaseUntil, $leaseSeconds]);
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->submit('armObjectWriteIntent', [$attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds]);
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        return $this->submit('retireUndispatchedObjectWriteIntent', [$attemptId]);
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->submit('retireTerminalPreLinkObjectWriteIntent', [$attemptId, $dispatchId]);
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        return $this->submit('consumeObjectWriteIntent', [$attemptId]);
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->submit('consumeArmedObjectWriteIntent', [$attemptId, $dispatchId]);
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        return $this->submit('abandonObjectWriteIntent', [$attemptId]);
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        return $this->submit('claimDueObjectWriteIntents', [$storageTier, $limit, $leaseSeconds]);
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        return $this->submit('retireExpiredUndispatchedObjectWriteIntents', [$storageTier, $limit]);
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->submit('renewObjectWriteClaim', [$attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds]);
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        return $this->submit('sealObjectWriteIntent', [$attemptId, $claimGeneration]);
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        $this->submit('stageBucketMultipartCleanup', [$bucket, $storageTier]);
    }

    public function stageBucketMultipartCleanupForBucket(BucketInfo $bucket, string $storageTier): void
    {
        $this->submit('stageBucketMultipartCleanupForBucket', [$bucket, $storageTier]);
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->submit('discardStorageGarbage', [$bucket, $storageTier, $storagePath]);
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        return $this->submit('dequeueStorageGarbage', [$limit]);
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->submit('completeStorageGarbage', [$id, $expectedLeaseExpiresAt]);
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->submit('retryStorageGarbage', [$id, $error, $nextAttemptAt, $expectedLeaseExpiresAt]);
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('completeStorageGarbageLease', [$id, $leaseExpiresAt, $leaseGeneration]);
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('retryStorageGarbageLease', [$id, $error, $nextAttemptAt, $leaseExpiresAt, $leaseGeneration]);
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('renewStorageGarbageLease', [$id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration]);
    }

    public function updateNotificationStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('updateNotificationStatusLease', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $leaseExpiresAt, $leaseGeneration]);
    }

    public function updateTierTransitionJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $targetStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('updateTierTransitionJobStatusLease', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $leaseExpiresAt, $leaseGeneration]);
    }

    public function renewTierTransitionJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('renewTierTransitionJobLeaseFenced', [$id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration]);
    }

    public function updateRestoreJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $restoredStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('updateRestoreJobStatusLease', [$id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $leaseExpiresAt, $leaseGeneration]);
    }

    public function renewRestoreJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->submit('renewRestoreJobLeaseFenced', [$id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration]);
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
            throw new \LogicException('Nested beginTransaction() is unsupported; use transaction() to join the current transaction.');
        }

        $worker = $this->pool->getWorker();
        $guard = new TransactionGuard($worker, $this->databasePath);
        $this->fiberWorkerMap[$fiber] = ['worker' => $worker, 'guard' => $guard];

        try {
            $this->submit('beginTransaction');
        } catch (\Throwable $error) {
            try {
                $this->rollbackPinnedAfterFailure();
            } finally {
                $this->unpinFiber();
            }
            throw $error;
        }
    }

    public function commit(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberWorkerMap[$fiber])) {
            throw new \LogicException('commit() requires an active transaction in the current Fiber.');
        }
        try {
            $this->submit('commit');
        } catch (\Throwable $error) {
            $this->rollbackPinnedAfterFailure();
            throw $error;
        } finally {
            $this->unpinFiber();
        }
    }

    public function rollback(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberWorkerMap[$fiber])) {
            throw new \LogicException('rollback() requires an active transaction in the current Fiber.');
        }
        try {
            $this->submit('rollback');
        } catch (\Throwable $error) {
            $this->killPinnedWorker();
            throw $error;
        } finally {
            $this->unpinFiber();
        }
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || ! isset($this->fiberWorkerMap[$fiber])) {
            throw new \LogicException('lockOwnerForUpdate() requires an active transaction in the current Fiber.');
        }
        $this->submit('lockOwnerForUpdate', [$ownerId]);
    }

    public function transaction(callable $callback): mixed
    {
        if (\Fiber::getCurrent() === null) {
            return \Amp\async(fn(): mixed => $this->transaction($callback))->await();
        }

        $fiber = \Fiber::getCurrent();
        $ownTx = ! isset($this->fiberWorkerMap[$fiber]);
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
            if ($ownTx && isset($this->fiberWorkerMap[$fiber])) {
                try {
                    $this->rollback();
                } catch (\Throwable) {
                    // Preserve the operation failure if the pinned worker is
                    // already unable to roll the transaction back.
                }
            }

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

    private function rollbackPinnedAfterFailure(): void
    {
        try {
            $this->submit('rollback');
        } catch (\Throwable) {
            $this->killPinnedWorker();
        }
    }

    private function killPinnedWorker(): void
    {
        $worker = $this->getPinnedWorker();
        if ($worker === null) {
            return;
        }
        try {
            $worker->kill();
        } catch (\Throwable) {
            // The worker is already unusable; the pool will discard it once
            // the pinned wrapper is released.
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
        if (! $this->armed) {
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
