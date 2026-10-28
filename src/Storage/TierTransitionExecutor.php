<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Lifecycle\LifecycleRuleValidator;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\QueueLease;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes durable physical storage-tier transition jobs.
 */
final class TierTransitionExecutor
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $tiers,
        LoggerInterface $logger = new NullLogger(),
        private readonly bool $deleteSourceAfterCommit = true,
        private readonly float $leaseRenewalIntervalSeconds = 100.0,
        private readonly ?LocalFencedBackgroundObjectWriter $localFencedWriter = null,
        private readonly ?AwsS3FencedBackgroundObjectWriter $awsFencedWriter = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if (! is_finite($this->leaseRenewalIntervalSeconds)
            || $this->leaseRenewalIntervalSeconds <= 0.0
            || $this->leaseRenewalIntervalSeconds >= QueueLease::DURATION_SECONDS / 2) {
            throw new \InvalidArgumentException('Tier transition lease renewal interval must fit within its lease.');
        }
        if (($this->localFencedWriter !== null || $this->awsFencedWriter !== null)
            && ! $this->metadata instanceof ObjectWriteIntentStore) {
            throw new \InvalidArgumentException('Fenced tier transition requires an object write intent metadata store.');
        }
    }

    /**
     * Counters describe this worker's claimed attempts, not authoritative SQL
     * queue status. "completed" also includes stale or lost-lease claims.
     * Inspect the durable job row to determine whether a transition committed.
     *
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
     * @param  array<string, mixed>  $job
     * @return 'completed'|'retried'|'deadLetter'
     */
    public function processDequeuedJob(array $job): string
    {
        $id = (int) $job['id'];
        $targetBackend = null;
        $targetTier = null;
        $writeResult = null;
        $metadataCommitted = false;
        $publicationCallbackCompleted = false;
        $fencedTarget = false;
        $dispatchToken = null;
        $targetBucket = null;
        $awsReservation = null;
        $awsSource = false;
        $leaseExpiresAt = (float) ($job['nextAttemptAt'] ?? 0);
        $cleanupOwnerId = null;

        try {
            $targetTier = $this->stringJobValue($job, 'targetTier');
            $targetStorageClass = $this->stringJobValue($job, 'targetStorageClass');
            $sourcePath = $this->stringJobValue($job, 'sourceStoragePath');
            $bucketOwner = $this->metadata->getBucketOwner($this->stringJobValue($job, 'bucket'));
            $object = $this->prepareJob($job, $sourcePath, $targetTier, $bucketOwner, $leaseExpiresAt);
            if ($object === null) {
                return 'completed';
            }
            $cleanupOwnerId = $object->ownerId;

            $sourceTier = $this->stringJobValue($job, 'sourceTier');
            if (! $this->tiers->has($sourceTier) || ! $this->tiers->has($targetTier)) {
                $missingTier = ! $this->tiers->has($sourceTier) ? $sourceTier : $targetTier;

                return $this->retryOrDeadLetter(
                    $job,
                    new \RuntimeException("Storage tier {$missingTier} is not configured on this worker."),
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
            $sourceBackend = $this->tiers->tier($sourceTier)->backend;
            $targetBackend = $this->tiers->tier($targetTier)->backend;
            $awsSource = $this->awsFencedWriter?->isBoundTier($sourceBackend, $sourceTier) ?? false;
            $awsTarget = $this->awsFencedWriter?->isBoundTier($targetBackend, $targetTier) ?? false;
            if ($awsSource || $awsTarget) {
                $targetBucket = $this->metadata->getBucket($object->bucket);
                if ($targetBucket === null) {
                    throw new \RuntimeException('Transition bucket disappeared before AWS publication or source read.');
                }
            }
            if ($awsTarget) {
                $fencedTarget = true;
                $awsReservation = $this->awsFencedWriter?->reserve(
                    $object,
                    $targetBucket ?? throw new \LogicException('AWS target bucket snapshot is unavailable.'),
                    $targetBackend,
                    $targetTier,
                    'tier_transition',
                );
            }
            $sourceSize = 0;
            $sourceMd5 = hash_init('md5');
            $sourceChecksums = StoredObjectChecksumVerifier::shouldVerify($object) ? new ChecksumCalculator() : null;
            $sourceStream = null;
            $writeFailure = null;
            try {
                $rawSource = $awsSource
                    ? $this->awsFencedWriter?->readCommittedSource(
                        $targetBucket ?? throw new \LogicException('AWS source bucket snapshot is unavailable.'),
                        $object,
                        $sourceBackend,
                        $sourceTier,
                        $sourcePath,
                    ) ?? throw new \LogicException('AWS source reader disappeared.')
                    : $sourceBackend->getObjectByPath($sourcePath);
                $sourceStream = new TeeReadableStream(
                    $rawSource,
                    static function (string $chunk) use (&$sourceSize, $sourceMd5, $sourceChecksums): void {
                        $sourceSize += strlen($chunk);
                        hash_update($sourceMd5, $chunk);
                        $sourceChecksums?->update($chunk);
                    },
                );
                if ($awsReservation !== null) {
                    [$writeResult, $dispatchToken] = $this->awsFencedWriter?->publishReserved(
                        $awsReservation,
                        $object,
                        $sourceStream,
                    ) ?? throw new \LogicException('AWS background writer disappeared.');
                } elseif ($this->localFencedWriter !== null && $this->localFencedWriter->isFilesystemTarget($targetBackend)) {
                    $fencedTarget = true;
                    $targetBucket = $this->metadata->getBucket($object->bucket);
                    if ($targetBucket === null) {
                        throw new \RuntimeException('Transition target bucket disappeared before local publication.');
                    }
                    [$writeResult, $dispatchToken] = $this->localFencedWriter->publish(
                        $object,
                        $targetBucket,
                        $targetBackend,
                        $targetTier,
                        $sourceStream,
                        'tier_transition',
                    );
                } else {
                    $writeResult = $targetBackend->putObject($object->bucket, $object->key, $sourceStream);
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
                if ($awsReservation !== null && $dispatchToken === null) {
                    try {
                        $this->awsFencedWriter?->retireBeforeDispatch($awsReservation);
                    } catch (\Throwable) {
                        // Armed or uncertain attempts remain durable for reconciliation.
                    }
                }
                throw $writeFailure;
            }

            $this->verifyCopy($object, $writeResult, $sourceSize, hash_final($sourceMd5));
            if ($sourceChecksums !== null) {
                StoredObjectChecksumVerifier::assertMatches(
                    $object,
                    $sourceChecksums->finalize(),
                    'Tier transition source checksum does not match object metadata.',
                );
            }

            $metadataCommitted = $this->metadata->transaction(function () use ($id, $job, $object, $sourcePath, $targetStorageClass, $targetTier, $writeResult, $bucketOwner, $targetBucket, $dispatchToken, &$leaseExpiresAt, &$publicationCallbackCompleted): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }
                if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                    return false;
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
                    $this->updateOwnedJobStatus(
                        job: $job,
                        status: 'completed',
                        incrementAttempts: false,
                        error: 'Object changed while tier transition data was being copied.',
                        expectedLeaseExpiresAt: $leaseExpiresAt,
                    );

                    return false;
                }
                if (! $this->lifecycleJobStillApplies($job, $current)) {
                    $this->cancelStaleJob($job, $current, 'Lifecycle transition no longer applies after copy.', $leaseExpiresAt);

                    return false;
                }

                if ($dispatchToken !== null) {
                    $currentBucket = $this->metadata->getBucket($object->bucket);
                    if ($targetBucket === null || $currentBucket?->generation !== $targetBucket->generation
                    ) {
                        throw new \RuntimeException('Transition local write lost its bucket generation.');
                    }
                }

                if (! $this->updateOwnedJobStatus(
                    job: $job,
                    status: 'completed',
                    incrementAttempts: false,
                    targetStoragePath: $writeResult->path,
                    expectedLeaseExpiresAt: $leaseExpiresAt,
                )) {
                    return false;
                }
                $this->metadata->updateObjectPlacement(
                    bucket: $current->bucket,
                    key: $current->key,
                    versionId: $job['versionId'] ?? $current->versionId,
                    storageClass: $targetStorageClass,
                    storageTier: $targetTier,
                    storagePath: $writeResult->path,
                );
                if ($current->restoreStatus === 'pending') {
                    $this->metadata->updateObjectRestoreState(
                        bucket: $current->bucket,
                        key: $current->key,
                        versionId: $job['versionId'] ?? $current->versionId,
                        restoreStatus: null,
                    );
                }
                $sourceTier = $this->stringJobValue($job, 'sourceTier');
                if ($this->deleteSourceAfterCommit && ($sourceTier !== $targetTier || $sourcePath !== $writeResult->path)) {
                    // Keep a durable exact-path reference if the process dies
                    // after this placement commits but before source deletion.
                    $this->metadata->enqueueStorageGarbage($current->bucket, $sourceTier, $sourcePath, $current->ownerId);
                }
                if ($dispatchToken !== null && (! $this->metadata instanceof ObjectWriteIntentStore
                    || ! $this->metadata->consumeArmedObjectWriteIntent($dispatchToken->attemptId, $dispatchToken->dispatchId))) {
                    throw new \RuntimeException('Transition local write lost its publication lease.');
                }
                $publicationCallbackCompleted = true;

                return true;
            });

            if (! $metadataCommitted) {
                if (! $fencedTarget) {
                    $this->cleanTarget($job, $targetBackend, $writeResult->path, $cleanupOwnerId);
                }

                return 'completed';
            }

            if (
                $this->deleteSourceAfterCommit
                && ! $awsSource
                && ($this->stringJobValue($job, 'sourceTier') !== $targetTier || $sourcePath !== $writeResult->path)
            ) {
                try {
                    StorageGarbageDiscard::afterDelete(
                        $this->metadata,
                        $sourceBackend,
                        $object->bucket,
                        $this->stringJobValue($job, 'sourceTier'),
                        $sourcePath,
                    );
                } catch (\Throwable $e) {
                    if ($e instanceof CancelledException) {
                        throw $e;
                    }
                    $this->logger->warning('Tier transition source cleanup failed.', [
                        'job_id' => $id,
                        'bucket' => $object->bucket,
                        'key' => $object->key,
                        'source_tier' => $this->stringJobValue($job, 'sourceTier'),
                        'source_storage_path_hash' => hash('sha256', $sourcePath),
                        'exception' => $e::class,
                    ]);
                }
            }

            return 'completed';
        } catch (CancelledException $e) {
            if (! $fencedTarget && ! $metadataCommitted && $targetBackend !== null && $writeResult !== null
                && (! $publicationCallbackCompleted || $targetTier === null
                    || ! $this->targetMayBePublished($job, $targetTier, $writeResult->path))) {
                $this->cleanTarget($job, $targetBackend, $writeResult->path, $cleanupOwnerId);
            }

            throw $e;
        } catch (\Throwable $e) {
            if (! $fencedTarget && ! $metadataCommitted && $targetBackend !== null && $writeResult !== null) {
                if ($publicationCallbackCompleted && $targetTier !== null && $this->targetMayBePublished($job, $targetTier, $writeResult->path)) {
                    // A lost COMMIT acknowledgement cannot authorize removal
                    // of a path that metadata may already reference.
                    return 'completed';
                }
                $this->cleanTarget($job, $targetBackend, $writeResult->path, $cleanupOwnerId);
            }

            return $this->retryOrDeadLetter($job, $e, $leaseExpiresAt);
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
     * @param  array<string, mixed>  $job
     */
    private function prepareJob(
        array $job,
        string $sourcePath,
        string $targetTier,
        ?string $bucketOwner,
        float &$leaseExpiresAt,
    ): ?ObjectInfo {
        return $this->metadata->transaction(function () use ($job, $sourcePath, $targetTier, $bucketOwner, &$leaseExpiresAt): ?ObjectInfo {
            if ($bucketOwner !== null) {
                OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            }
            if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                return null;
            }

            $object = $this->objectForJob($job);
            if ($object === null || $object->isDeleteMarker || $bucketOwner === null) {
                $this->completeStaleJob($job, 'Object metadata no longer exists for tier transition job.', $leaseExpiresAt);

                return null;
            }
            if (! $this->lifecycleJobStillApplies($job, $object)) {
                $this->cancelStaleJob($job, $object, 'Lifecycle transition no longer applies before copy.', $leaseExpiresAt);

                return null;
            }
            if ($this->isAlreadyTransitioned($object, $job)) {
                $this->updateOwnedJobStatus(
                    job: $job,
                    status: 'completed',
                    incrementAttempts: false,
                    targetStoragePath: $object->systemMetadata['storagePath'] ?? null,
                    expectedLeaseExpiresAt: $leaseExpiresAt,
                );

                return null;
            }
            if (! $this->objectStillMatchesJobSource($object, $job, $sourcePath)) {
                $this->completeStaleJob($job, 'Object placement changed before tier transition job was processed.', $leaseExpiresAt);

                return null;
            }

            $this->metadata->updateObjectPlacement(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $job['versionId'] ?? $object->versionId,
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

    /** @param array<string, mixed> $job */
    private function cancelStaleJob(array $job, ObjectInfo $object, string $reason, float $leaseExpiresAt): void
    {
        if (! $this->updateOwnedJobStatus(
            job: $job,
            status: 'completed',
            incrementAttempts: false,
            error: $reason,
            expectedLeaseExpiresAt: $leaseExpiresAt,
        )) {
            return;
        }
        $sourcePath = $this->stringJobValue($job, 'sourceStoragePath');
        if (
            $this->objectStillMatchesJobSource($object, $job, $sourcePath)
            && in_array($object->transitionStatus, ['pending', 'processing'], true)
            && $object->transitionTargetTier === $this->stringJobValue($job, 'targetTier')
        ) {
            $this->metadata->updateObjectPlacement(
                $object->bucket,
                $object->key,
                $job['versionId'] ?? $object->versionId,
                $object->storageClass,
                $object->storageTier,
                $sourcePath,
            );
        }
    }

    /** @param array<string, mixed> $job */
    private function lifecycleJobStillApplies(array $job, ObjectInfo $object): bool
    {
        $origin = $job['origin'] ?? 'legacy';
        if ($origin === 'direct') {
            return true;
        }
        if ($origin !== 'lifecycle' || ! is_string($job['lifecycleRuleId'] ?? null) || ! is_bool($job['expectedIsLatest'] ?? null)) {
            return false;
        }

        $expectedLatest = $job['expectedIsLatest'];
        if (($object->systemMetadata['isLatest'] ?? null) !== ($expectedLatest ? '1' : '0')) {
            return false;
        }
        $ageReference = $object->systemMetadata[$expectedLatest ? 'createdAt' : 'noncurrentAt'] ?? null;
        if (! is_string($ageReference)) {
            return false;
        }
        try {
            $created = new \DateTimeImmutable($ageReference);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return false;
        }

        foreach ($this->metadata->getBucketLifecycle($object->bucket) as $rule) {
            if (($rule['id'] ?? null) !== $job['lifecycleRuleId'] || ($rule['status'] ?? null) !== 'Enabled') {
                continue;
            }
            try {
                LifecycleRuleValidator::assertValid($rule);
            } catch (\UnexpectedValueException) {
                return false;
            }
            $prefix = $rule['prefix'] ?? $rule['filter']['prefix'] ?? $rule['filter']['and']['prefix'] ?? null;
            if (is_string($prefix) && ! str_starts_with($object->key, $prefix)) {
                return false;
            }
            if (! $this->lifecycleTagsMatch($object, $rule)) {
                return false;
            }
            $transitions = $rule[$expectedLatest ? 'transitions' : 'noncurrentTransitions'] ?? [];
            if (! is_array($transitions)) {
                return false;
            }
            foreach ($transitions as $transition) {
                if (! is_array($transition) || ($transition['storageClass'] ?? null) !== $job['targetStorageClass']) {
                    continue;
                }
                if (! $expectedLatest && isset($transition['noncurrentDays'])) {
                    $days = $transition['noncurrentDays'];
                    if ($created < $now->modify("-{$days} days")) {
                        return true;
                    }

                    continue;
                }
                if ($expectedLatest && isset($transition['days'])) {
                    $days = $transition['days'];
                    if ($created < $now->modify("-{$days} days")) {
                        return true;
                    }

                    continue;
                }
                if ($expectedLatest && isset($transition['date'])) {
                    try {
                        if ($now >= new \DateTimeImmutable((string) $transition['date']) && $created < $now) {
                            return true;
                        }
                    } catch (\Throwable) {
                        return false;
                    }
                }
            }

            return false;
        }

        return false;
    }

    /** @param array<string, mixed> $rule */
    private function lifecycleTagsMatch(ObjectInfo $object, array $rule): bool
    {
        $filter = $rule['filter'] ?? null;
        if (! is_array($filter)) {
            return true;
        }
        $required = [];
        if (isset($filter['tag']['key'], $filter['tag']['value'])) {
            $required[(string) $filter['tag']['key']] = (string) $filter['tag']['value'];
        }
        foreach ($filter['and']['tags'] ?? [] as $tag) {
            if (isset($tag['key'], $tag['value'])) {
                $required[(string) $tag['key']] = (string) $tag['value'];
            }
        }
        if ($required === []) {
            return true;
        }
        $actual = [];
        foreach ($this->metadata->getObjectTagging($object->bucket, $object->key, $object->versionId) as $tag) {
            $actual[$tag['key']] = $tag['value'];
        }
        foreach ($required as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function sameObject(ObjectInfo $left, ObjectInfo $right): bool
    {
        return $left->bucket === $right->bucket
            && $left->key === $right->key
            && $left->versionId === $right->versionId
            && $left->ownerId === $right->ownerId
            && $left->etag === $right->etag
            && $left->size === $right->size
            && ($left->systemMetadata['storagePath'] ?? null) === ($right->systemMetadata['storagePath'] ?? null)
            && $left->partLayout?->digest === $right->partLayout?->digest;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function cleanTarget(array $job, StorageBackend $targetBackend, string $path, ?string $ownerId): void
    {
        try {
            DurableStorageDelete::run(
                $this->metadata,
                $targetBackend,
                $this->stringJobValue($job, 'bucket'),
                $this->stringJobValue($job, 'targetTier'),
                $path,
                'tier_transition_target_cleanup',
                $ownerId,
            );
        } catch (\Throwable $cleanupError) {
            $this->logger->critical('Tier transition target cleanup could not be persisted.', [
                'job_id' => (int) $job['id'],
                'storage_path_hash' => hash('sha256', $path),
                'exception' => $cleanupError::class,
            ]);
        }
    }

    /** @param array<string, mixed> $job */
    private function targetMayBePublished(array $job, string $targetTier, string $path): bool
    {
        try {
            $bucket = $this->stringJobValue($job, 'bucket');
            $owner = $this->metadata->getBucketOwner($bucket);

            return $this->metadata->transaction(function () use ($job, $owner, $targetTier, $path): bool {
                if ($owner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $owner);
                }
                $versionId = $job['versionId'] ?? null;
                $objects = is_string($versionId) && $versionId !== ''
                    ? [$this->metadata->getObjectMetadataByVersion(
                        $this->stringJobValue($job, 'bucket'),
                        $this->stringJobValue($job, 'key'),
                        $versionId,
                    )]
                    : [
                        $this->metadata->getObjectMetadataByVersion(
                            $this->stringJobValue($job, 'bucket'),
                            $this->stringJobValue($job, 'key'),
                            'null',
                        ),
                        $this->objectForJob($job),
                    ];
                foreach ($objects as $current) {
                    if ($current !== null
                        && $current->storageTier === $targetTier
                        && ($current->systemMetadata['storagePath'] ?? null) === $path) {
                        return true;
                    }
                }

                return false;
            });
        } catch (\Throwable $probeError) {
            $this->logger->warning('Tier transition publication could not be verified; target retained.', [
                'job_id' => (int) $job['id'],
                'storage_path_hash' => hash('sha256', $path),
                'exception' => $probeError::class,
            ]);

            return true;
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
        // AES-GCM stores its authentication tag separately, so the physical
        // object ciphertext has the same byte length as its plaintext.
        if ($sourceSize !== $object->size) {
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
            + ($consumeAttempt ? $this->retryDelaySeconds($attempts + 1) : 60);
        $reason = $consumeAttempt ? 'Tier transition processing failed.' : 'Required storage tier is unavailable.';
        $bucketOwner = $this->metadata->getBucketOwner($this->stringJobValue($job, 'bucket'));

        try {
            $updated = $this->metadata->transaction(function () use ($job, $id, $status, $nextAttemptAt, $deadLetter, $reason, $bucketOwner, $consumeAttempt, &$leaseExpiresAt): bool {
                if ($bucketOwner !== null) {
                    OwnerWriteLock::acquire($this->metadata, $bucketOwner);
                }

                if (! $this->renewOwnedLease($job, $leaseExpiresAt)) {
                    return false;
                }

                if (($this->metadata->getTierTransitionJob($id)['status'] ?? null) !== 'processing') {
                    return false;
                }

                if (! $this->updateOwnedJobStatus(
                    job: $job,
                    status: $status,
                    error: $reason,
                    nextAttemptAt: $nextAttemptAt,
                    incrementAttempts: $consumeAttempt,
                    expectedLeaseExpiresAt: $leaseExpiresAt,
                )) {
                    return false;
                }

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
                        versionId: $job['versionId'] ?? $object->versionId,
                        storageClass: $object->storageClass,
                        storageTier: $object->storageTier,
                        storagePath: $object->systemMetadata['storagePath'] ?? $this->stringJobValue($job, 'sourceStoragePath'),
                        transitionStatus: $deadLetter ? 'failed' : 'pending',
                        transitionTargetTier: $this->stringJobValue($job, 'targetTier'),
                        transitionError: $reason,
                    );
                }

                return true;
            });
            if (! $updated) {
                $this->logger->info('Tier transition result ignored after processing lease was lost.', [
                    'job_id' => $id,
                    'exception' => $e::class,
                ]);

                return 'completed';
            }
        } catch (\Throwable) {
            // The queue state is authoritative for retry/dead-letter handling.
        }

        $this->logger->warning($consumeAttempt ? 'Tier transition job failed.' : 'Tier transition job deferred; required tier is unavailable on this worker.', [
            'job_id' => $id,
            'status' => $status,
            'attempts' => $attempts + (int) $consumeAttempt,
            'max_attempts' => $maxAttempts,
            'exception' => $e::class,
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
            $renewed = $this->metadata->renewTierTransitionJobLeaseFenced($id, $newLease, $currentLease, (int) $job['leaseGeneration']);
        } else {
            $renewed = $this->metadata->renewTierTransitionJobLease($id, $newLease, $currentLease);
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
        ?string $targetStoragePath = null,
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        $id = (int) $job['id'];
        if ($expectedLeaseExpiresAt !== null && $this->metadata instanceof FencedQueueStore && isset($job['leaseGeneration'])) {
            return $this->metadata->updateTierTransitionJobStatusLease(
                $id,
                $status,
                $error,
                $nextAttemptAt,
                $incrementAttempts,
                $targetStoragePath,
                $expectedLeaseExpiresAt,
                (int) $job['leaseGeneration'],
            );
        }

        return $this->metadata->updateTierTransitionJobStatus(
            $id,
            $status,
            $error,
            $nextAttemptAt,
            $incrementAttempts,
            $targetStoragePath,
            $expectedLeaseExpiresAt,
        );
    }
}
