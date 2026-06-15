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
    ): void {
        $quota = $this->quotas->quotaForOwner($ownerId);
        if (! $quota->enabled()) {
            return;
        }

        $stats = $this->metadata->getBucketStorageStats($bucket);
        $objectCountDelta = ($versioningEnabled || $existingObject === null) ? 1 : 0;
        $bytesDelta = $versioningEnabled
            ? $newObjectSize
            : $newObjectSize - ($existingObject !== null ? $existingObject->size : 0);

        $newBucketObjectCount = $stats['objectCount'] + $objectCountDelta;
        $newBucketBytes = $stats['bytesUsed'] + $bytesDelta;

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
            $ownerBytes = $this->ownerBytesUsed($ownerId);
            if ($ownerBytes + $bytesDelta > $quota->maxBytesPerOwner) {
                throw new QuotaExceededException(sprintf(
                    'Storage quota exceeded: owner %s is limited to %d byte(s).',
                    $ownerId,
                    $quota->maxBytesPerOwner,
                ));
            }
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
}
