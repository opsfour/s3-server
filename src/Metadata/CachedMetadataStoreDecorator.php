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
 * Caches CORS configuration only. Authorization, encryption, and versioning
 * state must be read from the shared store so a change on another server is
 * effective on the next request.
 *
 * Write operations invalidate the relevant cache entries immediately.
 * The CORS cache is per-process and can remain stale for up to the TTL after
 * a change on another server.
 */
final class CachedMetadataStoreDecorator implements MetadataStore, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartWriteIntentStore, FencedMultipartWriteIntentStore, LocalMultipartCompletionIntentStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, LocalMultipartFenceAdmissionStore, RecursiveCleanupAdmissionStore, MultipartUploadTierStore, ObjectWriteIntentStore, PagedMultipartPartsStore, MultipartPartChecksumStore, FencedStorageGarbageStore, StorageGarbageLeaseStore, FencedQueueStore, RestoredCopyTierStore, MultipartCompletionCapacityReader, WriteIntentCapacityReader
{
    /** @var array<string, array{value: mixed, expires: float}> */
    private array $cache = [];

    private int $cacheSize = 0;

    private const int MAX_CACHE_ENTRIES = 10_000;

    public function __construct(
        private readonly MetadataStore $inner,
        private readonly float $ttlSeconds = 5.0,
        private readonly int $maxCacheEntries = self::MAX_CACHE_ENTRIES,
    ) {
        if (! is_finite($ttlSeconds) || $ttlSeconds < 0.0) {
            throw new \InvalidArgumentException('Metadata cache TTL must be finite and >= 0 seconds.');
        }
        if ($maxCacheEntries < 1) {
            throw new \InvalidArgumentException('Metadata cache entry limit must be >= 1.');
        }
    }

    public function innerStore(): MetadataStore
    {
        return $this->inner;
    }

    // ---------------------------------------------------------------
    // Cached reads
    // ---------------------------------------------------------------

