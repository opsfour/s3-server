<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\QueueLease;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Xml\XmlRequestParser;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RestoreExecutor
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $tiers,
        LoggerInterface $logger = new NullLogger(),
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly float $leaseRenewalIntervalSeconds = 100.0,
        private readonly ?LocalFencedBackgroundObjectWriter $localFencedWriter = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if (! is_finite($this->leaseRenewalIntervalSeconds)
            || $this->leaseRenewalIntervalSeconds <= 0.0
            || $this->leaseRenewalIntervalSeconds >= QueueLease::DURATION_SECONDS / 2) {
            throw new \InvalidArgumentException('Restore lease renewal interval must fit within its lease.');
        }
        if ($this->localFencedWriter !== null && ! $this->metadata instanceof ObjectWriteIntentStore) {
            throw new \InvalidArgumentException('Fenced restore requires an object write intent metadata store.');
        }
    }

    /**
     * Counters describe this worker's claimed attempts, not authoritative SQL
     * queue status. "completed" also includes stale or lost-lease claims.
     * Inspect the durable job row to determine whether a restore committed.
     *
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
     * @param  array<string, mixed>  $job
     * @return 'completed'|'retried'|'deadLetter'
     */
    public function processDequeuedJob(array $job): string
    {
        $id = (int) $job['id'];
        $targetBackend = null;
        $targetTierName = '';
        $write = null;
        $metadataCommitted = false;
        $fencedTarget = false;
        $dispatchToken = null;
        $targetBucket = null;
        $leaseExpiresAt = (float) ($job['nextAttemptAt'] ?? 0);

        try {
            $restoreDays = $job['restoreDays'] ?? null;
            if (! is_int($restoreDays) || $restoreDays < 1 || $restoreDays > XmlRequestParser::MAX_RESTORE_DAYS) {
                throw new \UnexpectedValueException('Restore job Days are outside the supported range.');
            }
            $bucketOwner = $this->metadata->getBucketOwner((string) ($job['bucket'] ?? ''));
            $prepared = $this->prepareJob($job, $bucketOwner, $leaseExpiresAt);
            if ($prepared === null) {
                return 'completed';
            }
            [$object, $targetBucket] = $prepared;

            $sourceTier = (string) $job['sourceTier'];
            if (! $this->tiers->has($sourceTier)) {
                return $this->retryOrDeadLetter(
                    $job,
                    new \RuntimeException("Storage tier {$sourceTier} is not configured on this worker."),
                    $leaseExpiresAt,
                    consumeAttempt: false,
                );
            }

            $heartbeat = new QueueLeaseHeartbeat(
                function (float $expiresAt) use ($job, &$leaseExpiresAt): bool {
                    return $this->renewOwnedLease($job, $leaseExpiresAt, $expiresAt);
                },
                $this->leaseRenewalIntervalSeconds,
            );
            $sourceStoragePath = (string) $job['sourceStoragePath'];
            $sourceBackend = $this->tiers->tier($sourceTier)->backend;
            $targetTier = $this->tiers->defaultTier();
            $targetBackend = $targetTier->backend;
            $targetTierName = $targetTier->name;
            if (! $this->metadata instanceof RestoredCopyTierStore) {
                throw new \RuntimeException('Restore requires tier-aware metadata persistence.');
            }
            $tierStore = $this->metadata;
            $sourceSize = 0;
            $sourceMd5 = hash_init('md5');
            $sourceChecksums = StoredObjectChecksumVerifier::shouldVerify($object) ? new ChecksumCalculator() : null;
            $sourceStream = null;
            $writeFailure = null;
            try {
                $sourceStream = new TeeReadableStream(
                    $sourceBackend->getObjectByPath($sourceStoragePath),
                    static function (string $chunk) use (&$sourceSize, $sourceMd5, $sourceChecksums): void {
                        $sourceSize += strlen($chunk);
                        hash_update($sourceMd5, $chunk);
                        $sourceChecksums?->update($chunk);
                    },
                );
                if ($this->localFencedWriter !== null && $this->localFencedWriter->isFilesystemTarget($targetBackend)) {
                    $fencedTarget = true;
                    [$write, $dispatchToken] = $this->localFencedWriter->publish(
                        $object,
                        $targetBucket,
                        $targetBackend,
                        $targetTierName,
                        $sourceStream,
                        'restore_copy',
                    );
                } else {
                    $write = $targetBackend->putObject($object->bucket, $object->key, $sourceStream);
                }
            } catch (\Throwable $e) {
                $writeFailure = $e;
            } finally {
                $cleanupFailure = null;
                try {
                    $sourceStream?->close();
                } catch (\Throwable $e) {
                    $cleanupFailure = $e;
                }
                try {
                    $heartbeat->stop();
                } catch (\Throwable $e) {
                    $cleanupFailure ??= $e;
                }
                if ($writeFailure === null && $cleanupFailure !== null) {
                    $writeFailure = $cleanupFailure;
                }
            }
            if ($writeFailure !== null) {
                throw $writeFailure;
            }

            $sourceMd5Hex = hash_final($sourceMd5);
            if ($write->size !== $sourceSize || strtolower($write->md5Hex) !== strtolower($sourceMd5Hex)) {
                throw new \RuntimeException('Restore physical copy verification failed.');
            }
            // AES-GCM keeps the authentication tag in metadata; ciphertext
            // length must still equal the committed plaintext length.
            if ($sourceSize !== $object->size) {
                throw new \RuntimeException('Restore source size does not match object metadata.');
            }
            if ($sourceChecksums !== null) {
                StoredObjectChecksumVerifier::assertMatches(
                    $object,
                    $sourceChecksums->finalize(),
                    'Restore source checksum does not match object metadata.',
                );
            }
            if (! isset($object->userMetadata['__sse-algorithm'])) {
                $etag = trim($object->etag, '"');
                if (preg_match('/^[a-f0-9]{32}$/i', $etag) === 1 && strtolower($sourceMd5Hex) !== strtolower($etag)) {
                    throw new \RuntimeException('Restore source MD5 does not match object ETag.');
                }
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
                    'sizeKnown' => true,
                    'sourceTier' => (string) $job['sourceTier'],
                    'restoreDays' => max(1, (int) $job['restoreDays']),
                    'restoreExpiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                ],
                bucketGeneration: $targetBucket->generation,
            ) ?? new S3Event(
                name: 's3:ObjectRestore:Completed',
                bucket: $object->bucket,
                key: $object->key,
                size: $object->size,
                etag: $object->etag,
                ownerId: $object->ownerId,
                attributes: [
                    'versionId' => $object->versionId,
                    'sizeKnown' => true,
                    'sourceTier' => (string) $job['sourceTier'],
                    'restoreDays' => max(1, (int) $job['restoreDays']),
                    'restoreExpiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
                ],
                bucketGeneration: $targetBucket->generation,
            );

            $metadataCommitted = $this->metadata->transaction(function () use ($id, $job, $object, $sourceTier, $sourceStoragePath, $write, $targetTierName, $tierStore, $expiresAt, $event, $bucketOwner, $targetBucket, $dispatchToken, &$leaseExpiresAt): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }
                if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                    return false;
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
                    $this->updateOwnedJobStatus(
                        job: $job,
                        status: 'completed',
                        error: 'Object changed while restore data was being copied.',
                        incrementAttempts: false,
                        expectedLeaseExpiresAt: $leaseExpiresAt,
                    );

                    return false;
                }

                $currentBucket = $this->metadata->getBucket($object->bucket);
                if ($currentBucket === null || $currentBucket->generation !== $targetBucket->generation
                    || $currentBucket->ownerId !== $targetBucket->ownerId) {
                    throw new \RuntimeException('Restore target bucket changed before metadata commit.');
                }

                if (! $this->updateOwnedJobStatus(
                    job: $job,
                    status: 'completed',
                    incrementAttempts: false,
                    restoredStoragePath: $write->path,
                    expectedLeaseExpiresAt: $leaseExpiresAt,
                )) {
                    return false;
                }
                $tierStore->updateObjectRestoreStateWithTier(
                    bucket: $current->bucket,
                    key: $current->key,
                    versionId: $current->versionId ?? 'null',
                    restoreStatus: 'restored',
                    restoredStoragePath: $write->path,
                    restoreExpiresAt: $expiresAt,
                    restoredStorageTier: $targetTierName,
                );
                $this->notifications?->enqueueWebhooks($event);
                if ($dispatchToken !== null && (! $this->metadata instanceof ObjectWriteIntentStore
                    || ! $this->metadata->consumeArmedObjectWriteIntent($dispatchToken->attemptId, $dispatchToken->dispatchId))) {
                    throw new \RuntimeException('Restore local write lost its publication lease.');
                }

                return true;
            });

            if (! $metadataCommitted) {
                if (! $fencedTarget) {
                    $this->cleanTargetIfUnreferenced($job, $targetBackend, $targetTierName, $write->path);
                }

                return 'completed';
            }

            $this->notifications?->dispatchInternalEvent($event);

            return 'completed';
        } catch (CancelledException $e) {
            if (! $fencedTarget && ! $metadataCommitted && $targetBackend !== null && $write !== null) {
                $this->cleanTargetIfUnreferenced($job, $targetBackend, $targetTierName, $write->path);
            }

            throw $e;
        } catch (\Throwable $e) {
            if (! $fencedTarget && ! $metadataCommitted && $targetBackend !== null && $write !== null) {
                $this->cleanTargetIfUnreferenced($job, $targetBackend, $targetTierName, $write->path);
            }

            return $this->retryOrDeadLetter($job, $e, $leaseExpiresAt);
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
     * @param  array<string, mixed>  $job
     * @return array{ObjectInfo, BucketInfo}|null
     */
    private function prepareJob(array $job, ?string $bucketOwner, float &$leaseExpiresAt): ?array
    {
        return $this->metadata->transaction(function () use ($job, $bucketOwner, &$leaseExpiresAt): ?array {
            if ($bucketOwner !== null) {
                OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            }
            if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                return null;
            }

            $object = $this->objectForJob($job);
            $bucket = $this->metadata->getBucket((string) $job['bucket']);
            if ($object === null || $object->isDeleteMarker || $bucketOwner === null
                || $bucket === null || $bucket->ownerId !== $bucketOwner) {
                $this->completeStaleJob($job, 'Object metadata no longer exists for restore job.', $leaseExpiresAt);

                return null;
            }

            $sourceTier = (string) $job['sourceTier'];
            $sourceStoragePath = (string) $job['sourceStoragePath'];
            if ($object->storageTier !== $sourceTier || ($object->systemMetadata['storagePath'] ?? null) !== $sourceStoragePath) {
                $this->completeStaleJob($job, 'Object placement changed before restore job was processed.', $leaseExpiresAt);

                return null;
            }

            return [$object, $bucket];
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
     * @param  array<string, mixed>  $job
     */
    private function cleanTargetIfUnreferenced(array $job, StorageBackend $targetBackend, string $targetTierName, string $path): void
    {
        try {
            $mayBeReferenced = $this->metadata->transaction(function () use ($job, $targetTierName, $path): bool {
                $bucket = (string) ($job['bucket'] ?? '');
                $key = (string) ($job['key'] ?? '');
                $owner = $this->metadata->getBucketOwner($bucket);
                if ($owner === null) {
                    throw new \RuntimeException('Restore owner is unavailable during target reference probe.');
                }
                OwnerWriteLock::acquire($this->metadata, $owner);

                $versionId = $job['versionId'] ?? null;
                $version = is_string($versionId) && $versionId !== '' ? $versionId : 'null';
                $objects = [
                    $this->metadata->getObjectMetadataByVersion($bucket, $key, $version),
                    $this->metadata->getObjectMetadata($bucket, $key),
                ];
                foreach ($objects as $object) {
                    if (
                        $object !== null
                        && $object->restoredStoragePath === $path
                        && ($object->restoredStorageTier === null || $object->restoredStorageTier === $targetTierName)
                    ) {
                        return true;
                    }
                }

                return false;
            });
        } catch (\Throwable $probeError) {
            // A lost commit acknowledgement can leave this path published. A
            // failed metadata probe cannot establish that deletion is safe.
            $this->logger->critical('Restore target reference could not be verified; physical copy retained for recovery.', [
                'job_id' => (int) $job['id'],
                'storage_path_hash' => hash('sha256', $path),
                'exception' => $probeError::class,
            ]);

            return;
        }

        if (! $mayBeReferenced) {
            $this->cleanTarget($job, $targetBackend, $targetTierName, $path);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function cleanTarget(array $job, StorageBackend $targetBackend, string $targetTierName, string $path): void
    {
        try {
            DurableStorageDelete::run(
                $this->metadata,
                $targetBackend,
                (string) ($job['bucket'] ?? ''),
                $targetTierName,
                $path,
            );
        } catch (\Throwable $cleanupError) {
            $this->logger->critical('Restore target cleanup could not be persisted.', [
                'job_id' => (int) $job['id'],
                'storage_path_hash' => hash('sha256', $path),
                'exception' => $cleanupError::class,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @return 'completed'
     */
    private function completeStaleJob(array $job, string $reason, float $leaseExpiresAt): string
    {
        $this->updateOwnedJobStatus(
            job: $job,
            status: 'completed',
            incrementAttempts: false,
            error: $reason,
            expectedLeaseExpiresAt: $leaseExpiresAt,
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
     * @param  array<string, mixed>  $job
     * @return 'completed'|'retried'|'deadLetter'
     */
    private function retryOrDeadLetter(array $job, \Throwable $e, float &$leaseExpiresAt, bool $consumeAttempt = true): string
    {
        $id = (int) $job['id'];
        $attempts = (int) ($job['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($job['maxAttempts'] ?? 1));
        $deadLetter = $consumeAttempt && $attempts + 1 >= $maxAttempts;
        $status = $deadLetter ? 'dead_letter' : 'pending';
        $nextAttemptAt = $deadLetter ? null : ($this->metadata instanceof MultipartWriteIntentStore
            ? $this->metadata->multipartWriteIntentNow() : microtime(true))
            + ($consumeAttempt ? min(300, 2 ** min(8, $attempts)) : 60);
        $reason = $consumeAttempt ? 'Restore processing failed.' : 'Required storage tier is unavailable.';
        $bucketOwner = $this->metadata->getBucketOwner((string) ($job['bucket'] ?? ''));

        try {
            $updated = $this->metadata->transaction(function () use ($job, $id, $status, $reason, $nextAttemptAt, $deadLetter, $bucketOwner, $consumeAttempt, &$leaseExpiresAt): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }

                if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                    return false;
                }

                if (($this->metadata->getRestoreJob($id)['status'] ?? null) !== 'processing') {
                    return false;
                }

                if (! $this->updateOwnedJobStatus(
                    $job,
                    $status,
                    $reason,
                    $nextAttemptAt,
                    incrementAttempts: $consumeAttempt,
                    expectedLeaseExpiresAt: $leaseExpiresAt,
                )) {
                    return false;
                }
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
                        $object->versionId ?? 'null',
                        'failed',
                    );
                }

                return true;
            });
            if (! $updated) {
                $this->logger->info('Restore result ignored after processing lease was lost.', [
                    'job_id' => $id,
                    'exception' => $e::class,
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
                ]);
            }
        }

        $this->logger->warning($consumeAttempt ? 'Restore job failed.' : 'Restore job deferred; required tier is unavailable on this worker.', [
            'job_id' => $id,
            'status' => $status,
            'attempts' => $attempts + (int) $consumeAttempt,
            'max_attempts' => $maxAttempts,
            'exception' => $e::class,
        ]);

        return $deadLetter ? 'deadLetter' : 'retried';
    }

    /** @param array<string, mixed> $job */
    private function renewOwnedLease(array $job, float &$currentLease, ?float $newLease = null): bool
    {
        $id = (int) $job['id'];
        // An immediate renewal must change the stored value even on drivers
        // that round floating-point timestamps before reporting affected rows.
        $now = $this->metadata instanceof MultipartWriteIntentStore
            ? $this->metadata->multipartWriteIntentNow() : microtime(true);
        $newLease = max(
            $this->metadata instanceof MultipartWriteIntentStore
                ? QueueLease::expiresAt($now) : ($newLease ?? QueueLease::expiresAt($now)),
            $currentLease + 0.01,
        );
        if ($this->metadata instanceof FencedQueueStore && isset($job['leaseGeneration'])) {
            $renewed = $this->metadata->renewRestoreJobLeaseFenced($id, $newLease, $currentLease, (int) $job['leaseGeneration']);
        } else {
            $renewed = $this->metadata->renewRestoreJobLease($id, $newLease, $currentLease);
        }
        if (! $renewed) {
            return false;
        }
        $currentLease = $newLease;

        return true;
    }

    /** @param array<string, mixed> $job */
    private function updateOwnedJobStatus(
        array $job,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        $id = (int) $job['id'];
        if ($expectedLeaseExpiresAt !== null && $this->metadata instanceof FencedQueueStore && isset($job['leaseGeneration'])) {
            return $this->metadata->updateRestoreJobStatusLease(
                $id,
                $status,
                $error,
                $nextAttemptAt,
                $incrementAttempts,
                $restoredStoragePath,
                $expectedLeaseExpiresAt,
                (int) $job['leaseGeneration'],
            );
        }

        return $this->metadata->updateRestoreJobStatus(
            $id,
            $status,
            $error,
            $nextAttemptAt,
            $incrementAttempts,
            $restoredStoragePath,
            $expectedLeaseExpiresAt,
        );
    }
}
