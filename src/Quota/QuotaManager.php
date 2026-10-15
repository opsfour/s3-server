<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Metadata\MetadataStore;

final readonly class QuotaManager
{
    public function __construct(
        private MetadataStore $metadata,
        private QuotaProvider $quotas,
    ) {}

    public static function fromGlobalConfig(MetadataStore $metadata, QuotaConfig $config): self
    {
        return new self($metadata, new MetadataQuotaProvider(
            $metadata,
            new ConfigQuotaProvider($config),
        ));
    }

    public function hasQuotaForOwner(string $ownerId): bool
    {
        return $this->quotas->hasQuotaForOwner($ownerId);
    }

    public function assertCanCreateBucket(string $ownerId): void
    {
        $quota = $this->quotas->quotaForOwner($ownerId);
        if ($quota->maxBucketsPerOwner <= 0) {
            return;
        }

        $bucketCount = $this->metadata->countBuckets($ownerId);
        if ($bucketCount >= $quota->maxBucketsPerOwner) {
            throw new QuotaExceededException(sprintf(
                'Bucket quota exceeded: owner %s is limited to %d bucket(s).',
                $ownerId,
                $quota->maxBucketsPerOwner,
            ));
        }
    }

    public function assertCanWriteObject(
        string $ownerId,
        string $bucket,
        ?ObjectInfo $existingObject,
        int $newObjectSize,
        bool $versioningEnabled,
        ?string $completingUploadId = null,
        ?string $stagingOwnerId = null,
    ): void {
        $ownerQuota = $this->quotas->quotaForOwner($ownerId);
        $bucketQuota = $this->quotas->quotaForOwner($this->metadata->getBucketOwner($bucket) ?? $ownerId);
        if (! $ownerQuota->enabled() && ! $bucketQuota->enabled()) {
            return;
        }

        $stats = $this->metadata->getBucketStorageStats($bucket);
        $bucketMultipart = $this->metadata->getMultipartStorageStats(null, $bucket);
        $completedStagingBytes = $completingUploadId !== null
            ? $this->partBytes($completingUploadId)
            : 0;
        // A suspended write replaces only an existing null version. The
        // latest numbered version remains stored and must still count toward
        // bucket and owner quotas, even if a caller passes it here.
        $replacedObject = ($versioningEnabled
            || $existingObject?->versionId !== null
            || $existingObject?->isDeleteMarker === true)
            ? null
            : $existingObject;
        $objectCountDelta = $replacedObject === null ? 1 : 0;
        $bytesDelta = $newObjectSize - ($replacedObject === null ? 0 : $replacedObject->size);

        $newBucketObjectCount = $stats['objectCount'] + $objectCountDelta;
        $newBucketBytes = $stats['bytesUsed']
            + $bucketMultipart['bytesUsed']
            - $completedStagingBytes
            + $bytesDelta;

        if ($bucketQuota->maxObjectsPerBucket > 0 && $newBucketObjectCount > $bucketQuota->maxObjectsPerBucket) {
            throw new QuotaExceededException(sprintf(
                'Object quota exceeded: bucket %s is limited to %d object(s).',
                $bucket,
                $bucketQuota->maxObjectsPerBucket,
            ));
        }

        if ($bucketQuota->maxBytesPerBucket > 0 && $newBucketBytes > $bucketQuota->maxBytesPerBucket) {
            throw new QuotaExceededException(sprintf(
                'Storage quota exceeded: bucket %s is limited to %d byte(s).',
                $bucket,
                $bucketQuota->maxBytesPerBucket,
            ));
        }

        if ($ownerQuota->maxBytesPerOwner > 0) {
            $ownerDelta = $newObjectSize
                - ($replacedObject?->ownerId === $ownerId ? $replacedObject->size : 0);
            $ownerBytes = $this->metadata->getOwnerStorageStats($ownerId)['bytesUsed']
                + $this->metadata->getMultipartStorageStats($ownerId)['bytesUsed']
                - ($stagingOwnerId === null || $stagingOwnerId === $ownerId ? $completedStagingBytes : 0);
            if ($ownerBytes + $ownerDelta > $ownerQuota->maxBytesPerOwner) {
                throw new QuotaExceededException(sprintf(
                    'Storage quota exceeded: owner %s is limited to %d byte(s).',
                    $ownerId,
                    $ownerQuota->maxBytesPerOwner,
                ));
            }
        }
    }

    public function assertCanCreateMultipart(string $ownerId, string $bucket): void
    {
        $ownerQuota = $this->quotas->quotaForOwner($ownerId);
        $bucketQuota = $this->quotas->quotaForOwner($this->metadata->getBucketOwner($bucket) ?? $ownerId);
        $bucketStats = $this->metadata->getMultipartStorageStats(null, $bucket);
        $ownerStats = $this->metadata->getMultipartStorageStats($ownerId);

        if (
            $bucketQuota->maxMultipartUploadsPerBucket > 0
            && $bucketStats['uploadCount'] >= $bucketQuota->maxMultipartUploadsPerBucket
        ) {
            throw new QuotaExceededException('Multipart upload count quota exceeded for bucket.');
        }
        if (
            $ownerQuota->maxMultipartUploadsPerOwner > 0
            && $ownerStats['uploadCount'] >= $ownerQuota->maxMultipartUploadsPerOwner
        ) {
            throw new QuotaExceededException('Multipart upload count quota exceeded for owner.');
        }
    }

    public function assertCanWritePart(
        string $ownerId,
        string $bucket,
        string $uploadId,
        int $partNumber,
        int $newSize,
    ): void {
        $ownerQuota = $this->quotas->quotaForOwner($ownerId);
        $bucketQuota = $this->quotas->quotaForOwner($this->metadata->getBucketOwner($bucket) ?? $ownerId);
        $existingSize = 0;
        foreach ($this->metadata->getParts($uploadId) as $part) {
            if ($part['part_number'] === $partNumber) {
                $existingSize = $part['size'];
                break;
            }
        }
        $delta = $newSize - $existingSize;
        $bucketStaging = $this->metadata->getMultipartStorageStats(null, $bucket)['bytesUsed'] + $delta;
        $ownerStaging = $this->metadata->getMultipartStorageStats($ownerId)['bytesUsed'] + $delta;

        if ($bucketQuota->maxMultipartBytesPerBucket > 0 && $bucketStaging > $bucketQuota->maxMultipartBytesPerBucket) {
            throw new QuotaExceededException('Multipart staging byte quota exceeded for bucket.');
        }
        if ($ownerQuota->maxMultipartBytesPerOwner > 0 && $ownerStaging > $ownerQuota->maxMultipartBytesPerOwner) {
            throw new QuotaExceededException('Multipart staging byte quota exceeded for owner.');
        }
        $bucketStored = $this->metadata->getBucketStorageStats($bucket)['bytesUsed'];
        if ($bucketQuota->maxBytesPerBucket > 0 && $bucketStored + $bucketStaging > $bucketQuota->maxBytesPerBucket) {
            throw new QuotaExceededException('Storage quota exceeded by multipart staging data.');
        }
        if (
            $ownerQuota->maxBytesPerOwner > 0
            && $this->metadata->getOwnerStorageStats($ownerId)['bytesUsed'] + $ownerStaging > $ownerQuota->maxBytesPerOwner
        ) {
            throw new QuotaExceededException('Owner storage quota exceeded by multipart staging data.');
        }
    }

    private function partBytes(string $uploadId): int
    {
        return array_sum(array_column($this->metadata->getParts($uploadId), 'size'));
    }
}