    public function getBucketOwner(string $bucket): ?string
    {
        return $this->inner->getBucketOwner($bucket);
    }

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        return $this->inner->getBucketOwnershipControls($bucket);
    }

    public function putBucketOwnershipControls(string $bucket, string $objectOwnership): void
    {
        $this->inner->putBucketOwnershipControls($bucket, $objectOwnership);
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $this->inner->deleteBucketOwnershipControls($bucket);
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->inner->bucketExists($bucket);
    }

    public function getBucketPolicy(string $bucket): ?string
    {
        return $this->inner->getBucketPolicy($bucket);
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        return $this->inner->getAccountPolicy($ownerId);
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        return $this->inner->getNamedPolicy($policyName);
    }

    public function lockNamedPoliciesForUpdate(array $policyNames): array
    {
        return $this->inner->lockNamedPoliciesForUpdate($policyNames);
    }

    public function getBucketCors(string $bucket): array
    {
        return $this->cached("cors:{$bucket}", fn() => $this->inner->getBucketCors($bucket));
    }

    public function getPublicAccessBlock(string $bucket): ?array
    {
        return $this->inner->getPublicAccessBlock($bucket);
    }

    public function getBucketEncryption(string $bucket): ?array
    {
        return $this->inner->getBucketEncryption($bucket);
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
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->inner->deleteBucketPolicy($bucket);
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
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->inner->deletePublicAccessBlock($bucket);
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->inner->putBucketEncryption($bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled);
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->inner->deleteBucketEncryption($bucket);
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

    public function getOwnerStorageStats(string $ownerId): array
    {
        return $this->inner->getOwnerStorageStats($ownerId);
    }

    public function getAccountQuota(string $ownerId): ?QuotaConfig
    {
        return $this->inner->getAccountQuota($ownerId);
    }

    public function listAccountQuotas(): array
    {
        return $this->inner->listAccountQuotas();
    }

    public function listAccountQuotasPage(?string $after, int $limit): array
    {
        return $this->inner->listAccountQuotasPage($after, $limit);
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
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->inner->deleteAccountPolicy($ownerId);
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->inner->putNamedPolicy($policyName, $policyJson);
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->inner->deleteNamedPolicy($policyName);
    }

    public function listBuckets(string $ownerId): array
    {
        return $this->inner->listBuckets($ownerId);
    }

    public function countBuckets(string $ownerId): int
    {
        return $this->inner->countBuckets($ownerId);
    }

    public function listBucketsPage(string $ownerId, ?string $after, ?string $prefix, ?string $region, int $maxBuckets): array
    {
        return $this->inner->listBucketsPage($ownerId, $after, $prefix, $region, $maxBuckets);
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

    public function getCurrentObjectVersion(string $bucket, string $key): ?ObjectInfo
    {
        return $this->inner->getCurrentObjectVersion($bucket, $key);
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

    public function updateObjectRestoreStateWithTier(string $bucket, string $key, ?string $versionId, ?string $restoreStatus, ?string $restoredStoragePath = null, ?\DateTimeImmutable $restoreExpiresAt = null, ?string $restoredStorageTier = null): void
    {
        if (! $this->inner instanceof RestoredCopyTierStore) {
            throw new \LogicException('Metadata backend does not support pinned restored-copy tiers.');
        }
        $this->inner->updateObjectRestoreStateWithTier($bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt, $restoredStorageTier);
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

    public function createMultipartUploadWithTier(string $uploadId, string $bucket, string $key, string $ownerId, string $storageTier, ?string $contentType = null, array $userMetadata = []): void
    {
        if (! $this->inner instanceof MultipartUploadTierStore) {
            throw new \LogicException('Metadata backend does not support pinned multipart upload tiers.');
        }
        $this->inner->createMultipartUploadWithTier($uploadId, $bucket, $key, $ownerId, $storageTier, $contentType, $userMetadata);
    }

    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void
    {
        $this->intentStore()->createMultipartWriteIntent($intent);
    }

    public function multipartWriteIntentNow(): float
    {
        return $this->intentStore()->multipartWriteIntentNow();
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        return $this->intentStore()->getMultipartWriteIntent($attemptId);
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        return $this->intentStore()->renewMultipartWriteIntent($attemptId, $expectedLeaseUntil, $newLeaseUntil);
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        return $this->intentStore()->abandonMultipartWriteIntent($attemptId, $nextAttemptAt);
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        return $this->intentStore()->abandonMultipartWriteIntentsForUpload($uploadId, $nextAttemptAt);
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        return $this->intentStore()->consumeMultipartWriteIntent($attemptId);
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->inner->armMultipartWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $newLeaseUntil);
    }

    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->inner->retireLocalMultipartWriteIntentBeforeDispatch($attemptId, $dispatchId);
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->inner->retireDueUndispatchedLocalMultipartWriteIntents($storageTier, $limit);
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->inner->consumeMultipartWriteIntentWithDispatch($attemptId, $dispatchId);
    }

    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        return $this->intentStore()->claimDueMultipartWriteIntents($limit, $now, $leaseUntil);
    }

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        return $this->intentStore()->retryMultipartWriteIntent($attemptId, $expectedLeaseUntil, $nextAttemptAt);
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        if (! $this->inner instanceof FencedMultipartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support fenced multipart collector claims.');
        }

        return $this->inner->renewMultipartCollectorClaim($attemptId, $newLeaseUntil, $expectedLeaseUntil, $claimGeneration);
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        if (! $this->inner instanceof FencedMultipartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support fenced multipart collector claims.');
        }

        return $this->inner->retryMultipartCollectorClaim($attemptId, $nextAttemptAt, $expectedLeaseUntil, $claimGeneration);
    }

    private function intentStore(): MultipartWriteIntentStore
    {
        if (! $this->inner instanceof MultipartWriteIntentStore) {
            throw new \LogicException('Metadata backend does not support durable multipart write intents.');
        }

        return $this->inner;
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        return $this->objectIntentStore()->reserveObjectWriteIntent($intent, $leaseSeconds);
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->objectIntentStore()->getObjectWriteIntent($attemptId);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->objectIntentStore()->renewObjectWriteIntent($attemptId, $expectedLeaseUntil, $leaseSeconds);
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->objectIntentStore()->armObjectWriteIntent($attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds);
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        return $this->objectIntentStore()->retireUndispatchedObjectWriteIntent($attemptId);
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->objectIntentStore()->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        return $this->objectIntentStore()->consumeObjectWriteIntent($attemptId);
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->objectIntentStore()->consumeArmedObjectWriteIntent($attemptId, $dispatchId);
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        return $this->objectIntentStore()->abandonObjectWriteIntent($attemptId);
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        return $this->objectIntentStore()->claimDueObjectWriteIntents($storageTier, $limit, $leaseSeconds);
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        return $this->objectIntentStore()->retireExpiredUndispatchedObjectWriteIntents($storageTier, $limit);
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->objectIntentStore()->renewObjectWriteClaim($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds);
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        return $this->objectIntentStore()->sealObjectWriteIntent($attemptId, $claimGeneration);
    }

    private function objectIntentStore(): ObjectWriteIntentStore
    {
        if (! $this->inner instanceof ObjectWriteIntentStore) {
            throw new \LogicException('Metadata backend does not support durable object write intents.');
        }

        return $this->inner;
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

    public function listMultipartUploadsForBucket(
        BucketInfo $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array {
        if (! $this->inner instanceof GenerationBoundMultipartListingStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart listing is unavailable for this metadata backend.');
        }

        return $this->inner->listMultipartUploadsForBucket($bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker);
    }

    public function getMultipartStorageStats(?string $ownerId, ?string $bucket = null): array
    {
        return $this->inner->getMultipartStorageStats($ownerId, $bucket);
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->inner->putPart($uploadId, $partNumber, $etag, $size, $storagePath);
    }

    public function supportsMultipartPartChecksums(): bool
    {
        return $this->inner instanceof MultipartPartChecksumStore
            && $this->inner->supportsMultipartPartChecksums();
    }

    public function putPartWithChecksum(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath, ?string $checksumAlgorithm, ?string $checksumValue): void
    {
        $inner = $this->inner;
        if (! $inner instanceof MultipartPartChecksumStore || ! $inner->supportsMultipartPartChecksums()) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Multipart part checksum persistence is unavailable.');
        }
        $inner->putPartWithChecksum($uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue);
    }

    public function getParts(string $uploadId): array
    {
        return $this->inner->getParts($uploadId);
    }

    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array
    {
        if ($this->inner instanceof PagedMultipartPartsStore) {
            return $this->inner->listPartsPage($uploadId, $afterPartNumber, $limit);
        }
        if ($limit < 1 || $limit > 1001) {
            throw new \InvalidArgumentException('Multipart part page limit must be between 1 and 1001.');
        }

        $parts = array_values(array_filter(
            $this->inner->getParts($uploadId),
            static fn(array $part): bool => $part['part_number'] > $afterPartNumber,
        ));

        return array_slice($parts, 0, $limit);
    }

    public function deleteParts(string $uploadId): void
    {
        $this->inner->deleteParts($uploadId);
    }

    public function getBucketVersioning(string $bucket): string
    {
        return $this->inner->getBucketVersioning($bucket);
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        $this->inner->setBucketVersioning($bucket, $status);
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

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        if (! $this->inner instanceof ObjectRetentionDeletionStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'Object retention removal is unavailable for this metadata backend.',
            );
        }
        $this->inner->deleteObjectRetention($bucket, $key, $versionId);
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

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        return $this->inner->getObjectTagging($bucket, $key, $versionId);
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $this->inner->putObjectTagging($bucket, $key, $tags, $versionId);
    }

    public function deleteObjectTagging(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->inner->deleteObjectTagging($bucket, $key, $versionId);
    }

    public function getBucketLifecycle(string $bucket): array
    {
        return $this->inner->getBucketLifecycle($bucket);
    }

    public function listReferencedStorageTiers(): array
    {
        return $this->inner->listReferencedStorageTiers();
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
        string $origin = 'direct',
        ?string $lifecycleRuleId = null,
        ?bool $expectedIsLatest = null,
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
            $origin,
            $lifecycleRuleId,
            $expectedIsLatest,
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
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->inner->updateTierTransitionJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $expectedLeaseExpiresAt);
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->inner->renewTierTransitionJobLease($id, $leaseExpiresAt, $expectedLeaseExpiresAt);
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
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->inner->updateRestoreJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $expectedLeaseExpiresAt);
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->inner->renewRestoreJobLease($id, $leaseExpiresAt, $expectedLeaseExpiresAt);
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

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null, ?\DateTimeImmutable $createdBefore = null): array
    {
        return $this->inner->listExpiredMultipartUploads($bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId, $createdBefore);
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
    ): int {
        return $this->inner->enqueueNotification($bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts);
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->inner->updateNotificationPayload($id, $payloadJson);
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->inner->dequeueNotifications($limit);
    }

    public function getNotificationQueueStats(): array
    {
        return $this->inner->getNotificationQueueStats();
    }

    public function getMultipartCompletionIntentCount(): ?int
    {
        return $this->inner instanceof MultipartCompletionCapacityReader
            ? $this->inner->getMultipartCompletionIntentCount()
            : null;
    }

    public function getWriteIntentCapacityCounts(): ?array
    {
        return $this->inner instanceof WriteIntentCapacityReader
            ? $this->inner->getWriteIntentCapacityCounts()
            : null;
    }

    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        return $this->inner->updateNotificationStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $expectedLeaseExpiresAt);
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->inner->cleanupOldNotifications($maxAgeSeconds);
    }

    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->inner->enqueueStorageGarbage($bucket, $storageTier, $storagePath);
    }

    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
    ): void {
        $store = $this->inner;
        if (! $store instanceof MultipartPartWriteIntentStore) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart part write intents are unavailable.');
        }
        $store->createMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath);
    }

    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        $store = $this->inner;
        if (! $store instanceof MultipartPartWriteIntentStore) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart part write intents are unavailable.');
        }

        return $store->consumeMultipartPartWriteIntent($bucket, $uploadId, $storagePath);
    }

    public function reserveLocalMultipartPartWriteIntent(string $bucket, string $bucketGeneration, string $storageTier, string $uploadId, string $storagePath): void
    {
        $this->fencedPartStore()->reserveLocalMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath);
    }

    public function canSealLocalMultipartPartClaim(int $garbageId, string $storagePath, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof LocalMultipartPartSealGuard) {
            return false;
        }

        return $this->inner->canSealLocalMultipartPartClaim($garbageId, $storagePath, $leaseGeneration);
    }

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->fencedPartStore()->armLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId);
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->fencedPartStore()->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId);
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        return $this->fencedPartStore()->retireUndispatchedLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath);
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->fencedPartStore()->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId);
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        return $this->fencedPartStore()->retireExpiredUndispatchedLocalMultipartPartWriteIntents($storageTier, $limit);
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        if (! $this->inner instanceof LocalMultipartFenceAdmissionStore) {
            throw new \LogicException('Inner metadata store lacks local multipart fence admission.');
        }
        $this->inner->assertNoLegacyLocalMultipartIntents($storageTier);
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        if (! $this->inner instanceof RecursiveCleanupAdmissionStore) {
            throw new \LogicException('Inner metadata store lacks recursive cleanup admission.');
        }
        $this->inner->assertNoUnsafeRecursiveCleanup($storageTier);
    }

    private function fencedPartStore(): FencedMultipartPartWriteIntentStore
    {
        if (! $this->inner instanceof FencedMultipartPartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart part fencing.');
        }

        return $this->inner;
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        $this->inner->stageBucketMultipartCleanup($bucket, $storageTier);
    }

    public function stageBucketMultipartCleanupForBucket(BucketInfo $bucket, string $storageTier): void
    {
        if (! $this->inner instanceof GenerationBoundBucketMultipartCleanupStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart cleanup is unavailable for this metadata backend.');
        }
        $this->inner->stageBucketMultipartCleanupForBucket($bucket, $storageTier);
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->inner->discardStorageGarbage($bucket, $storageTier, $storagePath);
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        return $this->inner->dequeueStorageGarbage($limit);
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->inner->completeStorageGarbage($id, $expectedLeaseExpiresAt);
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->inner->retryStorageGarbage($id, $error, $nextAttemptAt, $expectedLeaseExpiresAt);
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof FencedStorageGarbageStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim generations.');
        }

        return $this->inner->completeStorageGarbageLease($id, $leaseExpiresAt, $leaseGeneration);
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof FencedStorageGarbageStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim generations.');
        }

        return $this->inner->retryStorageGarbageLease($id, $error, $nextAttemptAt, $leaseExpiresAt, $leaseGeneration);
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof StorageGarbageLeaseStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim renewal.');
        }

        return $this->inner->renewStorageGarbageLease($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration);
    }

    private function fencedQueueStore(): FencedQueueStore
    {
        if (! $this->inner instanceof FencedQueueStore) {
            throw new \LogicException('Wrapped metadata store does not support queue claim generations.');
        }

        return $this->inner;
    }

    public function updateNotificationStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->fencedQueueStore()->updateNotificationStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $leaseExpiresAt, $leaseGeneration);
    }

    public function updateTierTransitionJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $targetStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->fencedQueueStore()->updateTierTransitionJobStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $leaseExpiresAt, $leaseGeneration);
    }

    public function renewTierTransitionJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->fencedQueueStore()->renewTierTransitionJobLeaseFenced($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration);
    }

    public function updateRestoreJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $restoredStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->fencedQueueStore()->updateRestoreJobStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $leaseExpiresAt, $leaseGeneration);
    }

    public function renewRestoreJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->fencedQueueStore()->renewRestoreJobLeaseFenced($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        try {
            $this->inner->commit();
        } catch (\Throwable $error) {
            // The transaction outcome can be unknown; no value read inside it
            // is safe to retain in the process cache.
            $this->clearCache();
            throw $error;
        }
    }

    public function rollback(): void
    {
        try {
            $this->inner->rollback();
        } finally {
            $this->clearCache();
        }
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $this->inner->lockOwnerForUpdate($ownerId);
    }

    public function transaction(callable $callback): mixed
    {
        try {
            return $this->inner->transaction($callback);
        } catch (\Throwable $e) {
            // Reads performed by the callback may have cached uncommitted values.
            $this->clearCache();

            throw $e;
        }
    }

    // ---------------------------------------------------------------
    // Cache internals
    // ---------------------------------------------------------------

    /**
     * @template T
     *
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
        if ($this->cacheSize >= $this->maxCacheEntries && ! isset($this->cache[$key])) {
            $this->evictExpired($now);
            if ($this->cacheSize >= $this->maxCacheEntries) {
                $oldest = array_key_first($this->cache);
                if ($oldest !== null) {
                    unset($this->cache[$oldest]);
                    $this->cacheSize--;
                }
            }
        }

        if (! isset($this->cache[$key])) {
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
        $this->invalidate("cors:{$bucket}");
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

    private function clearCache(): void
    {
        $this->cache = [];
        $this->cacheSize = 0;
    }
}
