<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreGarbageCollector
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $hotStorage,
        private readonly LoggerInterface $logger = new NullLogger,
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
                $this->hotStorage->deleteObjectByPath($path, $object->bucket);
                $this->metadata->updateObjectRestoreState(
                    bucket: $object->bucket,
                    key: $object->key,
                    versionId: $object->versionId,
                    restoreStatus: null,
                    restoredStoragePath: null,
                    restoreExpiresAt: null,
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
