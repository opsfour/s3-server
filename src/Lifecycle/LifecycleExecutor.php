<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
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

        $this->lockOwnerId = $lockOwnerId ?? 'lifecycle-' . bin2hex(random_bytes(8));
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
            $lockAcquired = $this->metadata->acquireLock('lifecycle:global', $this->lockOwnerId, $this->lockTtlSeconds);
            if (!$lockAcquired) {
                $status = 'skipped_lock';
                $this->logger->info('Lifecycle sweep skipped because another node holds the lifecycle lock.', $this->lifecycleContext([
                    'event' => 'sweep_skipped_lock',
                    'status' => $status,
                    'lock_owner_id' => $this->lockOwnerId,
                ]));

            } else {
                $this->processAllBuckets();
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $this->logger->error('Lifecycle sweep failed.', $this->lifecycleContext([
                'event' => 'sweep_failed',
                'status' => $status,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));
        } finally {
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
        $startedAt = hrtime(true);
        $rules = $this->metadata->getBucketLifecycle($bucket);
        if ($rules === []) {
            return;
        }

        $remainingActions = $this->maxActionsPerRun;
        $appliedActions = 0;

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

                    $this->deleteObject($bucket, $obj);
                    $deleted++;
                    $actions++;
                    $this->metrics?->recordLifecycleAction($action);
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

                        $this->deleteObject($bucket, $obj);
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
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
                        $this->metadata->deleteObjectVersion($bucket, $obj->key, $obj->versionId);
                        $storagePath = $obj->systemMetadata['storagePath'] ?? null;
                        if ($storagePath !== null && $storagePath !== '') {
                            try {
                                $this->storage->deleteObjectByPath($storagePath, $bucket);
                            } catch (\Throwable $e) {
                                $this->logger->warning('Lifecycle failed to delete noncurrent version storage.', $this->lifecycleContext([
                                    'event' => 'storage_delete_failed',
                                    'bucket' => $bucket,
                                    'key' => $obj->key,
                                    'version_id' => $obj->versionId,
                                    'action' => $action,
                                    'exception' => $e::class,
                                    'error' => $e->getMessage(),
                                ]));
                            }
                        }
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
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
                            $this->storage->abortMultipartUpload($bucket, $upload['key_name'], $upload['upload_id']);
                            $this->metadata->deleteParts($upload['upload_id']);
                            $this->metadata->deleteMultipartUpload($upload['upload_id']);
                            $aborted++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
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
                        $this->metadata->deleteObjectVersion($bucket, $dm->key, $dm->versionId);
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
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
        $sourceStoragePath = $object->systemMetadata['storagePath'] ?? null;
        if ($sourceStoragePath === null || $sourceStoragePath === '') {
            return false;
        }

        $targetTier = $targetStorageClass;
        if (
            $object->storageClass === $targetStorageClass
            && $object->storageTier === $targetTier
            && $object->transitionStatus === 'available'
        ) {
            return false;
        }

        if (
            in_array($object->transitionStatus, ['pending', 'processing'], true)
            && $object->transitionTargetTier === $targetTier
        ) {
            return false;
        }

        $this->metadata->transaction(function () use ($object, $targetStorageClass, $targetTier, $sourceStoragePath): void {
            $this->metadata->enqueueTierTransitionJob(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $object->versionId,
                sourceTier: $object->storageTier,
                targetTier: $targetTier,
                targetStorageClass: $targetStorageClass,
                sourceStoragePath: $sourceStoragePath,
            );
            $this->metadata->updateObjectPlacement(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $object->versionId,
                storageClass: $object->storageClass,
                storageTier: $object->storageTier,
                storagePath: $sourceStoragePath,
                transitionStatus: 'pending',
                transitionTargetTier: $targetTier,
            );
        });

        return true;
    }

    private function deleteObject(string $bucket, ObjectInfo $obj): void
    {
        $versioning = $this->metadata->getBucketVersioning($bucket);

        if ($versioning === 'Enabled') {
            // Create a delete marker instead of permanent delete.
            $this->metadata->deleteObjectVersioned($bucket, $obj->key, $obj->ownerId);
        } elseif ($versioning === 'Suspended') {
            // Suspended: create delete marker at version_id='null' to preserve existing versions.
            $this->metadata->deleteObjectVersioned($bucket, $obj->key, $obj->ownerId, suspended: true);
        } else {
            // Delete metadata first, then best-effort storage cleanup.
            // If metadata delete succeeds but storage fails, the file is orphaned
            // (cleaned up by lifecycle temp-file sweep). The reverse order risks
            // metadata pointing to a missing file — causing 500 on reads.
            $storagePath = $obj->systemMetadata['storagePath'] ?? null;
            $this->metadata->deleteObjectMetadata($bucket, $obj->key);
            if ($storagePath !== null && $storagePath !== '') {
                try {
                    $this->storage->deleteObjectByPath($storagePath, $bucket);
                } catch (\Throwable $e) {
                    $this->logger->warning('Lifecycle failed to delete object storage.', $this->lifecycleContext([
                        'event' => 'storage_delete_failed',
                        'bucket' => $bucket,
                        'key' => $obj->key,
                        'version_id' => $obj->versionId,
                        'action' => 'expire_current',
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]));
                }
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
        foreach ($this->metadata->getObjectTagging($bucket, $object->key) as $tag) {
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
            try {
                $this->processBucket($bucket->name);
                $processed++;
            } catch (\Throwable $e) {
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
