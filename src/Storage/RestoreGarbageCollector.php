<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreGarbageCollector
{
    private readonly StorageTierRegistry $tiers;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageTierRegistry|StorageBackend $hotStorage,
        LoggerInterface $logger = new NullLogger(),
        string $hotTier = 'STANDARD',
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        $this->tiers = $hotStorage instanceof StorageTierRegistry
            ? $hotStorage
            : StorageTierRegistry::single($hotStorage, $hotTier);
    }

    public function assertBoundTo(MetadataStore $metadata, StorageTierRegistry $tiers, EfsHostEvidenceReader $evidence): void
    {
        if ($this->metadata !== $metadata || $this->tiers !== $tiers || $this->efsEvidence !== $evidence) {
            throw new \RuntimeException('Restore garbage collector differs from the EFS candidate resources.');
        }
    }

    /**
     * @return array{scanned: int, expired: int, failed: int}
     */
    public function collect(int $limit = 1000, ?\DateTimeImmutable $now = null): array
    {
        $this->efsEvidence?->assertFresh();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $objects = $this->metadata->listExpiredRestoredObjects($now, $limit);
        $this->efsEvidence?->assertFresh();
        $stats = ['scanned' => count($objects), 'expired' => 0, 'failed' => 0];

        foreach ($objects as $object) {
            $this->efsEvidence?->assertFresh();
            $path = $object->restoredStoragePath;
            if ($path === null || $path === '') {
                continue;
            }

            try {
                $restoreTier = RestoredCopyTierResolver::resolve($object, $this->tiers);
                $bucketOwner = $this->metadata->getBucketOwner($object->bucket);
                $this->efsEvidence?->assertFresh();
                $cleared = $this->metadata->transaction(function () use ($object, $path, $restoreTier, $now, $bucketOwner): bool {
                    $this->efsEvidence?->assertFresh();
                    if ($bucketOwner === null) {
                        return false;
                    }
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                    // A suspended null version can become noncurrent after a
                    // newer numbered version is written. Its restored copy
                    // still belongs to that exact version, not the latest.
                    $versionId = $object->versionId ?? 'null';
                    $current = $this->metadata->getObjectMetadataByVersion($object->bucket, $object->key, $versionId);
                    if (
                        $current === null
                        || $current->restoredStoragePath !== $path
                        || $current->restoredStorageTier !== $restoreTier->name
                        || $current->restoreExpiresAt === null
                        || $current->restoreExpiresAt > $now
                    ) {
                        return false;
                    }

                    // Persist the exact physical cleanup path before clearing
                    // the only object-metadata reference to this restore copy.
                    $this->metadata->enqueueStorageGarbage(
                        $current->bucket,
                        $restoreTier->name,
                        $path,
                    );
                    $this->metadata->updateObjectRestoreState(
                        bucket: $current->bucket,
                        key: $current->key,
                        versionId: $versionId,
                        restoreStatus: null,
                        restoredStoragePath: null,
                        restoreExpiresAt: null,
                    );

                    return true;
                });
                if (! $cleared) {
                    continue;
                }

                $this->efsEvidence?->assertFresh();
                StorageGarbageDiscard::afterDelete(
                    $this->metadata,
                    $restoreTier->backend,
                    $object->bucket,
                    $restoreTier->name,
                    $path,
                );
                $stats['expired']++;
            } catch (\Amp\CancelledException $e) {
                // The exact path was queued before deletion. Let shutdown
                // stop the sweep; the storage collector can finish it later.
                throw $e;
            } catch (\Throwable $e) {
                $this->efsEvidence?->assertFresh();
                $stats['failed']++;
                $this->logger->warning('Expired restore cleanup failed.', [
                    'bucket' => $object->bucket,
                    'key' => $object->key,
                    'version_id' => $object->versionId,
                    'restored_storage_path_hash' => hash('sha256', $path),
                    'exception' => $e::class,
                ]);
            }
        }

        return $stats;
    }
}
