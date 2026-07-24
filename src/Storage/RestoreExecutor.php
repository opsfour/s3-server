<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreExecutor
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $tiers,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly float $leaseRenewalIntervalSeconds = 100.0,
    ) {
        if ($this->leaseRenewalIntervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('Restore lease renewal interval must be greater than zero.');
        }
    }

    /**
     * @return array{processed: int, completed: int, retried: int, deadLetter: int}
     */
    public function processNext(int $limit = 100, ?Cancellation $cancellation = null): array
    {
        $stats = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'deadLetter' => 0];

        $limit = max(1, $limit);
        while ($stats['processed'] < $limit) {
            $cancellation?->throwIfRequested();
            $jobs = $this->metadata->dequeueRestoreJobs(1);
            if ($jobs === []) {
                break;
            }

            $job = $jobs[0];
            $stats['processed']++;
            $stats[$this->processDequeuedJob($job)]++;
            $cancellation?->throwIfRequested();
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $job
     * @return 'completed'|'retried'|'deadLetter'
     */
    public function processDequeuedJob(array $job): string
    {
        $id = (int) $job['id'];
        $targetBackend = null;
        $write = null;
        $metadataCommitted = false;

        try {
            $bucketOwner = $this->metadata->getBucketOwner((string) ($job['bucket'] ?? ''));
            $object = $this->prepareJob($job, $bucketOwner);
            if ($object === null || $object->isDeleteMarker) {
                return 'completed';
            }

            $heartbeat = new QueueLeaseHeartbeat(
                fn(float $expiresAt): bool => $this->metadata->renewRestoreJobLease($id, $expiresAt),
                $this->leaseRenewalIntervalSeconds,
            );
            $sourceTier = (string) $job['sourceTier'];
            $sourceStoragePath = (string) $job['sourceStoragePath'];
            $sourceBackend = $this->tiers->tier($sourceTier)->backend;
            $targetBackend = $this->tiers->defaultBackend();
            $sourceSize = 0;
            $sourceMd5 = hash_init('md5');
            try {
                $sourceStream = new TeeReadableStream(
                    $sourceBackend->getObjectByPath($sourceStoragePath),
                    static function (string $chunk) use (&$sourceSize, $sourceMd5): void {
                        $sourceSize += strlen($chunk);
                        hash_update($sourceMd5, $chunk);
                    },
                );
                $write = $targetBackend->putObject(
                    $object->bucket,
                    $object->key,
                    $sourceStream,
                );
            } finally {
                $heartbeat->stop();
            }

            if ($write->size !== $sourceSize || strtolower($write->md5Hex) !== strtolower(hash_final($sourceMd5))) {
                throw new \RuntimeException('Restore physical copy verification failed.');
            }

            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . max(1, (int) $job['restoreDays']) . ' days');

            $event = $this->notifications?->createEvent(
                's3:ObjectRestore:Completed',
                $object->bucket,
                $object->key,
                $object->size,
                $object->etag,
                $object->ownerId,
                [
                    'versionId' => $object->versionId,
                    'sourceTier' => (string) $job['sourceTier'],
                    'restoreDays' => max(1, (int) $job['restoreDays']),
                    'restoreExpiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                ],
            ) ?? new S3Event(
                name: 's3:ObjectRestore:Completed',
                bucket: $object->bucket,
                key: $object->key,
                size: $object->size,
                etag: $object->etag,
                ownerId: $object->ownerId,
                attributes: [
                    'versionId' => $object->versionId,
                    'sourceTier' => (string) $job['sourceTier'],
                    'restoreDays' => max(1, (int) $job['restoreDays']),
                    'restoreExpiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                ],
            );

            $metadataCommitted = $this->metadata->transaction(function () use ($id, $job, $object, $sourceTier, $sourceStoragePath, $write, $expiresAt, $event, $bucketOwner): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }
                $currentJob = $this->metadata->getRestoreJob($id);
                $current = $this->objectForJob($job);
                if (($currentJob['status'] ?? null) !== 'processing') {
                    return false;
                }
                if (
                    $bucketOwner === null
                    || $current === null
                    || ! $this->sameObject($current, $object)
                    || $current->storageTier !== $sourceTier
                    || ($current->systemMetadata['storagePath'] ?? null) !== $sourceStoragePath
                ) {
                    $this->metadata->updateRestoreJobStatus(
                        id: $id,
                        status: 'completed',
                        error: 'Object changed while restore data was being copied.',
                        incrementAttempts: false,
                    );

                    return false;
                }

                $this->metadata->updateObjectRestoreState(
                    bucket: $current->bucket,
                    key: $current->key,
                    versionId: $current->versionId,
                    restoreStatus: 'restored',
                    restoredStoragePath: $write->path,
                    restoreExpiresAt: $expiresAt,
                );
                $this->metadata->updateRestoreJobStatus(
                    id: $id,
                    status: 'completed',
                    incrementAttempts: false,
                    restoredStoragePath: $write->path,
                );
                $this->notifications?->enqueueWebhooks($event);

                return true;
            });

            if (! $metadataCommitted) {
                $this->cleanTarget($job, $targetBackend, $write->path);

                return 'completed';
            }

            $this->notifications?->dispatchInternalEvent($event);

            return 'completed';
        } catch (\Throwable $e) {
            if (! $metadataCommitted && $targetBackend !== null && $write !== null) {
                $this->cleanTarget($job, $targetBackend, $write->path);
            }

            return $this->retryOrDeadLetter($job, $e);
        }
    }

    /** @param array<string, mixed> $job */
    private function objectForJob(array $job): ?ObjectInfo
    {
        $versionId = $job['versionId'] ?? null;
        if ($versionId !== null && $versionId !== '') {
            return $this->metadata->getObjectMetadataByVersion((string) $job['bucket'], (string) $job['key'], (string) $versionId);
        }

        return $this->metadata->getObjectMetadata((string) $job['bucket'], (string) $job['key']);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function prepareJob(array $job, ?string $bucketOwner): ?ObjectInfo
    {
        return $this->metadata->transaction(function () use ($job, $bucketOwner): ?ObjectInfo {
            if ($bucketOwner !== null) {
                OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            }

            $object = $this->objectForJob($job);
            if ($object === null || $object->isDeleteMarker || $bucketOwner === null) {
                $this->completeStaleJob($job, 'Object metadata no longer exists for restore job.');

                return null;
            }

            $sourceTier = (string) $job['sourceTier'];
            $sourceStoragePath = (string) $job['sourceStoragePath'];
            if ($object->storageTier !== $sourceTier || ($object->systemMetadata['storagePath'] ?? null) !== $sourceStoragePath) {
                $this->completeStaleJob($job, 'Object placement changed before restore job was processed.');

                return null;
            }

            return $object;
        });
    }

    private function sameObject(ObjectInfo $left, ObjectInfo $right): bool
    {
        return $left->bucket === $right->bucket
            && $left->key === $right->key
            && $left->versionId === $right->versionId
            && $left->ownerId === $right->ownerId
            && $left->etag === $right->etag
            && $left->size === $right->size
            && ($left->systemMetadata['storagePath'] ?? null) === ($right->systemMetadata['storagePath'] ?? null);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function cleanTarget(array $job, StorageBackend $targetBackend, string $path): void
    {
        try {
            DurableStorageDelete::run(
                $this->metadata,
                $targetBackend,
                (string) ($job['bucket'] ?? ''),
                $this->tiers->defaultTier()->name,
                $path,
            );
        } catch (\Throwable $cleanupError) {
            $this->logger->critical('Restore target cleanup could not be persisted.', [
                'job_id' => (int) $job['id'],
                'storage_path' => $path,
                'error' => $cleanupError->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return 'completed'
     */
    private function completeStaleJob(array $job, string $reason): string
    {
        $this->metadata->updateRestoreJobStatus(
            id: (int) $job['id'],
            status: 'completed',
            incrementAttempts: false,
            error: $reason,
        );

        $this->logger->info('Restore job skipped as stale.', [
            'job_id' => (int) $job['id'],
            'bucket' => (string) ($job['bucket'] ?? ''),
            'key' => (string) ($job['key'] ?? ''),
            'version_id' => $job['versionId'] ?? null,
            'reason' => $reason,
        ]);

        return 'completed';
    }

    /**
     * @param array<string, mixed> $job
     * @return 'completed'|'retried'|'deadLetter'
     */
    private function retryOrDeadLetter(array $job, \Throwable $e): string
    {
        $id = (int) $job['id'];
        $attempts = (int) ($job['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($job['maxAttempts'] ?? 1));
        $deadLetter = $attempts + 1 >= $maxAttempts;
        $status = $deadLetter ? 'dead_letter' : 'pending';
        $nextAttemptAt = $deadLetter ? null : microtime(true) + min(300, 2 ** min(8, $attempts));
        $bucketOwner = $this->metadata->getBucketOwner((string) ($job['bucket'] ?? ''));

        try {
            $updated = $this->metadata->transaction(function () use ($job, $id, $status, $e, $nextAttemptAt, $deadLetter, $bucketOwner): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }

                if (($this->metadata->getRestoreJob($id)['status'] ?? null) !== 'processing') {
                    return false;
                }

                $this->metadata->updateRestoreJobStatus($id, $status, $e->getMessage(), $nextAttemptAt);
                $object = $this->objectForJob($job);
                if (
                    $deadLetter
                    && $object !== null
                    && ! $object->isDeleteMarker
                    && $object->storageTier === (string) $job['sourceTier']
                    && ($object->systemMetadata['storagePath'] ?? null) === (string) $job['sourceStoragePath']
                ) {
                    $this->metadata->updateObjectRestoreState(
                        $object->bucket,
                        $object->key,
                        $object->versionId,
                        'failed',
                    );
                }

                return true;
            });
            if (! $updated) {
                $this->logger->info('Restore result ignored after processing lease was lost.', [
                    'job_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                return 'completed';
            }
        } catch (\Throwable $stateError) {
            if ($deadLetter) {
                $this->logger->error('Restore job was dead-lettered but object state could not be marked failed.', [
                    'job_id' => $id,
                    'bucket' => (string) ($job['bucket'] ?? ''),
                    'key' => (string) ($job['key'] ?? ''),
                    'version_id' => $job['versionId'] ?? null,
                    'exception' => $stateError::class,
                    'error' => $stateError->getMessage(),
                ]);
            }
        }

        $this->logger->warning('Restore job failed.', [
            'job_id' => $id,
            'status' => $status,
            'attempts' => $attempts + 1,
            'max_attempts' => $maxAttempts,
            'error' => $e->getMessage(),
        ]);

        return $deadLetter ? 'deadLetter' : 'retried';
    }
}
