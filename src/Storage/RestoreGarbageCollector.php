<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreGarbageCollector
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $hotStorage,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $hotTier = 'STANDARD',
    ) {}

    /**
     * @return array{scanned: int, expired: int, failed: int}
     */
    public function collect(int $limit = 1000, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $objects = $this->metadata->listExpiredRestoredObjects($now, $limit);
        $stats = ['scanned' => count($objects), 'expired' => 0, 'failed' => 0];

        foreach ($objects as $object) {
            $path = $object->restoredStoragePath;
            if ($path === null || $path === '') {
                continue;
            }

            try {
                $bucketOwner = $this->metadata->getBucketOwner($object->bucket);
                $cleared = $this->metadata->transaction(function () use ($object, $path, $now, $bucketOwner): bool {
                    if ($bucketOwner === null) {
                        return false;
                    }
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                    $current = $object->versionId !== null
                        ? $this->metadata->getObjectMetadataByVersion($object->bucket, $object->key, $object->versionId)
                        : $this->metadata->getObjectMetadata($object->bucket, $object->key);
                    if (
                        $current === null
                        || $current->restoredStoragePath !== $path
                        || $current->restoreExpiresAt === null
                        || $current->restoreExpiresAt > $now
                    ) {
                        return false;
                    }

                    $this->metadata->updateObjectRestoreState(
                        bucket: $current->bucket,
                        key: $current->key,
                        versionId: $current->versionId,
                        restoreStatus: null,
                        restoredStoragePath: null,
                        restoreExpiresAt: null,
                    );

                    return true;
                });
                if (! $cleared) {
                    continue;
                }

                DurableStorageDelete::run(
                    $this->metadata,
                    $this->hotStorage,
                    $object->bucket,
                    $this->hotTier,
                    $path,
                );
                $stats['expired']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->logger->warning('Expired restore cleanup failed.', [
                    'bucket' => $object->bucket,
                    'key' => $object->key,
                    'version_id' => $object->versionId,
                    'restored_storage_path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}
