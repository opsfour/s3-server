<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\MetadataStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreExecutor
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $tiers,
        private readonly LoggerInterface $logger = new NullLogger,
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
            $write = $targetBackend->putObject(
                $object->bucket,
                $object->key,
                $sourceBackend->getObjectByPath($sourceStoragePath),
            );

            if ($write->size !== $object->size) {
                throw new \RuntimeException("Restore copy size mismatch: expected {$object->size}, got {$write->size}.");
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

            return 'completed';
        } catch (\Throwable $e) {
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
