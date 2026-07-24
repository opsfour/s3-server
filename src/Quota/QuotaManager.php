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

    public function assertCanCreateBucket(string $ownerId): void
    {
        $quota = $this->quotas->quotaForOwner($ownerId);
        if ($quota->maxBucketsPerOwner <= 0) {
            return;
        }

        $bucketCount = count($this->metadata->listBuckets($ownerId));
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
    ): void {
        $quota = $this->quotas->quotaForOwner($ownerId);
        if (! $quota->enabled()) {
            return;
        }

        $stats = $this->metadata->getBucketStorageStats($bucket);
        $bucketMultipart = $this->metadata->getMultipartStorageStats($ownerId, $bucket);
        $completedStagingBytes = $completingUploadId !== null
            ? $this->partBytes($completingUploadId)
            : 0;
        $objectCountDelta = ($versioningEnabled || $existingObject === null) ? 1 : 0;
        $bytesDelta = $versioningEnabled
            ? $newObjectSize
            : $newObjectSize - ($existingObject !== null ? $existingObject->size : 0);

        $newBucketObjectCount = $stats['objectCount'] + $objectCountDelta;
        $newBucketBytes = $stats['bytesUsed']
            + $bucketMultipart['bytesUsed']
            - $completedStagingBytes
            + $bytesDelta;

        if ($quota->maxObjectsPerBucket > 0 && $newBucketObjectCount > $quota->maxObjectsPerBucket) {
            throw new QuotaExceededException(sprintf(
                'Object quota exceeded: bucket %s is limited to %d object(s).',
                $bucket,
                $quota->maxObjectsPerBucket,
            ));
        }

        if ($quota->maxBytesPerBucket > 0 && $newBucketBytes > $quota->maxBytesPerBucket) {
            throw new QuotaExceededException(sprintf(
                'Storage quota exceeded: bucket %s is limited to %d byte(s).',
                $bucket,
                $quota->maxBytesPerBucket,
            ));
        }

        if ($quota->maxBytesPerOwner > 0) {
            $ownerBytes = $this->ownerBytesUsed($ownerId)
                + $this->metadata->getMultipartStorageStats($ownerId)['bytesUsed']
                - $completedStagingBytes;
            if ($ownerBytes + $bytesDelta > $quota->maxBytesPerOwner) {
                throw new QuotaExceededException(sprintf(
                    'Storage quota exceeded: owner %s is limited to %d byte(s).',
                    $ownerId,
                    $quota->maxBytesPerOwner,
                ));
            }
        }
    }

    public function assertCanCreateMultipart(string $ownerId, string $bucket): void
    {
        $quota = $this->quotas->quotaForOwner($ownerId);
        $bucketStats = $this->metadata->getMultipartStorageStats($ownerId, $bucket);
        $ownerStats = $this->metadata->getMultipartStorageStats($ownerId);

        if (
            $quota->maxMultipartUploadsPerBucket > 0
            && $bucketStats['uploadCount'] >= $quota->maxMultipartUploadsPerBucket
        ) {
            throw new QuotaExceededException('Multipart upload count quota exceeded for bucket.');
        }
        if (
            $quota->maxMultipartUploadsPerOwner > 0
            && $ownerStats['uploadCount'] >= $quota->maxMultipartUploadsPerOwner
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
        $quota = $this->quotas->quotaForOwner($ownerId);
        $existingSize = 0;
        foreach ($this->metadata->getParts($uploadId) as $part) {
            if ($part['part_number'] === $partNumber) {
                $existingSize = $part['size'];
                break;
            }
        }
        $delta = $newSize - $existingSize;
        $bucketStaging = $this->metadata->getMultipartStorageStats($ownerId, $bucket)['bytesUsed'] + $delta;
        $ownerStaging = $this->metadata->getMultipartStorageStats($ownerId)['bytesUsed'] + $delta;

        if ($quota->maxMultipartBytesPerBucket > 0 && $bucketStaging > $quota->maxMultipartBytesPerBucket) {
            throw new QuotaExceededException('Multipart staging byte quota exceeded for bucket.');
        }
        if ($quota->maxMultipartBytesPerOwner > 0 && $ownerStaging > $quota->maxMultipartBytesPerOwner) {
            throw new QuotaExceededException('Multipart staging byte quota exceeded for owner.');
        }
        $bucketStored = $this->metadata->getBucketStorageStats($bucket)['bytesUsed'];
        if ($quota->maxBytesPerBucket > 0 && $bucketStored + $bucketStaging > $quota->maxBytesPerBucket) {
            throw new QuotaExceededException('Storage quota exceeded by multipart staging data.');
        }
        if (
            $quota->maxBytesPerOwner > 0
            && $this->ownerBytesUsed($ownerId) + $ownerStaging > $quota->maxBytesPerOwner
        ) {
            throw new QuotaExceededException('Owner storage quota exceeded by multipart staging data.');
        }
    }

    private function ownerBytesUsed(string $ownerId): int
    {
        $bytes = 0;

        foreach ($this->metadata->listBuckets($ownerId) as $bucketInfo) {
            $stats = $this->metadata->getBucketStorageStats($bucketInfo->name);
            $bytes += $stats['bytesUsed'];
        }

        return $bytes;
    }

    private function partBytes(string $uploadId): int
    {
        return array_sum(array_column($this->metadata->getParts($uploadId), 'size'));
    }
}
