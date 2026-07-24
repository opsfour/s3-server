<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Executes lifecycle rules for all buckets.
 *
 * Processes expiration, noncurrent version expiration,
 * abort incomplete multipart uploads, and orphaned delete markers.
 */
final class LifecycleExecutor
{
    private readonly string $lockOwnerId;

    private readonly StorageTierRegistry $storageTiers;

    private readonly ObjectLockChecker $objectLockChecker;

    private bool $leaseHeld = false;

    private float $lastLeaseRenewal = 0.0;

    private bool $stopRequested = false;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $batchSize = 1000,
        private readonly int $maxActionsPerRun = 1000,
        private readonly int $lockTtlSeconds = 300,
        ?string $lockOwnerId = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly ?NotificationDispatcher $notifications = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly int $multipartMaxAgeSeconds = 604_800,
    ) {
        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException('Lifecycle batch size must be >= 1.');
        }

        if ($this->maxActionsPerRun < 1) {
            throw new \InvalidArgumentException('Lifecycle max actions per run must be >= 1.');
        }

        if ($this->lockTtlSeconds < 1) {
            throw new \InvalidArgumentException('Lifecycle lock TTL must be >= 1.');
        }
        if ($this->multipartMaxAgeSeconds < 0) {
            throw new \InvalidArgumentException('Multipart maximum age must be >= 0.');
        }

        $this->lockOwnerId = $lockOwnerId ?? 'lifecycle-' . bin2hex(random_bytes(8));
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
        $this->objectLockChecker = new ObjectLockChecker($metadata);
    }

    /**
     * Process lifecycle rules for all buckets.
     */
    public function execute(): void
    {
        $startedAt = hrtime(true);
        $status = 'completed';
        $lockAcquired = false;
        $this->metrics?->startLifecycleSweep();
        $this->logger->info('Lifecycle sweep started.', $this->lifecycleContext([
            'event' => 'sweep_started',
            'lock_owner_id' => $this->lockOwnerId,
            'lock_ttl_seconds' => $this->lockTtlSeconds,
            'batch_size' => $this->batchSize,
            'max_actions_per_run' => $this->maxActionsPerRun,
        ]));

        // Get all buckets (we need a way to iterate — use a sentinel owner or list all).
        // For simplicity, we'll process rules for buckets that have lifecycle configs.
        // This requires iterating all buckets. We'll use a simple approach.
        try {
            $this->renewLeaseIfNeeded();
            $lockAcquired = $this->metadata->acquireLock('lifecycle:global', $this->lockOwnerId, $this->lockTtlSeconds);
            if (!$lockAcquired) {
                $status = 'skipped_lock';
                $this->logger->info('Lifecycle sweep skipped because another node holds the lifecycle lock.', $this->lifecycleContext([
                    'event' => 'sweep_skipped_lock',
                    'status' => $status,
                    'lock_owner_id' => $this->lockOwnerId,
                ]));

            } else {
                $this->leaseHeld = true;
                $this->lastLeaseRenewal = hrtime(true) / 1_000_000_000;
                $this->processAllBuckets();
            }
        } catch (\Throwable $e) {
            if ($this->stopRequested && $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                $status = 'cancelled';
                $this->logger->info('Lifecycle sweep cancelled for shutdown.', $this->lifecycleContext([
                    'event' => 'sweep_cancelled',
                    'status' => $status,
                ]));
            } else {
                $status = 'error';
                $this->logger->error('Lifecycle sweep failed.', $this->lifecycleContext([
                    'event' => 'sweep_failed',
                    'status' => $status,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        } finally {
            $this->leaseHeld = false;
            if ($lockAcquired) {
                try {
                    $this->metadata->releaseLock('lifecycle:global', $this->lockOwnerId);
                } catch (\Throwable $e) {
                    $this->logger->warning('Lifecycle lock release failed.', $this->lifecycleContext([
                        'event' => 'lock_release_failed',
                        'lock_owner_id' => $this->lockOwnerId,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]));
                }
            }

            $this->metrics?->finishLifecycleSweep($status, hrtime(true) - $startedAt);
            $this->logger->info('Lifecycle sweep finished.', $this->lifecycleContext([
                'event' => 'sweep_finished',
                'status' => $status,
                'lock_acquired' => $lockAcquired,
                'duration_ms' => $this->durationMs($startedAt),
            ]));
        }

        // Clean up stale temp files from client disconnects.
        if ($this->storage instanceof FilesystemBackend) {
            try {
                $cleaned = $this->storage->cleanupStaleTempFiles();
                if ($cleaned > 0) {
                    $this->logger->info('Lifecycle temp-file cleanup completed.', $this->lifecycleContext([
                        'event' => 'temp_cleanup_completed',
                        'count' => $cleaned,
                    ]));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Lifecycle temp-file cleanup failed.', $this->lifecycleContext([
                    'event' => 'temp_cleanup_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        }
    }

    /**
     * Process lifecycle rules for a specific bucket.
     */
    public function processBucket(string $bucket): void
    {
        $this->renewLeaseIfNeeded();
        $startedAt = hrtime(true);
        $staleMultipartActions = $this->cleanupStaleMultipartUploads($bucket);
        $rules = $this->metadata->getBucketLifecycle($bucket);
        if ($rules === []) {
            return;
        }

        $remainingActions = max(0, $this->maxActionsPerRun - $staleMultipartActions);
        $appliedActions = $staleMultipartActions;

        $this->logger->debug('Lifecycle bucket processing started.', $this->lifecycleContext([
            'event' => 'bucket_started',
            'bucket' => $bucket,
            'rules' => count($rules),
            'action_budget' => $this->maxActionsPerRun,
        ]));

        foreach ($rules as $rule) {
            if ($remainingActions <= 0) {
                $this->logger->info('Lifecycle bucket action budget exhausted.', $this->lifecycleContext([
                    'event' => 'bucket_budget_exhausted',
                    'bucket' => $bucket,
                    'remaining_actions' => $remainingActions,
                    'applied_actions' => $appliedActions,
                ]));

                break;
            }

            if ($rule['status'] !== 'Enabled') {
                continue;
            }

            $prefix = $this->rulePrefix($rule);
            $ruleStartedAt = hrtime(true);
            $ruleId = $this->ruleId($rule);

            try {
                $this->logger->debug('Lifecycle rule processing started.', $this->lifecycleContext([
                    'event' => 'rule_started',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'action_budget' => $remainingActions,
                ]));
                $ruleActions = $this->processRule($bucket, $rule, $prefix, $remainingActions);
                $remainingActions -= $ruleActions;
                $appliedActions += $ruleActions;
                $this->logger->info('Lifecycle rule processing completed.', $this->lifecycleContext([
                    'event' => 'rule_completed',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'applied_actions' => $ruleActions,
                    'remaining_actions' => $remainingActions,
                    'duration_ms' => $this->durationMs($ruleStartedAt),
                ]));
            } catch (\Throwable $e) {
                if ($e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning('Lifecycle rule processing failed.', $this->lifecycleContext([
                    'event' => 'rule_failed',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        }

        $this->logger->debug('Lifecycle bucket processing completed.', $this->lifecycleContext([
            'event' => 'bucket_completed',
            'bucket' => $bucket,
            'rules' => count($rules),
            'applied_actions' => $appliedActions,
            'remaining_actions' => $remainingActions,
            'duration_ms' => $this->durationMs($startedAt),
        ]));
    }

    /** @param array<string, mixed> $rule */
    private function processRule(string $bucket, array $rule, ?string $prefix, int $actionBudget): int
    {
        $this->renewLeaseIfNeeded();
        $actions = 0;
        $tags = $this->ruleTags($rule);

        if (isset($rule['transitions']) && is_array($rule['transitions']) && $actions < $actionBudget) {
            foreach ($rule['transitions'] as $transition) {
                if ($actions >= $actionBudget || ! is_array($transition) || ! isset($transition['storageClass'])) {
                    continue;
                }

                $action = 'transition_current:' . (string) $transition['storageClass'];
                $checkpoint = $this->checkpoint($bucket, $rule, $action);
                $olderThan = $this->transitionCutoff($transition);
                if ($olderThan === null) {
                    continue;
                }

                $queued = 0;
                do {
                    $limit = min($this->batchSize, $actionBudget - $actions);
                    $objects = $this->metadata->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $checkpoint['cursorKey'] ?? null);

                    if ($objects === []) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($objects as $obj) {
                        $this->saveObjectCheckpoint($bucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                        if (!$this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->enqueueTransitionIfNeeded($obj, (string) $transition['storageClass'])) {
                            $queued++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($objects) < $limit) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    }
                } while (count($objects) === $limit && $actions < $actionBudget);

                $this->logActionCompleted($bucket, $rule, $action, $queued, [
                    'target_storage_class' => (string) $transition['storageClass'],
                ]);
            }
        }

        if (isset($rule['noncurrentTransitions']) && is_array($rule['noncurrentTransitions']) && $actions < $actionBudget) {
            foreach ($rule['noncurrentTransitions'] as $transition) {
                if ($actions >= $actionBudget || ! is_array($transition) || ! isset($transition['storageClass'], $transition['noncurrentDays'])) {
                    continue;
                }

                $action = 'transition_noncurrent:' . (string) $transition['storageClass'];
                $checkpoint = $this->checkpoint($bucket, $rule, $action);
                $queued = 0;

                do {
                    $limit = min($this->batchSize, $actionBudget - $actions);
                    $objects = $this->metadata->listExpiredNoncurrentVersions(
                        $bucket,
                        $prefix,
                        (int) $transition['noncurrentDays'],
                        $limit,
                        $tags,
                        $checkpoint['cursorKey'] ?? null,
                        $checkpoint['cursorVersionId'] ?? null,
                    );

                    if ($objects === []) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($objects as $obj) {
                        $this->saveObjectCheckpoint($bucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                        if (!$this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->enqueueTransitionIfNeeded($obj, (string) $transition['storageClass'])) {
                            $queued++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($objects) < $limit) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    }
                } while (count($objects) === $limit && $actions < $actionBudget);

                $this->logActionCompleted($bucket, $rule, $action, $queued, [
                    'target_storage_class' => (string) $transition['storageClass'],
                    'noncurrent_days' => (int) $transition['noncurrentDays'],
                ]);
            }
        }

        // Expiration by days.
        if (isset($rule['expiration']['days']) && $actions < $actionBudget) {
            $action = 'expire_current';
            $checkpoint = $this->checkpoint($bucket, $rule, $action);
            $days = (int) $rule['expiration']['days'];
            $olderThan = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions);
                $expired = $this->metadata->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $checkpoint['cursorKey'] ?? null);

                if ($expired === []) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($expired as $obj) {
                    $this->saveObjectCheckpoint($bucket, $rule, $action, $obj);
                    $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                    if (!$this->objectMatchesRule($bucket, $obj, $rule)) {
                        continue;
                    }

                    if ($this->deleteObject($bucket, $obj, $rule)) {
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
                    }
                }

                if (count($expired) < $limit) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                }
            } while (count($expired) === $limit && $actions < $actionBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted, [
                'expiration_mode' => 'days',
                'expiration_days' => $days,
            ]);
        }

        // Expiration by date.
        if (isset($rule['expiration']['date']) && $actions < $actionBudget) {
            $action = 'expire_current';
            $checkpoint = $this->checkpoint($bucket, $rule, $action);
            $date = new \DateTimeImmutable($rule['expiration']['date']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($now >= $date) {
                $deleted = 0;
                do {
                    $limit = min($this->batchSize, $actionBudget - $actions);
                    $expired = $this->metadata->listExpiredObjects($bucket, $prefix, $now, $limit, $tags, $checkpoint['cursorKey'] ?? null);

                    if ($expired === []) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($expired as $obj) {
                        $this->saveObjectCheckpoint($bucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                        if (!$this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->deleteObject($bucket, $obj, $rule)) {
                            $deleted++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($expired) < $limit) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    }
                } while (count($expired) === $limit && $actions < $actionBudget);

                $this->logActionCompleted($bucket, $rule, $action, $deleted, [
                    'expiration_mode' => 'date',
                    'expiration_date' => $rule['expiration']['date'],
                ]);
            }
        }

        // NoncurrentVersionExpiration.
        if (isset($rule['noncurrentExpiration']['noncurrentDays']) && $actions < $actionBudget) {
            $action = 'expire_noncurrent';
            $checkpoint = $this->checkpoint($bucket, $rule, $action);
            $noncurrentDays = (int) $rule['noncurrentExpiration']['noncurrentDays'];
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions);
                $expired = $this->metadata->listExpiredNoncurrentVersions(
                    $bucket,
                    $prefix,
                    $noncurrentDays,
                    $limit,
                    $tags,
                    $checkpoint['cursorKey'] ?? null,
                    $checkpoint['cursorVersionId'] ?? null,
                );

                if ($expired === []) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($expired as $obj) {
                    $this->saveObjectCheckpoint($bucket, $rule, $action, $obj);
                    $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                    if (!$this->objectMatchesRule($bucket, $obj, $rule)) {
                        continue;
                    }

                    if ($obj->versionId !== null) {
                        try {
                            if ($this->deleteNoncurrentVersion($bucket, $obj, $rule)) {
                                $deleted++;
                                $actions++;
                                $this->metrics?->recordLifecycleAction($action);
                            }
                        } catch (\OpsFour\S3Server\Exception\ObjectLockedException) {
                            $this->logger->info('Lifecycle retained an Object Lock protected version.', [
                                'bucket' => $bucket,
                                'key' => $obj->key,
                                'version_id' => $obj->versionId,
                            ]);
                        }
                    }
                }

                if (count($expired) < $limit) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                }
            } while (count($expired) === $limit && $actions < $actionBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted);
        }

        // AbortIncompleteMultipartUpload.
        if (isset($rule['abortIncompleteDays']) && $actions < $actionBudget) {
            $action = 'abort_multipart';
            $checkpoint = $this->checkpoint($bucket, $rule, $action);
            $days = (int) $rule['abortIncompleteDays'];
            $aborted = 0;

            if ($tags !== []) {
                $this->logger->info('Lifecycle tag-filtered multipart abort rule skipped.', $this->lifecycleContext([
                    'event' => 'action_skipped',
                    'bucket' => $bucket,
                    'rule_id' => $this->ruleId($rule),
                    'action' => $action,
                    'reason' => 'multipart_upload_tags_not_persisted',
                ]));
            } else {
                do {
                    $limit = min($this->batchSize, $actionBudget - $actions);
                    $expired = $this->metadata->listExpiredMultipartUploads(
                        $bucket,
                        $days,
                        $limit,
                        $prefix,
                        $checkpoint['cursorKey'] ?? null,
                        $checkpoint['cursorUploadId'] ?? null,
                    );

                    if ($expired === []) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($expired as $upload) {
                        $this->saveMultipartCheckpoint($bucket, $rule, $action, $upload['key_name'], $upload['upload_id']);
                        $checkpoint = ['cursorKey' => $upload['key_name'], 'cursorVersionId' => null, 'cursorUploadId' => $upload['upload_id']];

                        try {
                            if ($this->abortMultipartUploadDurably($bucket, $upload['key_name'], $upload['upload_id'])) {
                                $aborted++;
                                $actions++;
                                $this->metrics?->recordLifecycleAction($action);
                            }
                        } catch (\Throwable $e) {
                            $this->logger->warning('Lifecycle failed to abort multipart upload.', $this->lifecycleContext([
                                'event' => 'multipart_abort_failed',
                                'bucket' => $bucket,
                                'key' => $upload['key_name'],
                                'upload_id' => $upload['upload_id'],
                                'action' => $action,
                                'exception' => $e::class,
                                'error' => $e->getMessage(),
                            ]));
                        }
                    }

                    if (count($expired) < $limit) {
                        $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    }
                } while (count($expired) === $limit && $actions < $actionBudget);
            }

            $this->logActionCompleted($bucket, $rule, $action, $aborted);
        }

        // ExpiredObjectDeleteMarker.
        if (isset($rule['expiration']['expiredObjectDeleteMarker']) && $rule['expiration']['expiredObjectDeleteMarker'] && $actions < $actionBudget) {
            $action = 'delete_marker';
            $checkpoint = $this->checkpoint($bucket, $rule, $action);
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions);
                $orphaned = $this->metadata->listOrphanedDeleteMarkers(
                    $bucket,
                    $prefix,
                    $limit,
                    $tags,
                    $checkpoint['cursorKey'] ?? null,
                    $checkpoint['cursorVersionId'] ?? null,
                );

                if ($orphaned === []) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($orphaned as $dm) {
                    $this->saveObjectCheckpoint($bucket, $rule, $action, $dm);
                    $checkpoint = ['cursorKey' => $dm->key, 'cursorVersionId' => $dm->versionId, 'cursorUploadId' => null];

                    if (!$this->objectMatchesRule($bucket, $dm, $rule)) {
                        continue;
                    }

                    if ($dm->versionId !== null) {
                        if ($this->deleteOrphanedMarker($bucket, $dm, $rule)) {
                            $deleted++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }
                }

                if (count($orphaned) < $limit) {
                    $this->clearCheckpointIfPresent($bucket, $rule, $action, $checkpoint);
                }
            } while (count($orphaned) === $limit && $actions < $actionBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted);
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $transition
     */
    private function transitionCutoff(array $transition): ?\DateTimeImmutable
    {
        if (isset($transition['days'])) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . (int) $transition['days'] . ' days');
        }

        if (isset($transition['date'])) {
            $date = new \DateTimeImmutable((string) $transition['date']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return $now >= $date ? $now : null;
        }

        return null;
    }

    private function enqueueTransitionIfNeeded(ObjectInfo $object, string $targetStorageClass): bool
    {
        $targetTier = $targetStorageClass;
        $bucketOwner = $this->metadata->getBucketOwner($object->bucket);

        return $this->metadata->transaction(function () use ($object, $targetStorageClass, $targetTier, $bucketOwner): bool {
            if ($bucketOwner === null) {
                return false;
            }
            OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            $current = $object->versionId !== null
                ? $this->metadata->getObjectMetadataByVersion($object->bucket, $object->key, $object->versionId)
                : $this->metadata->getObjectMetadata($object->bucket, $object->key);
            if ($current === null || ! self::sameObject($current, $object)) {
                return false;
            }

            $sourceStoragePath = $current->systemMetadata['storagePath'] ?? null;
            if ($sourceStoragePath === null || $sourceStoragePath === '') {
                return false;
            }
            if (
                $current->storageClass === $targetStorageClass
                && $current->storageTier === $targetTier
                && $current->transitionStatus === 'available'
            ) {
                return false;
            }
            if (
                in_array($current->transitionStatus, ['pending', 'processing'], true)
                && $current->transitionTargetTier === $targetTier
            ) {
                return false;
            }

            $this->metadata->enqueueTierTransitionJob(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId,
                sourceTier: $current->storageTier,
                targetTier: $targetTier,
                targetStorageClass: $targetStorageClass,
                sourceStoragePath: $sourceStoragePath,
            );
            $this->metadata->updateObjectPlacement(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId,
                storageClass: $current->storageClass,
                storageTier: $current->storageTier,
                storagePath: $sourceStoragePath,
                transitionStatus: 'pending',
                transitionTargetTier: $targetTier,
            );

            return true;
        });
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function deleteObject(string $bucket, ObjectInfo $obj, array $rule): bool
    {
        $bucketOwner = $this->metadata->getBucketOwner($bucket);
        if ($bucketOwner === null) {
            return false;
        }

        $objectToClean = $this->metadata->transaction(function () use ($bucketOwner, $bucket, $obj, $rule): ObjectInfo|false|null {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner, $obj->ownerId);
            $current = $this->metadata->getObjectMetadata($bucket, $obj->key);
            if ($current === null || ! self::sameObject($current, $obj) || ! $this->objectMatchesRule($bucket, $current, $rule)) {
                return false;
            }

            $versioning = $this->metadata->getBucketVersioning($bucket);
            if ($versioning === 'Enabled') {
                $this->metadata->deleteObjectVersioned($bucket, $obj->key, $obj->ownerId);

                return null;
            }
            if ($versioning === 'Suspended') {
                $this->metadata->deleteObjectVersioned($bucket, $obj->key, $obj->ownerId, suspended: true);

                return $current;
            }

            $this->metadata->deleteObjectMetadata($bucket, $obj->key);

            return $current;
        });

        if ($objectToClean === false) {
            return false;
        }
        if ($objectToClean instanceof ObjectInfo) {
            $this->deleteStoredData($objectToClean, 'expire_current');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @throws \OpsFour\S3Server\Exception\ObjectLockedException
     */
    private function deleteNoncurrentVersion(string $bucket, ObjectInfo $object, array $rule): bool
    {
        $bucketOwner = $this->metadata->getBucketOwner($bucket);
        if ($bucketOwner === null || $object->versionId === null) {
            return false;
        }

        $deleted = $this->metadata->transaction(function () use ($bucketOwner, $bucket, $object, $rule): ObjectInfo|false|null {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner, $object->ownerId);
            $current = $this->metadata->getObjectMetadataByVersion($bucket, $object->key, $object->versionId);
            if ($current === null || ! self::sameObject($current, $object) || ! $this->objectMatchesRule($bucket, $current, $rule)) {
                return false;
            }
            $this->objectLockChecker->checkProtection($bucket, $object->key, $object->versionId);

            return $this->metadata->deleteObjectVersion($bucket, $object->key, $object->versionId);
        });

        if (! $deleted instanceof ObjectInfo) {
            return false;
        }
        $this->deleteStoredData($deleted, 'expire_noncurrent');

        return true;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function deleteOrphanedMarker(string $bucket, ObjectInfo $marker, array $rule): bool
    {
        $bucketOwner = $this->metadata->getBucketOwner($bucket);
        if ($bucketOwner === null || $marker->versionId === null) {
            return false;
        }

        return $this->metadata->transaction(function () use ($bucketOwner, $bucket, $marker, $rule): bool {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner, $marker->ownerId);
            $current = $this->metadata->getObjectMetadataByVersion($bucket, $marker->key, $marker->versionId);
            if (
                $current === null
                || ! $current->isDeleteMarker
                || ($current->systemMetadata['isLatest'] ?? null) !== '1'
                || ! self::sameObject($current, $marker)
                || ! $this->objectMatchesRule($bucket, $current, $rule)
            ) {
                return false;
            }

            return $this->metadata->deleteObjectVersion($bucket, $marker->key, $marker->versionId) !== null;
        });
    }

    private static function sameObject(ObjectInfo $left, ObjectInfo $right): bool
    {
        return $left->versionId === $right->versionId
            && $left->etag === $right->etag
            && ($left->systemMetadata['storagePath'] ?? null) === ($right->systemMetadata['storagePath'] ?? null)
            && $left->isDeleteMarker === $right->isDeleteMarker;
    }

    private function deleteStoredData(ObjectInfo $object, string $action): void
    {
        $locations = [];
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        if ($storagePath !== null && $storagePath !== '') {
            $locations[] = [$this->storageTiers->tier($object->storageTier)->backend, $storagePath];
        }
        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            $locations[] = [$this->storageTiers->defaultBackend(), $object->restoredStoragePath];
        }

        foreach ($locations as [$backend, $path]) {
            try {
                $backend->deleteObjectByPath($path, $object->bucket);
            } catch (\Throwable $e) {
                try {
                    $tier = $backend === $this->storageTiers->defaultBackend()
                        ? $this->storageTiers->defaultTier()->name
                        : $object->storageTier;
                    $this->metadata->enqueueStorageGarbage($object->bucket, $tier, $path);
                } catch (\Throwable $queueError) {
                    throw new \RuntimeException(
                        'Lifecycle physical delete failed and could not be queued for retry.',
                        0,
                        $queueError,
                    );
                }
                $this->logger->warning('Lifecycle failed to delete object storage.', $this->lifecycleContext([
                    'event' => 'storage_delete_failed',
                    'bucket' => $object->bucket,
                    'key' => $object->key,
                    'version_id' => $object->versionId,
                    'storage_tier' => $object->storageTier,
                    'storage_path' => $path,
                    'action' => $action,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rule
     */
    private function logActionCompleted(string $bucket, array $rule, string $action, int $count, array $context = []): void
    {
        $ruleId = $this->ruleId($rule);
        $this->logger->info('Lifecycle action completed.', $this->lifecycleContext([
            'event' => 'action_completed',
            'bucket' => $bucket,
            'rule_id' => $ruleId,
            'action' => $action,
            'status' => 'completed',
            'count' => $count,
        ] + $context));

        $this->notifications?->dispatchEvent(new S3Event(
            name: 's3:Lifecycle:ActionApplied',
            bucket: $bucket,
            key: '',
            ownerId: '',
            attributes: [
                'rule_id' => $ruleId,
                'lifecycle_action' => $action,
                'count' => $count,
            ] + $context,
        ), enqueueWebhooks: false);
    }

    /** @param array<string, mixed> $rule */
    private function rulePrefix(array $rule): ?string
    {
        $prefix = $rule['prefix'] ?? $rule['filter']['prefix'] ?? $rule['filter']['and']['prefix'] ?? null;

        return is_string($prefix) ? $prefix : null;
    }

    /** @param array<string, mixed> $rule */
    private function objectMatchesRule(string $bucket, ObjectInfo $object, array $rule): bool
    {
        $requiredTags = $this->ruleTags($rule);
        if ($requiredTags === []) {
            return true;
        }

        $objectTags = [];
        foreach ($this->metadata->getObjectTagging($bucket, $object->key, $object->versionId) as $tag) {
            $objectTags[$tag['key']] = $tag['value'];
        }

        foreach ($requiredTags as $key => $value) {
            if (($objectTags[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null
     * @param array<string, mixed> $rule
     */
    private function checkpoint(string $bucket, array $rule, string $action): ?array
    {
        return $this->metadata->getLifecycleCheckpoint($bucket, $this->ruleId($rule), $action);
    }

    /** @param array<string, mixed> $rule */
    private function saveObjectCheckpoint(string $bucket, array $rule, string $action, ObjectInfo $object): void
    {
        $this->renewLeaseIfNeeded();
        $this->metadata->putLifecycleCheckpoint(
            $bucket,
            $this->ruleId($rule),
            $action,
            $object->key,
            $object->versionId,
        );
    }

    /** @param array<string, mixed> $rule */
    private function saveMultipartCheckpoint(string $bucket, array $rule, string $action, string $key, string $uploadId): void
    {
        $this->renewLeaseIfNeeded();
        $this->metadata->putLifecycleCheckpoint(
            $bucket,
            $this->ruleId($rule),
            $action,
            $key,
            cursorUploadId: $uploadId,
        );
    }

    /**
     * @param array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null $checkpoint
     * @param array<string, mixed> $rule
     */
    private function clearCheckpointIfPresent(string $bucket, array $rule, string $action, ?array $checkpoint): void
    {
        if ($checkpoint !== null) {
            $this->metadata->deleteLifecycleCheckpoint($bucket, $this->ruleId($rule), $action);
        }
    }

    /** @param array<string, mixed> $rule */
    private function ruleId(array $rule): string
    {
        return (string) ($rule['id'] ?? 'default');
    }

    /**
     * @return array<string, string>
     * @param array<string, mixed> $rule
     */
    private function ruleTags(array $rule): array
    {
        $tags = [];
        $filter = $rule['filter'] ?? null;

        if (!is_array($filter)) {
            return [];
        }

        if (isset($filter['tag']['key'], $filter['tag']['value'])) {
            $tags[(string) $filter['tag']['key']] = (string) $filter['tag']['value'];
        }

        foreach ($filter['and']['tags'] ?? [] as $tag) {
            if (isset($tag['key'], $tag['value'])) {
                $tags[(string) $tag['key']] = (string) $tag['value'];
            }
        }

        return $tags;
    }

    private function processAllBuckets(): void
    {
        $buckets = $this->metadata->listAllBuckets();

        $processed = 0;
        foreach ($buckets as $bucket) {
            $this->renewLeaseIfNeeded();
            try {
                $this->processBucket($bucket->name);
                $processed++;
            } catch (\Throwable $e) {
                if ($e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning(
                    'Lifecycle bucket processing failed.',
                    $this->lifecycleContext([
                        'event' => 'bucket_failed',
                        'bucket' => $bucket->name,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]),
                );
            }
        }

        $this->logger->debug('Lifecycle bucket sweep completed.', $this->lifecycleContext([
            'event' => 'bucket_sweep_completed',
            'processed_buckets' => $processed,
            'total_buckets' => count($buckets),
        ]));
    }

    private function renewLeaseIfNeeded(): void
    {
        if ($this->stopRequested) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Lifecycle shutdown was requested.',
            );
        }

        if (! $this->leaseHeld) {
            return;
        }

        $now = hrtime(true) / 1_000_000_000;
        if ($now - $this->lastLeaseRenewal < max(1.0, $this->lockTtlSeconds / 3)) {
            return;
        }

        if (! $this->metadata->acquireLock('lifecycle:global', $this->lockOwnerId, $this->lockTtlSeconds)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Lifecycle lease was lost while processing.',
            );
        }

        $this->lastLeaseRenewal = $now;
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    private function cleanupStaleMultipartUploads(string $bucket): int
    {
        if ($this->multipartMaxAgeSeconds === 0) {
            return 0;
        }

        $uploads = $this->metadata->listExpiredMultipartUploads(
            $bucket,
            0,
            $this->maxActionsPerRun,
            createdBefore: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify("-{$this->multipartMaxAgeSeconds} seconds"),
        );
        $aborted = 0;
        foreach ($uploads as $upload) {
            $this->renewLeaseIfNeeded();
            try {
                if ($this->abortMultipartUploadDurably($bucket, $upload['key_name'], $upload['upload_id'])) {
                    $aborted++;
                    $this->metrics?->recordLifecycleAction('abort_stale_multipart');
                }
            } catch (\Throwable $error) {
                $this->logger->warning('Stale multipart upload cleanup failed.', [
                    'bucket' => $bucket,
                    'key' => $upload['key_name'],
                    'upload_id' => $upload['upload_id'],
                    'error' => $error->getMessage(),
                ]);
            }
        }

        return $aborted;
    }

    private function abortMultipartUploadDurably(
        string $bucket,
        string $key,
        string $uploadId,
    ): bool {
        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null) {
            return false;
        }

        try {
            $bucketOwner = $this->metadata->getBucketOwner($bucket);
            $parts = $this->metadata->transaction(function () use ($bucket, $key, $uploadId, $upload, $bucketOwner): array {
                OwnerWriteLock::acquire(
                    $this->metadata,
                    $bucketOwner ?? '',
                    $upload['owner_id'],
                );

                return MultipartCleanup::stage(
                    $this->metadata,
                    $bucket,
                    $key,
                    $uploadId,
                    $upload['owner_id'],
                    $this->storageTiers->defaultTier()->name,
                );
            });
        } catch (NoSuchUploadException) {
            return false;
        }

        MultipartCleanup::clean(
            $this->metadata,
            $this->storage,
            $bucket,
            $key,
            $uploadId,
            $this->storageTiers->defaultTier()->name,
            $parts,
        );

        return true;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function lifecycleContext(array $context = []): array
    {
        return ['component' => 'lifecycle'] + $context;
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }
}
