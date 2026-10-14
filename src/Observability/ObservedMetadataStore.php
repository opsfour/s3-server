<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Metadata\GenerationBoundBucketMultipartCleanupStore;
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
use OpsFour\S3Server\Metadata\LocalMultipartFenceAdmissionStore;
use OpsFour\S3Server\Metadata\LocalMultipartPartSealGuard;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectRetentionDeletionStore;
use OpsFour\S3Server\Metadata\PagedMultipartPartsStore;
use OpsFour\S3Server\Metadata\MultipartPartChecksumStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;

final readonly class ObservedMetadataStore implements MetadataStore, SharedAuthorizationLockStore, ObjectRetentionDeletionStore, GenerationBoundMultipartListingStore, GenerationBoundBucketMultipartCleanupStore, MultipartWriteIntentStore, FencedMultipartWriteIntentStore, LocalMultipartCompletionIntentStore, FencedMultipartPartWriteIntentStore, LocalMultipartPartSealGuard, LocalMultipartFenceAdmissionStore, RecursiveCleanupAdmissionStore, MultipartUploadTierStore, ObjectWriteIntentStore, PagedMultipartPartsStore, MultipartPartChecksumStore, FencedStorageGarbageStore, StorageGarbageLeaseStore, FencedQueueStore, RestoredCopyTierStore, MultipartCompletionCapacityReader, WriteIntentCapacityReader
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
        $this->observe(__FUNCTION__, fn() => $this->inner->initialize());
    }

    public function createBucket(string $ownerId, string $bucket, string $region): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->createBucket($ownerId, $bucket, $region));
    }

    public function deleteBucket(string $ownerId, string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucket($ownerId, $bucket));
    }

    public function getBucketStats(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketStats($bucket));
    }

    public function getBucketStorageStats(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketStorageStats($bucket));
    }

    public function getOwnerStorageStats(string $ownerId): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getOwnerStorageStats($ownerId));
    }

    public function getAccountQuota(string $ownerId): ?\OpsFour\S3Server\Quota\QuotaConfig
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getAccountQuota($ownerId));
    }

    public function listAccountQuotas(): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listAccountQuotas());
    }

    public function listAccountQuotasPage(?string $after, int $limit): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listAccountQuotasPage($after, $limit));
    }

    public function putAccountQuota(string $ownerId, \OpsFour\S3Server\Quota\QuotaConfig $quota): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putAccountQuota($ownerId, $quota));
    }

    public function deleteAccountQuota(string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteAccountQuota($ownerId));
    }

    public function getAccountPolicy(string $ownerId): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getAccountPolicy($ownerId));
    }

    public function putAccountPolicy(string $ownerId, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putAccountPolicy($ownerId, $policyJson));
    }

    public function deleteAccountPolicy(string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteAccountPolicy($ownerId));
    }

    public function getNamedPolicy(string $policyName): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getNamedPolicy($policyName));
    }

    public function lockNamedPoliciesForUpdate(array $policyNames): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->lockNamedPoliciesForUpdate($policyNames));
    }

    public function lockNamedPoliciesForRead(array $policyNames): array
    {
        return $this->observe(__FUNCTION__, function () use ($policyNames): array {
            if ($this->inner instanceof SharedAuthorizationLockStore) {
                return $this->inner->lockNamedPoliciesForRead($policyNames);
            }

            return $this->inner->lockNamedPoliciesForUpdate($policyNames);
        });
    }

    public function putNamedPolicy(string $policyName, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putNamedPolicy($policyName, $policyJson));
    }

    public function deleteNamedPolicy(string $policyName): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteNamedPolicy($policyName));
    }

    public function getBucket(string $bucket): ?\OpsFour\S3Server\Dto\BucketInfo
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucket($bucket));
    }

    public function listBuckets(string $ownerId): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listBuckets($ownerId));
    }

    public function countBuckets(string $ownerId): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->countBuckets($ownerId));
    }

    public function listBucketsPage(string $ownerId, ?string $after, ?string $prefix, ?string $region, int $maxBuckets): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listBucketsPage($ownerId, $after, $prefix, $region, $maxBuckets));
    }

    public function listAllBuckets(): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listAllBuckets());
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->bucketExists($bucket));
    }

    public function getBucketOwner(string $bucket): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketOwner($bucket));
    }

    public function putObjectMetadata(string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath, string $storageClass = 'STANDARD', ?string $contentEncoding = null, ?string $contentDisposition = null, ?string $cacheControl = null, array $userMetadata = [], ?string $checksumCrc32 = null, ?string $checksumCrc32c = null, ?string $checksumSha1 = null, ?string $checksumSha256 = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putObjectMetadata($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256));
    }

    public function getObjectMetadata(string $bucket, string $key): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectMetadata($bucket, $key));
    }

    public function getCurrentObjectVersion(string $bucket, string $key): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getCurrentObjectVersion($bucket, $key));
    }

    public function deleteObjectMetadata(string $bucket, string $key): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteObjectMetadata($bucket, $key));
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->objectExists($bucket, $key));
    }

    public function updateObjectPlacement(string $bucket, string $key, ?string $versionId, string $storageClass, string $storageTier, string $storagePath, string $transitionStatus = 'available', ?string $transitionTargetTier = null, ?string $transitionError = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->updateObjectPlacement($bucket, $key, $versionId, $storageClass, $storageTier, $storagePath, $transitionStatus, $transitionTargetTier, $transitionError));
    }

    public function updateObjectRestoreState(string $bucket, string $key, ?string $versionId, ?string $restoreStatus, ?string $restoredStoragePath = null, ?\DateTimeImmutable $restoreExpiresAt = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->updateObjectRestoreState($bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt));
    }

    public function updateObjectRestoreStateWithTier(string $bucket, string $key, ?string $versionId, ?string $restoreStatus, ?string $restoredStoragePath = null, ?\DateTimeImmutable $restoreExpiresAt = null, ?string $restoredStorageTier = null): void
    {
        if (! $this->inner instanceof RestoredCopyTierStore) {
            throw new \LogicException('Metadata backend does not support pinned restored-copy tiers.');
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->updateObjectRestoreStateWithTier($bucket, $key, $versionId, $restoreStatus, $restoredStoragePath, $restoreExpiresAt, $restoredStorageTier));
    }

    public function countObjects(string $bucket): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->countObjects($bucket));
    }

    public function listObjects(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxKeys = 1000, ?string $startAfter = null, ?string $continuationToken = null): \OpsFour\S3Server\Dto\ListObjectsResult
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listObjects($bucket, $prefix, $delimiter, $maxKeys, $startAfter, $continuationToken));
    }

    public function createMultipartUpload(string $uploadId, string $bucket, string $key, string $ownerId, ?string $contentType = null, array $userMetadata = []): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->createMultipartUpload($uploadId, $bucket, $key, $ownerId, $contentType, $userMetadata));
    }

    public function createMultipartUploadWithTier(string $uploadId, string $bucket, string $key, string $ownerId, string $storageTier, ?string $contentType = null, array $userMetadata = []): void
    {
        if (! $this->inner instanceof MultipartUploadTierStore) {
            throw new \LogicException('Metadata backend does not support pinned multipart upload tiers.');
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->createMultipartUploadWithTier($uploadId, $bucket, $key, $ownerId, $storageTier, $contentType, $userMetadata));
    }

    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void
    {
        $this->observe(__FUNCTION__, fn() => $this->intentStore()->createMultipartWriteIntent($intent));
    }

    public function multipartWriteIntentNow(): float
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->multipartWriteIntentNow());
    }

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->getMultipartWriteIntent($attemptId));
    }

    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->renewMultipartWriteIntent($attemptId, $expectedLeaseUntil, $newLeaseUntil));
    }

    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->abandonMultipartWriteIntent($attemptId, $nextAttemptAt));
    }

    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->abandonMultipartWriteIntentsForUpload($uploadId, $nextAttemptAt));
    }

    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->consumeMultipartWriteIntent($attemptId));
    }

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->armMultipartWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $newLeaseUntil));
    }

    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->observe(
            __FUNCTION__,
            fn() => $this->inner->retireLocalMultipartWriteIntentBeforeDispatch($attemptId, $dispatchId),
        );
    }

    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->observe(
            __FUNCTION__,
            fn() => $this->inner->retireDueUndispatchedLocalMultipartWriteIntents($storageTier, $limit),
        );
    }

    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool
    {
        if (! $this->inner instanceof LocalMultipartCompletionIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart completion fencing.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->consumeMultipartWriteIntentWithDispatch($attemptId, $dispatchId));
    }

    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->claimDueMultipartWriteIntents($limit, $now, $leaseUntil));
    }

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->intentStore()->retryMultipartWriteIntent($attemptId, $expectedLeaseUntil, $nextAttemptAt));
    }

    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        if (! $this->inner instanceof FencedMultipartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support fenced multipart collector claims.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->renewMultipartCollectorClaim($attemptId, $newLeaseUntil, $expectedLeaseUntil, $claimGeneration));
    }

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        if (! $this->inner instanceof FencedMultipartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support fenced multipart collector claims.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->retryMultipartCollectorClaim($attemptId, $nextAttemptAt, $expectedLeaseUntil, $claimGeneration));
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
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->reserveObjectWriteIntent($intent, $leaseSeconds));
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->getObjectWriteIntent($attemptId));
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->renewObjectWriteIntent($attemptId, $expectedLeaseUntil, $leaseSeconds));
    }

    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->armObjectWriteIntent($attemptId, $expectedLeaseUntil, $dispatchId, $leaseSeconds));
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->retireUndispatchedObjectWriteIntent($attemptId));
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId));
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->consumeObjectWriteIntent($attemptId));
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->consumeArmedObjectWriteIntent($attemptId, $dispatchId));
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->abandonObjectWriteIntent($attemptId));
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->claimDueObjectWriteIntents($storageTier, $limit, $leaseSeconds));
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->retireExpiredUndispatchedObjectWriteIntents($storageTier, $limit));
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->renewObjectWriteClaim($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds));
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->objectIntentStore()->sealObjectWriteIntent($attemptId, $claimGeneration));
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
        return $this->observe(__FUNCTION__, fn() => $this->inner->getMultipartUpload($uploadId));
    }

    public function deleteMultipartUpload(string $uploadId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteMultipartUpload($uploadId));
    }

    public function listMultipartUploads(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxUploads = 1000, ?string $keyMarker = null, ?string $uploadIdMarker = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listMultipartUploads($bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker));
    }

    public function listMultipartUploadsForBucket(\OpsFour\S3Server\Dto\BucketInfo $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxUploads = 1000, ?string $keyMarker = null, ?string $uploadIdMarker = null): array
    {
        if (! $this->inner instanceof GenerationBoundMultipartListingStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart listing is unavailable for this metadata backend.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->listMultipartUploadsForBucket($bucket, $prefix, $delimiter, $maxUploads, $keyMarker, $uploadIdMarker));
    }

    public function getMultipartStorageStats(?string $ownerId, ?string $bucket = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getMultipartStorageStats($ownerId, $bucket));
    }

    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putPart($uploadId, $partNumber, $etag, $size, $storagePath));
    }

    public function supportsMultipartPartChecksums(): bool
    {
        return $this->inner instanceof MultipartPartChecksumStore
            && $this->inner->supportsMultipartPartChecksums();
    }

    public function putPartWithChecksum(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath, ?string $checksumAlgorithm, ?string $checksumValue): void
    {
        $this->observe(__FUNCTION__, function () use ($uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue): void {
            $inner = $this->inner;
            if (! $inner instanceof MultipartPartChecksumStore || ! $inner->supportsMultipartPartChecksums()) {
                throw new \OpsFour\S3Server\Exception\NotImplementedException('Multipart part checksum persistence is unavailable.');
            }
            $inner->putPartWithChecksum($uploadId, $partNumber, $etag, $size, $storagePath, $checksumAlgorithm, $checksumValue);
        });
    }

    public function getParts(string $uploadId): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getParts($uploadId));
    }

    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array
    {
        return $this->observe(__FUNCTION__, function () use ($uploadId, $afterPartNumber, $limit): array {
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
        });
    }

    public function deleteParts(string $uploadId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteParts($uploadId));
    }

    public function getBucketVersioning(string $bucket): string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketVersioning($bucket));
    }

    public function setBucketVersioning(string $bucket, string $status): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->setBucketVersioning($bucket, $status));
    }

    public function putObjectVersioned(string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath, string $storageClass = 'STANDARD', ?string $contentEncoding = null, ?string $contentDisposition = null, ?string $cacheControl = null, array $userMetadata = [], ?string $checksumCrc32 = null, ?string $checksumCrc32c = null, ?string $checksumSha1 = null, ?string $checksumSha256 = null): string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->putObjectVersioned($bucket, $key, $ownerId, $size, $etag, $contentType, $storagePath, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256));
    }

    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->deleteObjectVersioned($bucket, $key, $ownerId, $suspended));
    }

    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectMetadataByVersion($bucket, $key, $versionId));
    }

    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?\OpsFour\S3Server\Dto\ObjectInfo
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->deleteObjectVersion($bucket, $key, $versionId));
    }

    public function listObjectVersions(string $bucket, ?string $prefix = null, ?string $delimiter = null, int $maxKeys = 1000, ?string $keyMarker = null, ?string $versionIdMarker = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listObjectVersions($bucket, $prefix, $delimiter, $maxKeys, $keyMarker, $versionIdMarker));
    }

    public function getObjectLockConfig(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectLockConfig($bucket));
    }

    public function putObjectLockConfig(string $bucket, array $config): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putObjectLockConfig($bucket, $config));
    }

    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectRetention($bucket, $key, $versionId));
    }

    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putObjectRetention($bucket, $key, $mode, $retainUntilDate, $versionId));
    }

    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void
    {
        if (! $this->inner instanceof ObjectRetentionDeletionStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'Object retention removal is unavailable for this metadata backend.',
            );
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteObjectRetention($bucket, $key, $versionId));
    }

    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectLegalHold($bucket, $key, $versionId));
    }

    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putObjectLegalHold($bucket, $key, $status, $versionId));
    }

    public function getAcl(string $resourceType, string $resourceName): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getAcl($resourceType, $resourceName));
    }

    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putAcl($resourceType, $resourceName, $ownerId, $grants));
    }

    public function getBucketTagging(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketTagging($bucket));
    }

    public function putBucketTagging(string $bucket, array $tags): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketTagging($bucket, $tags));
    }

    public function deleteBucketTagging(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketTagging($bucket));
    }

    public function getObjectTagging(string $bucket, string $key, ?string $versionId = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getObjectTagging($bucket, $key, $versionId));
    }

    public function putObjectTagging(string $bucket, string $key, array $tags, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putObjectTagging($bucket, $key, $tags, $versionId));
    }

    public function deleteObjectTagging(string $bucket, string $key, ?string $versionId = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteObjectTagging($bucket, $key, $versionId));
    }

    public function getBucketPolicy(string $bucket): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketPolicy($bucket));
    }

    public function putBucketPolicy(string $bucket, string $policyJson): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketPolicy($bucket, $policyJson));
    }

    public function deleteBucketPolicy(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketPolicy($bucket));
    }

    public function getBucketCors(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketCors($bucket));
    }

    public function putBucketCors(string $bucket, array $rules): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketCors($bucket, $rules));
    }

    public function deleteBucketCors(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketCors($bucket));
    }

    public function getBucketEncryption(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketEncryption($bucket));
    }

    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketEncryption($bucket, $sseAlgorithm, $kmsMasterKeyId, $bucketKeyEnabled));
    }

    public function deleteBucketEncryption(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketEncryption($bucket));
    }

    public function getBucketLifecycle(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketLifecycle($bucket));
    }

    public function listReferencedStorageTiers(): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listReferencedStorageTiers());
    }

    public function putBucketLifecycle(string $bucket, array $rules): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketLifecycle($bucket, $rules));
    }

    public function deleteBucketLifecycle(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketLifecycle($bucket));
    }

    public function getBucketNotification(string $bucket): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketNotification($bucket));
    }

    public function putBucketNotification(string $bucket, array $configs): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketNotification($bucket, $configs));
    }

    public function getPublicAccessBlock(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getPublicAccessBlock($bucket));
    }

    public function getBucketOwnershipControls(string $bucket): ?string
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketOwnershipControls($bucket));
    }

    public function putBucketOwnershipControls(string $bucket, string $objectOwnership): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketOwnershipControls($bucket, $objectOwnership));
    }

    public function deleteBucketOwnershipControls(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketOwnershipControls($bucket));
    }

    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putPublicAccessBlock($bucket, $blockPublicAcls, $ignorePublicAcls, $blockPublicPolicy, $restrictPublicBuckets));
    }

    public function deletePublicAccessBlock(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deletePublicAccessBlock($bucket));
    }

    public function getBucketLogging(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketLogging($bucket));
    }

    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketLogging($bucket, $targetBucket, $targetPrefix));
    }

    public function deleteBucketLogging(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketLogging($bucket));
    }

    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->acquireLock($lockName, $ownerId, $ttlSeconds));
    }

    public function releaseLock(string $lockName, string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->releaseLock($lockName, $ownerId));
    }

    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getLifecycleCheckpoint($bucket, $ruleId, $action));
    }

    public function putLifecycleCheckpoint(string $bucket, string $ruleId, string $action, ?string $cursorKey, ?string $cursorVersionId = null, ?string $cursorUploadId = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putLifecycleCheckpoint($bucket, $ruleId, $action, $cursorKey, $cursorVersionId, $cursorUploadId));
    }

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteLifecycleCheckpoint($bucket, $ruleId, $action));
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
        return $this->observe(__FUNCTION__, fn() => $this->inner->enqueueTierTransitionJob(
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
        ));
    }

    public function dequeueTierTransitionJobs(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->dequeueTierTransitionJobs($limit));
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
        return $this->observe(__FUNCTION__, fn() => $this->inner->updateTierTransitionJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $expectedLeaseExpiresAt));
    }

    public function renewTierTransitionJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->renewTierTransitionJobLease($id, $leaseExpiresAt, $expectedLeaseExpiresAt));
    }

    public function getTierTransitionJob(int $id): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getTierTransitionJob($id));
    }

    public function enqueueRestoreJob(string $bucket, string $key, ?string $versionId, string $sourceTier, string $sourceStoragePath, int $restoreDays, int $maxAttempts = 10): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->enqueueRestoreJob($bucket, $key, $versionId, $sourceTier, $sourceStoragePath, $restoreDays, $maxAttempts));
    }

    public function dequeueRestoreJobs(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->dequeueRestoreJobs($limit));
    }

    public function updateRestoreJobStatus(int $id, string $status, ?string $error = null, ?float $nextAttemptAt = null, bool $incrementAttempts = true, ?string $restoredStoragePath = null, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->updateRestoreJobStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $expectedLeaseExpiresAt));
    }

    public function renewRestoreJobLease(int $id, float $leaseExpiresAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->renewRestoreJobLease($id, $leaseExpiresAt, $expectedLeaseExpiresAt));
    }

    public function getRestoreJob(int $id): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getRestoreJob($id));
    }

    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $afterKey));
    }

    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listExpiredNoncurrentVersions($bucket, $prefix, $noncurrentDays, $limit, $tags, $afterKey, $afterVersionId));
    }

    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null, ?\DateTimeImmutable $createdBefore = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listExpiredMultipartUploads($bucket, $daysAfterInitiation, $limit, $prefix, $afterKey, $afterUploadId, $createdBefore));
    }

    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listOrphanedDeleteMarkers($bucket, $prefix, $limit, $tags, $afterKey, $afterVersionId));
    }

    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->listExpiredRestoredObjects($now, $limit));
    }

    public function getBucketWebsite(string $bucket): ?array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getBucketWebsite($bucket));
    }

    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->putBucketWebsite($bucket, $indexDocument, $errorDocument, $redirectAllHost, $redirectAllProtocol, $routingRules));
    }

    public function deleteBucketWebsite(string $bucket): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->deleteBucketWebsite($bucket));
    }

    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->rateLimitCheck($ip, $maxTokens, $refillRate));
    }

    public function rateLimitCleanup(int $maxAgeSeconds): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->rateLimitCleanup($maxAgeSeconds));
    }

    public function enqueueNotification(string $bucket, string $key, string $eventName, string $destinationUrl, string $payloadJson, int $maxAttempts = 10): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->enqueueNotification($bucket, $key, $eventName, $destinationUrl, $payloadJson, $maxAttempts));
    }

    public function updateNotificationPayload(int $id, string $payloadJson): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->updateNotificationPayload($id, $payloadJson));
    }

    public function dequeueNotifications(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->dequeueNotifications($limit));
    }

    public function getNotificationQueueStats(): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->getNotificationQueueStats());
    }

    public function getMultipartCompletionIntentCount(): ?int
    {
        return $this->inner instanceof MultipartCompletionCapacityReader
            ? $this->observe(__FUNCTION__, fn() => $this->inner->getMultipartCompletionIntentCount())
            : null;
    }

    public function getWriteIntentCapacityCounts(): ?array
    {
        return $this->inner instanceof WriteIntentCapacityReader
            ? $this->observe(__FUNCTION__, fn() => $this->inner->getWriteIntentCapacityCounts())
            : null;
    }

    public function updateNotificationStatus(int $id, string $status, ?string $error = null, ?float $nextAttemptAt = null, bool $incrementAttempts = true, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->updateNotificationStatus($id, $status, $error, $nextAttemptAt, $incrementAttempts, $expectedLeaseExpiresAt));
    }

    public function cleanupOldNotifications(int $maxAgeSeconds): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->cleanupOldNotifications($maxAgeSeconds));
    }

    public function enqueueStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->enqueueStorageGarbage($bucket, $storageTier, $storagePath));
    }

    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
    ): void {
        $this->observe(__FUNCTION__, fn() => $this->partIntentStore()->createMultipartPartWriteIntent(
            $bucket,
            $bucketGeneration,
            $storageTier,
            $uploadId,
            $storagePath,
        ));
    }

    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->partIntentStore()->consumeMultipartPartWriteIntent($bucket, $uploadId, $storagePath));
    }

    public function reserveLocalMultipartPartWriteIntent(string $bucket, string $bucketGeneration, string $storageTier, string $uploadId, string $storagePath): void
    {
        $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->reserveLocalMultipartPartWriteIntent($bucket, $bucketGeneration, $storageTier, $uploadId, $storagePath));
    }

    public function canSealLocalMultipartPartClaim(int $garbageId, string $storagePath, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof LocalMultipartPartSealGuard) {
            return false;
        }

        return $this->observe(__FUNCTION__, fn(): bool => $this->inner->canSealLocalMultipartPartClaim($garbageId, $storagePath, $leaseGeneration));
    }

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->armLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId));
    }

    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId));
    }

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->retireUndispatchedLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath));
    }

    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $storagePath, $dispatchId));
    }

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedPartStore()->retireExpiredUndispatchedLocalMultipartPartWriteIntents($storageTier, $limit));
    }

    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void
    {
        if (! $this->inner instanceof LocalMultipartFenceAdmissionStore) {
            throw new \LogicException('Inner metadata store lacks local multipart fence admission.');
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->assertNoLegacyLocalMultipartIntents($storageTier));
    }

    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void
    {
        if (! $this->inner instanceof RecursiveCleanupAdmissionStore) {
            throw new \LogicException('Inner metadata store lacks recursive cleanup admission.');
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->assertNoUnsafeRecursiveCleanup($storageTier));
    }

    private function fencedPartStore(): FencedMultipartPartWriteIntentStore
    {
        if (! $this->inner instanceof FencedMultipartPartWriteIntentStore) {
            throw new \LogicException('Wrapped metadata store does not support local multipart part fencing.');
        }

        return $this->inner;
    }

    private function partIntentStore(): MultipartPartWriteIntentStore
    {
        if (! $this->inner instanceof MultipartPartWriteIntentStore) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart part write intents are unavailable.');
        }

        return $this->inner;
    }

    public function stageBucketMultipartCleanup(string $bucket, string $storageTier): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->stageBucketMultipartCleanup($bucket, $storageTier));
    }

    public function stageBucketMultipartCleanupForBucket(\OpsFour\S3Server\Dto\BucketInfo $bucket, string $storageTier): void
    {
        if (! $this->inner instanceof GenerationBoundBucketMultipartCleanupStore) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart cleanup is unavailable for this metadata backend.');
        }
        $this->observe(__FUNCTION__, fn() => $this->inner->stageBucketMultipartCleanupForBucket($bucket, $storageTier));
    }

    public function discardStorageGarbage(string $bucket, string $storageTier, string $storagePath): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->discardStorageGarbage($bucket, $storageTier, $storagePath));
    }

    public function dequeueStorageGarbage(int $limit): array
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->dequeueStorageGarbage($limit));
    }

    public function completeStorageGarbage(int $id, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->completeStorageGarbage($id, $expectedLeaseExpiresAt));
    }

    public function retryStorageGarbage(int $id, string $error, float $nextAttemptAt, ?float $expectedLeaseExpiresAt = null): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->retryStorageGarbage($id, $error, $nextAttemptAt, $expectedLeaseExpiresAt));
    }

    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof FencedStorageGarbageStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim generations.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->completeStorageGarbageLease($id, $leaseExpiresAt, $leaseGeneration));
    }

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof FencedStorageGarbageStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim generations.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->retryStorageGarbageLease($id, $error, $nextAttemptAt, $leaseExpiresAt, $leaseGeneration));
    }

    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        if (! $this->inner instanceof StorageGarbageLeaseStore) {
            throw new \LogicException('Wrapped metadata store does not support garbage claim renewal.');
        }

        return $this->observe(__FUNCTION__, fn() => $this->inner->renewStorageGarbageLease($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration));
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
        return $this->observe(__FUNCTION__, fn() => $this->fencedQueueStore()->updateNotificationStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $leaseExpiresAt, $leaseGeneration));
    }

    public function updateTierTransitionJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $targetStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedQueueStore()->updateTierTransitionJobStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $targetStoragePath, $leaseExpiresAt, $leaseGeneration));
    }

    public function renewTierTransitionJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedQueueStore()->renewTierTransitionJobLeaseFenced($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration));
    }

    public function updateRestoreJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $restoredStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedQueueStore()->updateRestoreJobStatusLease($id, $status, $error, $nextAttemptAt, $incrementAttempts, $restoredStoragePath, $leaseExpiresAt, $leaseGeneration));
    }

    public function renewRestoreJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool
    {
        return $this->observe(__FUNCTION__, fn() => $this->fencedQueueStore()->renewRestoreJobLeaseFenced($id, $newLeaseExpiresAt, $expectedLeaseExpiresAt, $leaseGeneration));
    }

    public function beginTransaction(): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->beginTransaction());
    }

    public function commit(): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->commit());
    }

    public function rollback(): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->rollback());
    }

    public function lockOwnerForUpdate(string $ownerId): void
    {
        $this->observe(__FUNCTION__, fn() => $this->inner->lockOwnerForUpdate($ownerId));
    }

    public function prepareOwnerReadLock(string $ownerId): void
    {
        $this->observe(__FUNCTION__, function () use ($ownerId): void {
            if ($this->inner instanceof SharedAuthorizationLockStore) {
                $this->inner->prepareOwnerReadLock($ownerId);
            }
        });
    }

    public function lockOwnerForRead(string $ownerId): void
    {
        $this->observe(__FUNCTION__, function () use ($ownerId): void {
            if ($this->inner instanceof SharedAuthorizationLockStore) {
                $this->inner->lockOwnerForRead($ownerId);

                return;
            }

            $this->inner->lockOwnerForUpdate($ownerId);
        });
    }

    public function transaction(callable $callback): mixed
    {
        return $this->observe(__FUNCTION__, fn() => $this->inner->transaction($callback));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
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
