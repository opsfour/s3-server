<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\MetadataStore;
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
    ) {}

    /**
     * @return array{processed: int, completed: int, retried: int, deadLetter: int}
     */
    public function processNext(int $limit = 100): array
    {
        $stats = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'deadLetter' => 0];

        foreach ($this->metadata->dequeueRestoreJobs($limit) as $job) {
            $stats['processed']++;
            $stats[$this->processDequeuedJob($job)]++;
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

        try {
            $object = $this->objectForJob($job);
            if ($object === null || $object->isDeleteMarker) {
                return $this->completeStaleJob($job, 'Object metadata no longer exists for restore job.');
            }

            $sourceTier = (string) $job['sourceTier'];
            $sourceStoragePath = (string) $job['sourceStoragePath'];
            if ($object->storageTier !== $sourceTier || ($object->systemMetadata['storagePath'] ?? null) !== $sourceStoragePath) {
                return $this->completeStaleJob($job, 'Object placement changed before restore job was processed.');
            }

            $sourceBackend = $this->tiers->tier($sourceTier)->backend;
            $targetBackend = $this->tiers->defaultBackend();
            $sourceSize = 0;
            $sourceMd5 = hash_init('md5');
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

            if ($write->size !== $sourceSize || strtolower($write->md5Hex) !== strtolower(hash_final($sourceMd5))) {
                throw new \RuntimeException('Restore physical copy verification failed.');
            }

            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . max(1, (int) $job['restoreDays']) . ' days');

            $this->metadata->transaction(function () use ($id, $object, $write, $expiresAt): void {
                $this->metadata->updateObjectRestoreState(
                    bucket: $object->bucket,
                    key: $object->key,
                    versionId: $object->versionId,
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
            });

            $this->notifications?->dispatchEvent(new S3Event(
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
            ));

            return 'completed';
        } catch (\Throwable $e) {
            if ($targetBackend !== null && $write !== null) {
                try {
                    $targetBackend->deleteObjectByPath($write->path, (string) ($job['bucket'] ?? ''));
                } catch (\Throwable) {
                }
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

    /** @param array<string, mixed> $job */
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
     * @return 'retried'|'deadLetter'
     */
    private function retryOrDeadLetter(array $job, \Throwable $e): string
    {
        $id = (int) $job['id'];
        $attempts = (int) ($job['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($job['maxAttempts'] ?? 1));
        $deadLetter = $attempts + 1 >= $maxAttempts;
        $status = $deadLetter ? 'dead_letter' : 'pending';
        $nextAttemptAt = $deadLetter ? null : microtime(true) + min(300, 2 ** min(8, $attempts));

        $this->metadata->updateRestoreJobStatus($id, $status, $e->getMessage(), $nextAttemptAt);

        if ($deadLetter) {
            try {
                $object = $this->objectForJob($job);
                if ($object !== null && ! $object->isDeleteMarker) {
                    $this->metadata->updateObjectRestoreState($object->bucket, $object->key, $object->versionId, 'failed');
                }
            } catch (\Throwable) {
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
