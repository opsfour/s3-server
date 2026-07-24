<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes durable physical storage-tier transition jobs.
 */
final class TierTransitionExecutor
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $tiers,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $deleteSourceAfterCommit = true,
        private readonly float $leaseRenewalIntervalSeconds = 100.0,
    ) {
        if ($this->leaseRenewalIntervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('Tier transition lease renewal interval must be greater than zero.');
        }
    }

    /**
     * @return array{processed: int, completed: int, retried: int, deadLetter: int}
     */
    public function processNext(int $limit = 100, ?Cancellation $cancellation = null): array
    {
        $stats = [
            'processed' => 0,
            'completed' => 0,
            'retried' => 0,
            'deadLetter' => 0,
        ];

        $limit = max(1, $limit);
        while ($stats['processed'] < $limit) {
            $cancellation?->throwIfRequested();
            $jobs = $this->metadata->dequeueTierTransitionJobs(1);
            if ($jobs === []) {
                break;
            }

            $job = $jobs[0];
            $stats['processed']++;
            $result = $this->processDequeuedJob($job);
            $stats[$result]++;
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
        $writeResult = null;
        $metadataCommitted = false;

        try {
            $targetTier = $this->stringJobValue($job, 'targetTier');
            $targetStorageClass = $this->stringJobValue($job, 'targetStorageClass');
            $sourcePath = $this->stringJobValue($job, 'sourceStoragePath');
            $bucketOwner = $this->metadata->getBucketOwner($this->stringJobValue($job, 'bucket'));
            $object = $this->prepareJob($job, $sourcePath, $targetTier, $bucketOwner);
            if ($object === null) {
                return 'completed';
            }

            $heartbeat = new QueueLeaseHeartbeat(
                fn(float $expiresAt): bool => $this->metadata->renewTierTransitionJobLease($id, $expiresAt),
                $this->leaseRenewalIntervalSeconds,
            );
            $sourceBackend = $this->tiers->tier($this->stringJobValue($job, 'sourceTier'))->backend;
            $targetBackend = $this->tiers->tier($targetTier)->backend;
            $sourceSize = 0;
            $sourceMd5 = hash_init('md5');
            try {
                $sourceStream = new TeeReadableStream(
                    $sourceBackend->getObjectByPath($sourcePath),
                    static function (string $chunk) use (&$sourceSize, $sourceMd5): void {
                        $sourceSize += strlen($chunk);
                        hash_update($sourceMd5, $chunk);
                    },
                );
                $writeResult = $targetBackend->putObject(
                    $object->bucket,
                    $object->key,
                    $sourceStream,
                );
            } finally {
                $heartbeat->stop();
            }

            $this->verifyCopy(
                $object,
                $writeResult,
                $sourceSize,
                hash_final($sourceMd5),
            );

            $metadataCommitted = $this->metadata->transaction(function () use ($id, $job, $object, $sourcePath, $targetStorageClass, $targetTier, $writeResult, $bucketOwner): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }
                $currentJob = $this->metadata->getTierTransitionJob($id);
                $current = $this->objectForJob($job);
                if (($currentJob['status'] ?? null) !== 'processing') {
                    return false;
                }
                if (
                    $bucketOwner === null
                    || $current === null
                    || ! $this->sameObject($current, $object)
                    || ! $this->objectStillMatchesJobSource($current, $job, $sourcePath)
                ) {
                    $this->metadata->updateTierTransitionJobStatus(
                        id: $id,
                        status: 'completed',
                        incrementAttempts: false,
                        error: 'Object changed while tier transition data was being copied.',
                    );

                    return false;
                }

                $this->metadata->updateObjectPlacement(
                    bucket: $current->bucket,
                    key: $current->key,
                    versionId: $current->versionId,
                    storageClass: $targetStorageClass,
                    storageTier: $targetTier,
                    storagePath: $writeResult->path,
                );
                $this->metadata->updateTierTransitionJobStatus(
                    id: $id,
                    status: 'completed',
                    incrementAttempts: false,
                    targetStoragePath: $writeResult->path,
                );

                return true;
            });

            if (! $metadataCommitted) {
                $this->cleanTarget($job, $targetBackend, $writeResult->path);

                return 'completed';
            }

            if (
                $this->deleteSourceAfterCommit
                && ($this->stringJobValue($job, 'sourceTier') !== $targetTier || $sourcePath !== $writeResult->path)
            ) {
                try {
                    DurableStorageDelete::run(
                        $this->metadata,
                        $sourceBackend,
                        $object->bucket,
                        $this->stringJobValue($job, 'sourceTier'),
                        $sourcePath,
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('Tier transition source cleanup failed.', [
                        'job_id' => $id,
                        'bucket' => $object->bucket,
                        'key' => $object->key,
                        'source_tier' => $this->stringJobValue($job, 'sourceTier'),
                        'source_storage_path' => $sourcePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return 'completed';
        } catch (\Throwable $e) {
            if (! $metadataCommitted && $targetBackend !== null && $writeResult !== null) {
                $this->cleanTarget($job, $targetBackend, $writeResult->path);
            }

            return $this->retryOrDeadLetter($job, $e);
        }
    }

    /** @param array<string, mixed> $job */
    private function objectForJob(array $job): ?ObjectInfo
    {
        $bucket = $this->stringJobValue($job, 'bucket');
        $key = $this->stringJobValue($job, 'key');
        $versionId = $job['versionId'] ?? null;

        if ($versionId !== null && $versionId !== '') {
            return $this->metadata->getObjectMetadataByVersion($bucket, $key, (string) $versionId);
        }

        return $this->metadata->getObjectMetadata($bucket, $key);
    }

    /** @param array<string, mixed> $job */
    private function isAlreadyTransitioned(ObjectInfo $object, array $job): bool
    {
        return $object->storageTier === $this->stringJobValue($job, 'targetTier')
            && $object->storageClass === $this->stringJobValue($job, 'targetStorageClass')
            && ($object->systemMetadata['storagePath'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function prepareJob(
        array $job,
        string $sourcePath,
        string $targetTier,
        ?string $bucketOwner,
    ): ?ObjectInfo {
        return $this->metadata->transaction(function () use ($job, $sourcePath, $targetTier, $bucketOwner): ?ObjectInfo {
            if ($bucketOwner !== null) {
                OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            }

            $object = $this->objectForJob($job);
            if ($object === null || $object->isDeleteMarker || $bucketOwner === null) {
                $this->completeStaleJob($job, 'Object metadata no longer exists for tier transition job.');

                return null;
            }
            if ($this->isAlreadyTransitioned($object, $job)) {
                $this->metadata->updateTierTransitionJobStatus(
                    id: (int) $job['id'],
                    status: 'completed',
                    incrementAttempts: false,
                    targetStoragePath: $object->systemMetadata['storagePath'] ?? null,
                );

                return null;
            }
            if (! $this->objectStillMatchesJobSource($object, $job, $sourcePath)) {
                $this->completeStaleJob($job, 'Object placement changed before tier transition job was processed.');

                return null;
            }

            $this->metadata->updateObjectPlacement(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $object->versionId,
                storageClass: $object->storageClass,
                storageTier: $object->storageTier,
                storagePath: $sourcePath,
                transitionStatus: 'processing',
                transitionTargetTier: $targetTier,
            );

            return $object;
        });
    }

    /** @param array<string, mixed> $job */
    private function objectStillMatchesJobSource(ObjectInfo $object, array $job, string $sourcePath): bool
    {
        return $object->storageTier === $this->stringJobValue($job, 'sourceTier')
            && ($object->systemMetadata['storagePath'] ?? null) === $sourcePath;
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
                $this->stringJobValue($job, 'bucket'),
                $this->stringJobValue($job, 'targetTier'),
                $path,
            );
        } catch (\Throwable $cleanupError) {
            $this->logger->critical('Tier transition target cleanup could not be persisted.', [
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
        $this->metadata->updateTierTransitionJobStatus(
            id: (int) $job['id'],
            status: 'completed',
            incrementAttempts: false,
            error: $reason,
        );

        $this->logger->info('Tier transition job skipped as stale.', [
            'job_id' => (int) $job['id'],
            'bucket' => $this->stringJobValue($job, 'bucket'),
            'key' => $this->stringJobValue($job, 'key'),
            'version_id' => $job['versionId'] ?? null,
            'reason' => $reason,
        ]);

        return 'completed';
    }

    private function verifyCopy(
        ObjectInfo $object,
        StorageWriteResult $writeResult,
        int $sourceSize,
        string $sourceMd5,
    ): void {
        if ($writeResult->size !== $sourceSize) {
            throw new \RuntimeException("Tier transition physical copy size mismatch: expected {$sourceSize}, got {$writeResult->size}.");
        }

        if (strtolower($writeResult->md5Hex) !== strtolower($sourceMd5)) {
            throw new \RuntimeException('Tier transition physical copy MD5 mismatch.');
        }

        $encrypted = isset($object->userMetadata['__sse-algorithm']);
        if (! $encrypted && $sourceSize !== $object->size) {
            throw new \RuntimeException("Tier transition source size mismatch: expected {$object->size}, got {$sourceSize}.");
        }

        $simpleMd5 = $encrypted ? null : $this->simpleEtagMd5($object->etag);
        if ($simpleMd5 !== null && strtolower($sourceMd5) !== $simpleMd5) {
            throw new \RuntimeException('Tier transition copy MD5 mismatch.');
        }
    }

    private function simpleEtagMd5(string $etag): ?string
    {
        $normalized = trim($etag, '"');

        if (preg_match('/^[a-f0-9]{32}$/i', $normalized) !== 1) {
            return null;
        }

        return strtolower($normalized);
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
        $nextAttemptAt = $deadLetter ? null : microtime(true) + $this->retryDelaySeconds($attempts + 1);
        $bucketOwner = $this->metadata->getBucketOwner($this->stringJobValue($job, 'bucket'));

        try {
            $updated = $this->metadata->transaction(function () use ($job, $id, $status, $nextAttemptAt, $deadLetter, $e, $bucketOwner): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }

                if (($this->metadata->getTierTransitionJob($id)['status'] ?? null) !== 'processing') {
                    return false;
                }

                $this->metadata->updateTierTransitionJobStatus(
                    id: $id,
                    status: $status,
                    error: $e->getMessage(),
                    nextAttemptAt: $nextAttemptAt,
                );

                $object = $this->objectForJob($job);
                if (
                    $object !== null
                    && ! $object->isDeleteMarker
                    && $this->objectStillMatchesJobSource(
                        $object,
                        $job,
                        $this->stringJobValue($job, 'sourceStoragePath'),
                    )
                ) {
                    $this->metadata->updateObjectPlacement(
                        bucket: $object->bucket,
                        key: $object->key,
                        versionId: $object->versionId,
                        storageClass: $object->storageClass,
                        storageTier: $object->storageTier,
                        storagePath: $object->systemMetadata['storagePath'] ?? $this->stringJobValue($job, 'sourceStoragePath'),
                        transitionStatus: $deadLetter ? 'failed' : 'pending',
                        transitionTargetTier: $this->stringJobValue($job, 'targetTier'),
                        transitionError: $e->getMessage(),
                    );
                }

                return true;
            });
            if (! $updated) {
                $this->logger->info('Tier transition result ignored after processing lease was lost.', [
                    'job_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                return 'completed';
            }
        } catch (\Throwable) {
            // The queue state is authoritative for retry/dead-letter handling.
        }

        $this->logger->warning('Tier transition job failed.', [
            'job_id' => $id,
            'status' => $status,
            'attempts' => $attempts + 1,
            'max_attempts' => $maxAttempts,
            'error' => $e->getMessage(),
        ]);

        return $deadLetter ? 'deadLetter' : 'retried';
    }

    private function retryDelaySeconds(int $attempt): int
    {
        return min(300, 2 ** min(8, max(0, $attempt - 1)));
    }

    /** @param array<string, mixed> $job */
    private function stringJobValue(array $job, string $key): string
    {
        return (string) ($job[$key] ?? '');
    }
}
