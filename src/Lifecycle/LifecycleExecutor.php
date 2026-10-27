<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Handler\Multipart\MultipartUploadTierResolver;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\BucketGenerationGuard;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\XmlRequestParser;
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
    private const string SWEEP_CURSOR_BUCKET = '';

    private const string SWEEP_CURSOR_RULE = '__opsfour_internal_sweep__';

    private const string SWEEP_CURSOR_ACTION = 'next_bucket';

    private const string STALE_MULTIPART_CHECKPOINT_RULE = '__opsfour_internal_stale_multipart__';

    private const string STALE_MULTIPART_CHECKPOINT_ACTION = 'next_upload';

    private readonly string $lockOwnerId;

    private readonly StorageTierRegistry $storageTiers;

    private readonly ObjectLockChecker $objectLockChecker;

    private readonly LoggerInterface $logger;

    private bool $leaseHeld = false;

    private float $lastLeaseRenewal = 0.0;

    private bool $stopRequested = false;

    private bool $runnerStopRequested = false;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        LoggerInterface $logger = new NullLogger(),
        private readonly int $batchSize = 1000,
        private readonly int $maxActionsPerRun = 1000,
        private readonly int $lockTtlSeconds = 300,
        ?string $lockOwnerId = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly ?NotificationDispatcher $notifications = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly int $multipartMaxAgeSeconds = 604_800,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
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
            if (! $lockAcquired) {
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
            if ($e instanceof \Amp\CancelledException || (($this->stopRequested || $this->runnerStopRequested) && $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException)) {
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

        // Clean up stale temp files from client disconnects in every local
        // tier. The runtime wraps backends for metrics before constructing us.
        // A stop requested during the bucket sweep must not dispatch a new
        // filesystem worker after the sweep has reported cancellation.
        if ($this->stopRequested || $this->runnerStopRequested) {
            return;
        }
        $backends = [$this->storage];
        foreach ($this->storageTiers->all() as $tier) {
            $backends[] = $tier->backend;
        }
        $swept = [];
        foreach ($backends as $tempStorage) {
            while ($tempStorage instanceof ObservedStorageBackend) {
                $tempStorage = $tempStorage->innerBackend();
            }
            if (! $tempStorage instanceof FilesystemBackend || isset($swept[spl_object_id($tempStorage)])) {
                continue;
            }
            $swept[spl_object_id($tempStorage)] = true;
            try {
                $cleaned = $tempStorage->cleanupStaleTempFiles();
                if ($cleaned > 0) {
                    $this->logger->info('Lifecycle temp-file cleanup completed.', $this->lifecycleContext([
                        'event' => 'temp_cleanup_completed',
                        'count' => $cleaned,
                    ]));
                }
            } catch (\Throwable $e) {
                if ($e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning('Lifecycle temp-file cleanup failed.', $this->lifecycleContext([
                    'event' => 'temp_cleanup_failed',
                    'exception' => $e::class,
                ]));
            }
        }
    }

    /**
     * Process lifecycle rules for a specific bucket.
     */
    public function processBucket(string $bucket): void
    {
        $appliedActions = 0;
        $scannedCandidates = 0;
        $this->processBucketWithBudget(
            $bucket,
            $this->maxActionsPerRun,
            $this->maxActionsPerRun,
            $appliedActions,
            $scannedCandidates,
        );
    }

    private function processBucketWithBudget(
        string $bucket,
        int $actionBudget,
        int $candidateBudget,
        int &$appliedActions,
        int &$scannedCandidates,
    ): void {
        $this->renewLeaseIfNeeded();
        $observedBucket = $this->metadata->getBucket($bucket);
        if ($observedBucket === null) {
            return;
        }
        $startedAt = hrtime(true);
        $this->cleanupStaleMultipartUploads(
            $observedBucket,
            $actionBudget,
            $candidateBudget,
            $appliedActions,
            $scannedCandidates,
        );
        $rules = $this->metadata->getBucketLifecycle($bucket);
        if ($rules === []) {
            return;
        }

        $remainingActions = max(0, $actionBudget - $appliedActions);
        $remainingCandidates = max(0, $candidateBudget - $scannedCandidates);

        $this->logger->debug('Lifecycle bucket processing started.', $this->lifecycleContext([
            'event' => 'bucket_started',
            'bucket' => $bucket,
            'rules' => count($rules),
            'action_budget' => $actionBudget,
            'candidate_budget' => $candidateBudget,
        ]));

        foreach ($rules as $rule) {
            if ($remainingActions <= 0 || $remainingCandidates <= 0) {
                $this->logger->info('Lifecycle bucket budget exhausted.', $this->lifecycleContext([
                    'event' => 'bucket_budget_exhausted',
                    'bucket' => $bucket,
                    'remaining_actions' => $remainingActions,
                    'remaining_candidates' => $remainingCandidates,
                    'applied_actions' => $appliedActions,
                    'scanned_candidates' => $scannedCandidates,
                ]));

                break;
            }

            $ruleStartedAt = hrtime(true);
            $ruleId = is_string($rule['id'] ?? null) ? $rule['id'] : 'invalid';
            $prefix = null;
            $ruleActions = 0;
            $ruleCandidates = 0;
            $ruleCompleted = false;

            try {
                LifecycleRuleValidator::assertValid($rule);
                if ($rule['status'] !== 'Enabled') {
                    continue;
                }
                $prefix = $this->rulePrefix($rule);
                $this->logger->debug('Lifecycle rule processing started.', $this->lifecycleContext([
                    'event' => 'rule_started',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'action_budget' => $remainingActions,
                    'candidate_budget' => $remainingCandidates,
                ]));
                $this->processRule(
                    $observedBucket,
                    $rule,
                    $prefix,
                    $remainingActions,
                    $remainingCandidates,
                    $ruleActions,
                    $ruleCandidates,
                );
                $ruleCompleted = true;
            } catch (\Throwable $e) {
                if ($e instanceof \Amp\CancelledException || $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning('Lifecycle rule processing failed.', $this->lifecycleContext([
                    'event' => 'rule_failed',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'exception' => $e::class,
                ]));
            } finally {
                $remainingActions -= $ruleActions;
                $remainingCandidates -= $ruleCandidates;
                $appliedActions += $ruleActions;
                $scannedCandidates += $ruleCandidates;
            }

            if ($ruleCompleted) {
                $this->logger->info('Lifecycle rule processing completed.', $this->lifecycleContext([
                    'event' => 'rule_completed',
                    'bucket' => $bucket,
                    'rule_id' => $ruleId,
                    'prefix' => $prefix,
                    'applied_actions' => $ruleActions,
                    'scanned_candidates' => $ruleCandidates,
                    'remaining_actions' => $remainingActions,
                    'remaining_candidates' => $remainingCandidates,
                    'duration_ms' => $this->durationMs($ruleStartedAt),
                ]));
            }
        }

        $this->logger->debug('Lifecycle bucket processing completed.', $this->lifecycleContext([
            'event' => 'bucket_completed',
            'bucket' => $bucket,
            'rules' => count($rules),
            'applied_actions' => $appliedActions,
            'scanned_candidates' => $scannedCandidates,
            'remaining_actions' => $remainingActions,
            'remaining_candidates' => $remainingCandidates,
            'duration_ms' => $this->durationMs($startedAt),
        ]));
    }

    /** @param array<string, mixed> $rule */
    private function processRule(
        BucketInfo $observedBucket,
        array $rule,
        ?string $prefix,
        int $actionBudget,
        int $candidateBudget,
        int &$actions,
        int &$candidates,
    ): void {
        $this->renewLeaseIfNeeded();
        BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
        $bucket = $observedBucket->name;
        $tags = $this->ruleTags($rule);

        if (isset($rule['transitions']) && is_array($rule['transitions']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            foreach ($rule['transitions'] as $transition) {
                if ($actions >= $actionBudget || $candidates >= $candidateBudget) {
                    break;
                }
                if (! is_array($transition) || ! isset($transition['storageClass'])) {
                    continue;
                }

                $action = 'transition_current:' . (string) $transition['storageClass'];
                $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
                $olderThan = $this->transitionCutoff($transition);
                if ($olderThan === null) {
                    continue;
                }

                $queued = 0;
                do {
                    $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                    $objects = $this->metadata->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $checkpoint['cursorKey'] ?? null);
                    $candidates += count($objects);

                    if ($objects === []) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($objects as $obj) {
                        $this->saveObjectCheckpoint($observedBucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                        if (! $this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->enqueueTransitionIfNeeded($observedBucket, $obj, (string) $transition['storageClass'], true, $this->ruleId($rule))) {
                            $queued++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($objects) < $limit) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    }
                } while (count($objects) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

                $this->logActionCompleted($bucket, $rule, $action, $queued, [
                    'target_storage_class' => (string) $transition['storageClass'],
                ]);
            }
        }

        if (isset($rule['noncurrentTransitions']) && is_array($rule['noncurrentTransitions']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            foreach ($rule['noncurrentTransitions'] as $transition) {
                if ($actions >= $actionBudget || $candidates >= $candidateBudget) {
                    break;
                }
                if (! is_array($transition) || ! isset($transition['storageClass'], $transition['noncurrentDays'])) {
                    continue;
                }

                $action = 'transition_noncurrent:' . (string) $transition['storageClass'];
                $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
                $queued = 0;

                do {
                    $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                    $objects = $this->metadata->listExpiredNoncurrentVersions(
                        $bucket,
                        $prefix,
                        (int) $transition['noncurrentDays'],
                        $limit,
                        $tags,
                        $checkpoint['cursorKey'] ?? null,
                        $checkpoint['cursorVersionId'] ?? null,
                    );
                    $candidates += count($objects);

                    if ($objects === []) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($objects as $obj) {
                        $this->saveObjectCheckpoint($observedBucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId ?? 'null', 'cursorUploadId' => null];

                        if (! $this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->enqueueTransitionIfNeeded($observedBucket, $obj, (string) $transition['storageClass'], false, $this->ruleId($rule))) {
                            $queued++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($objects) < $limit) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    }
                } while (count($objects) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

                $this->logActionCompleted($bucket, $rule, $action, $queued, [
                    'target_storage_class' => (string) $transition['storageClass'],
                    'noncurrent_days' => (int) $transition['noncurrentDays'],
                ]);
            }
        }

        // Expiration by days.
        if (isset($rule['expiration']['days']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            $action = 'expire_current';
            $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
            $days = (int) $rule['expiration']['days'];
            $olderThan = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                $expired = $this->metadata->listExpiredObjects($bucket, $prefix, $olderThan, $limit, $tags, $checkpoint['cursorKey'] ?? null);
                $candidates += count($expired);

                if ($expired === []) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($expired as $obj) {
                    $this->saveObjectCheckpoint($observedBucket, $rule, $action, $obj);
                    $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                    if (! $this->objectMatchesRule($bucket, $obj, $rule)) {
                        continue;
                    }

                    if ($this->deleteObject($observedBucket, $obj, $rule)) {
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
                    }
                }

                if (count($expired) < $limit) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                }
            } while (count($expired) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted, [
                'expiration_mode' => 'days',
                'expiration_days' => $days,
            ]);
        }

        // Expiration by date.
        if (isset($rule['expiration']['date']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            $action = 'expire_current';
            $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
            $date = new \DateTimeImmutable($rule['expiration']['date']);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($now >= $date) {
                $deleted = 0;
                do {
                    $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                    $expired = $this->metadata->listExpiredObjects($bucket, $prefix, $now, $limit, $tags, $checkpoint['cursorKey'] ?? null);
                    $candidates += count($expired);

                    if ($expired === []) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($expired as $obj) {
                        $this->saveObjectCheckpoint($observedBucket, $rule, $action, $obj);
                        $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId, 'cursorUploadId' => null];

                        if (! $this->objectMatchesRule($bucket, $obj, $rule)) {
                            continue;
                        }

                        if ($this->deleteObject($observedBucket, $obj, $rule)) {
                            $deleted++;
                            $actions++;
                            $this->metrics?->recordLifecycleAction($action);
                        }
                    }

                    if (count($expired) < $limit) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    }
                } while (count($expired) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

                $this->logActionCompleted($bucket, $rule, $action, $deleted, [
                    'expiration_mode' => 'date',
                    'expiration_date' => $rule['expiration']['date'],
                ]);
            }
        }

        // NoncurrentVersionExpiration.
        if (isset($rule['noncurrentExpiration']['noncurrentDays']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            $action = 'expire_noncurrent';
            $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
            $noncurrentDays = (int) $rule['noncurrentExpiration']['noncurrentDays'];
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                $expired = $this->metadata->listExpiredNoncurrentVersions(
                    $bucket,
                    $prefix,
                    $noncurrentDays,
                    $limit,
                    $tags,
                    $checkpoint['cursorKey'] ?? null,
                    $checkpoint['cursorVersionId'] ?? null,
                );
                $candidates += count($expired);

                if ($expired === []) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($expired as $obj) {
                    $this->saveObjectCheckpoint($observedBucket, $rule, $action, $obj);
                    $checkpoint = ['cursorKey' => $obj->key, 'cursorVersionId' => $obj->versionId ?? 'null', 'cursorUploadId' => null];

                    if (! $this->objectMatchesRule($bucket, $obj, $rule)) {
                        continue;
                    }

                    try {
                        if ($this->deleteNoncurrentVersion($observedBucket, $obj, $rule)) {
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

                if (count($expired) < $limit) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                }
            } while (count($expired) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted);
        }

        // AbortIncompleteMultipartUpload.
        if (isset($rule['abortIncompleteDays']) && $actions < $actionBudget && $candidates < $candidateBudget) {
            $action = 'abort_multipart';
            $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
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
                    $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                    $expired = $this->metadata->listExpiredMultipartUploads(
                        $bucket,
                        $days,
                        $limit,
                        $prefix,
                        $checkpoint['cursorKey'] ?? null,
                        $checkpoint['cursorUploadId'] ?? null,
                    );
                    $candidates += count($expired);

                    if ($expired === []) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                        break;
                    }

                    foreach ($expired as $upload) {
                        $this->saveMultipartCheckpoint($observedBucket, $rule, $action, $upload['key_name'], $upload['upload_id']);
                        $checkpoint = ['cursorKey' => $upload['key_name'], 'cursorVersionId' => null, 'cursorUploadId' => $upload['upload_id']];

                        try {
                            if ($this->abortMultipartUploadDurably($bucket, $upload['key_name'], $upload['upload_id'], $observedBucket, $rule)) {
                                $aborted++;
                                $actions++;
                                $this->metrics?->recordLifecycleAction($action);
                            }
                        } catch (\Throwable $e) {
                            if ($e instanceof \Amp\CancelledException || $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                                throw $e;
                            }
                            $this->logger->warning('Lifecycle failed to abort multipart upload.', $this->lifecycleContext([
                                'event' => 'multipart_abort_failed',
                                'bucket' => $bucket,
                                'key' => $upload['key_name'],
                                'upload_id' => $upload['upload_id'],
                                'action' => $action,
                                'exception' => $e::class,
                            ]));
                        }
                    }

                    if (count($expired) < $limit) {
                        $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    }
                } while (count($expired) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);
            }

            $this->logActionCompleted($bucket, $rule, $action, $aborted);
        }

        // ExpiredObjectDeleteMarker.
        if (isset($rule['expiration']['expiredObjectDeleteMarker']) && $rule['expiration']['expiredObjectDeleteMarker'] && $actions < $actionBudget && $candidates < $candidateBudget) {
            $action = 'delete_marker';
            $checkpoint = $this->checkpoint($observedBucket, $rule, $action);
            $deleted = 0;

            do {
                $limit = min($this->batchSize, $actionBudget - $actions, $candidateBudget - $candidates);
                $orphaned = $this->metadata->listOrphanedDeleteMarkers(
                    $bucket,
                    $prefix,
                    $limit,
                    $tags,
                    $checkpoint['cursorKey'] ?? null,
                    $checkpoint['cursorVersionId'] ?? null,
                );
                $candidates += count($orphaned);

                if ($orphaned === []) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                    break;
                }

                foreach ($orphaned as $dm) {
                    $this->saveObjectCheckpoint($observedBucket, $rule, $action, $dm);
                    $checkpoint = ['cursorKey' => $dm->key, 'cursorVersionId' => $dm->versionId ?? 'null', 'cursorUploadId' => null];

                    if (! $this->objectMatchesRule($bucket, $dm, $rule)) {
                        continue;
                    }

                    if ($this->deleteOrphanedMarker($observedBucket, $dm, $rule)) {
                        $deleted++;
                        $actions++;
                        $this->metrics?->recordLifecycleAction($action);
                    }
                }

                if (count($orphaned) < $limit) {
                    $this->clearCheckpointIfPresent($observedBucket, $rule, $action, $checkpoint);
                }
            } while (count($orphaned) === $limit && $actions < $actionBudget && $candidates < $candidateBudget);

            $this->logActionCompleted($bucket, $rule, $action, $deleted);
        }

    }

    /**
     * @param  array<string, mixed>  $transition
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

    private function enqueueTransitionIfNeeded(BucketInfo $observedBucket, ObjectInfo $object, string $targetStorageClass, bool $expectedLatest, string $ruleId): bool
    {
        $targetTier = $targetStorageClass;
        $bucketOwner = $observedBucket->ownerId;

        return $this->metadata->transaction(function () use ($observedBucket, $object, $targetStorageClass, $targetTier, $bucketOwner, $expectedLatest, $ruleId): bool {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            $current = $object->versionId !== null || ! $expectedLatest
                ? $this->metadata->getObjectMetadataByVersion($object->bucket, $object->key, $object->versionId ?? 'null')
                : $this->metadata->getObjectMetadata($object->bucket, $object->key);
            if (
                $current === null
                || ! self::sameObject($current, $object)
                || ($current->systemMetadata['isLatest'] ?? null) !== ($expectedLatest ? '1' : '0')
            ) {
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
                in_array($current->transitionStatus, ['pending', 'processing', 'failed'], true)
                && $current->transitionTargetTier === $targetTier
            ) {
                return false;
            }

            $this->metadata->enqueueTierTransitionJob(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId ?? ($expectedLatest ? null : 'null'),
                sourceTier: $current->storageTier,
                targetTier: $targetTier,
                targetStorageClass: $targetStorageClass,
                sourceStoragePath: $sourceStoragePath,
                origin: 'lifecycle',
                lifecycleRuleId: $ruleId,
                expectedIsLatest: $expectedLatest,
            );
            $this->metadata->updateObjectPlacement(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId ?? ($expectedLatest ? null : 'null'),
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
     * @param  array<string, mixed>  $rule
     */
    private function deleteObject(BucketInfo $observedBucket, ObjectInfo $obj, array $rule): bool
    {
        $bucket = $observedBucket->name;
        $bucketOwner = $observedBucket->ownerId;
        $observedVersioning = $this->metadata->getBucketVersioning($bucket);
        $observedNullVersion = $observedVersioning === 'Suspended'
            ? $this->metadata->getObjectMetadataByVersion($bucket, $obj->key, 'null')
            : null;
        $observedNullOwnerId = $observedNullVersion !== null && ! $observedNullVersion->isDeleteMarker
            ? $observedNullVersion->ownerId
            : '';

        $objectToClean = $this->metadata->transaction(function () use ($observedBucket, $bucketOwner, $bucket, $obj, $rule, $observedVersioning, $observedNullVersion, $observedNullOwnerId): ObjectInfo|false|null {
            OwnerWriteLock::acquire(
                $this->metadata,
                $bucketOwner,
                $obj->ownerId,
                $observedNullOwnerId,
            );
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            if (! $this->ruleStillCurrent($bucket, $rule)) {
                return false;
            }
            $current = $this->metadata->getObjectMetadata($bucket, $obj->key);
            if ($current === null || ! self::sameObject($current, $obj) || ! $this->objectMatchesRule($bucket, $current, $rule)) {
                return false;
            }

            $versioning = $this->metadata->getBucketVersioning($bucket);
            if ($versioning !== $observedVersioning) {
                return false;
            }
            $deleteMarkerOwnerId = BucketOwnership::resolveDeleteMarkerOwner(
                BucketOwnership::effectiveMode($this->metadata, $bucket),
                $bucketOwner,
                '',
                lifecycle: true,
            );
            if ($versioning === 'Enabled') {
                $this->metadata->deleteObjectVersioned($bucket, $obj->key, $deleteMarkerOwnerId);

                return null;
            }
            if ($versioning === 'Suspended') {
                // A numbered latest version stays readable by its version ID.
                // The null delete marker replaces only an existing null version,
                // which may itself be noncurrent behind that numbered version.
                $nullVersion = $this->metadata->getObjectMetadataByVersion($bucket, $obj->key, 'null');
                if (! self::sameOptionalObject($nullVersion, $observedNullVersion)) {
                    return false;
                }
                $replacedObject = $nullVersion !== null && ! $nullVersion->isDeleteMarker
                    ? $nullVersion
                    : null;
                if ($replacedObject !== null) {
                    $this->objectLockChecker->checkProtection($bucket, $obj->key, 'null');
                    $this->enqueueStoredDataCleanup($replacedObject);
                }
                $this->metadata->deleteObjectVersioned($bucket, $obj->key, $deleteMarkerOwnerId, suspended: true);

                return $replacedObject;
            }

            $this->enqueueStoredDataCleanup($current);
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
     * @param  array<string, mixed>  $rule
     *
     * @throws \OpsFour\S3Server\Exception\ObjectLockedException
     */
    private function deleteNoncurrentVersion(BucketInfo $observedBucket, ObjectInfo $object, array $rule): bool
    {
        $bucket = $observedBucket->name;
        $bucketOwner = $observedBucket->ownerId;
        $versionId = $object->versionId ?? 'null';

        $deleted = $this->metadata->transaction(function () use ($observedBucket, $bucketOwner, $bucket, $object, $rule, $versionId): ObjectInfo|false|null {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner, $object->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            if (! $this->ruleStillCurrent($bucket, $rule)) {
                return false;
            }
            $current = $this->metadata->getObjectMetadataByVersion($bucket, $object->key, $versionId);
            if ($current === null
                || ($current->systemMetadata['isLatest'] ?? null) !== '0'
                || ! self::sameObject($current, $object)
                || ! $this->noncurrentExpirationStillApplies($current, $rule)
                || ! $this->objectMatchesRule($bucket, $current, $rule)) {
                return false;
            }
            $this->objectLockChecker->checkProtection($bucket, $object->key, $versionId);

            $this->enqueueStoredDataCleanup($current);

            return $this->metadata->deleteObjectVersion($bucket, $object->key, $versionId);
        });

        if (! $deleted instanceof ObjectInfo) {
            return false;
        }
        $this->deleteStoredData($deleted, 'expire_noncurrent');

        return true;
    }

    /** @param array<string, mixed> $rule */
    private function noncurrentExpirationStillApplies(ObjectInfo $object, array $rule): bool
    {
        $days = $rule['noncurrentExpiration']['noncurrentDays'] ?? null;
        $noncurrentAt = $object->systemMetadata['noncurrentAt'] ?? null;
        if (! is_int($days) || $days < 1 || $days > XmlRequestParser::MAX_LIFECYCLE_DAYS || ! is_string($noncurrentAt)) {
            return false;
        }

        try {
            $demotedAt = new \DateTimeImmutable($noncurrentAt);
            $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days");
        } catch (\Throwable) {
            return false;
        }

        return $demotedAt < $cutoff;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function deleteOrphanedMarker(BucketInfo $observedBucket, ObjectInfo $marker, array $rule): bool
    {
        $bucket = $observedBucket->name;
        $bucketOwner = $observedBucket->ownerId;
        $versionId = $marker->versionId ?? 'null';

        return $this->metadata->transaction(function () use ($observedBucket, $bucketOwner, $bucket, $marker, $rule, $versionId): bool {
            OwnerWriteLock::acquire($this->metadata, $bucketOwner, $marker->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            if (! $this->ruleStillCurrent($bucket, $rule)) {
                return false;
            }
            $current = $this->metadata->getObjectMetadataByVersion($bucket, $marker->key, $versionId);
            if (
                $current === null
                || ! $current->isDeleteMarker
                || ($current->systemMetadata['isLatest'] ?? null) !== '1'
                || ! self::sameObject($current, $marker)
                || ! $this->objectMatchesRule($bucket, $current, $rule)
            ) {
                return false;
            }

            return $this->metadata->deleteObjectVersion($bucket, $marker->key, $versionId) !== null;
        });
    }

    private static function sameObject(ObjectInfo $left, ObjectInfo $right): bool
    {
        return $left->versionId === $right->versionId
            && $left->ownerId === $right->ownerId
            && $left->etag === $right->etag
            && ($left->systemMetadata['storagePath'] ?? null) === ($right->systemMetadata['storagePath'] ?? null)
            && $left->isDeleteMarker === $right->isDeleteMarker;
    }

    private static function sameOptionalObject(?ObjectInfo $left, ?ObjectInfo $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return self::sameObject($left, $right);
    }

    /** @param array<string, mixed> $observedRule */
    private function ruleStillCurrent(string $bucket, array $observedRule): bool
    {
        foreach ($this->metadata->getBucketLifecycle($bucket) as $currentRule) {
            if ($currentRule['id'] === $observedRule['id']) {
                // Configuration changes take the same owner lock as these
                // mutations. Treat even an in-place rule edit as a new rule.
                return $currentRule['status'] === 'Enabled' && $currentRule === $observedRule;
            }
        }

        return false;
    }

    private function deleteStoredData(ObjectInfo $object, string $action): void
    {
        foreach ($this->storedDataLocations($object) as [$backend, $tier, $path]) {
            try {
                $this->assertNotStopping();
                \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                    $this->metadata,
                    $backend,
                    $object->bucket,
                    $tier,
                    $path,
                );
            } catch (\Throwable $e) {
                if ($e instanceof \Amp\CancelledException || $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning('Lifecycle failed to delete object storage.', $this->lifecycleContext([
                    'event' => 'storage_delete_failed',
                    'bucket' => $object->bucket,
                    'key' => $object->key,
                    'version_id' => $object->versionId,
                    'storage_tier' => $object->storageTier,
                    'storage_path_hash' => hash('sha256', $path),
                    'action' => $action,
                    'exception' => $e::class,
                ]));
            }
        }
    }

    private function enqueueStoredDataCleanup(ObjectInfo $object): void
    {
        foreach ($this->storedDataLocations($object) as [, $tier, $path]) {
            $this->metadata->enqueueStorageGarbage($object->bucket, $tier, $path);
        }
    }

    /** @return list<array{StorageBackend, string, string}> */
    private function storedDataLocations(ObjectInfo $object): array
    {
        $locations = [];
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        if ($storagePath !== null && $storagePath !== '') {
            $locations[] = [$this->storageTiers->tier($object->storageTier)->backend, $object->storageTier, $storagePath];
        }
        if ($object->restoredStoragePath !== null) {
            $restoredTier = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($object, $this->storageTiers);
            $locations[] = [$restoredTier->backend, $restoredTier->name, $object->restoredStoragePath];
        }

        return $locations;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $rule
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
     * @param  array<string, mixed>  $rule
     * @return array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null
     */
    private function checkpoint(BucketInfo $observedBucket, array $rule, string $action): ?array
    {
        BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);

        return $this->metadata->getLifecycleCheckpoint($observedBucket->name, $this->ruleId($rule), $action);
    }

    /** @param array<string, mixed> $rule */
    private function saveObjectCheckpoint(BucketInfo $observedBucket, array $rule, string $action, ObjectInfo $object): void
    {
        $this->renewLeaseIfNeeded();
        $this->metadata->transaction(function () use ($observedBucket, $rule, $action, $object): void {
            OwnerWriteLock::acquire($this->metadata, $observedBucket->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            $this->metadata->putLifecycleCheckpoint(
                $observedBucket->name,
                $this->ruleId($rule),
                $action,
                $object->key,
                self::actionUsesVersionCursor($action) ? ($object->versionId ?? 'null') : $object->versionId,
            );
        });
    }

    private static function actionUsesVersionCursor(string $action): bool
    {
        return $action === 'expire_noncurrent'
            || $action === 'delete_marker'
            || str_starts_with($action, 'transition_noncurrent:');
    }

    /** @param array<string, mixed> $rule */
    private function saveMultipartCheckpoint(BucketInfo $observedBucket, array $rule, string $action, string $key, string $uploadId): void
    {
        $this->renewLeaseIfNeeded();
        $this->metadata->transaction(function () use ($observedBucket, $rule, $action, $key, $uploadId): void {
            OwnerWriteLock::acquire($this->metadata, $observedBucket->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            $this->metadata->putLifecycleCheckpoint(
                $observedBucket->name,
                $this->ruleId($rule),
                $action,
                $key,
                cursorUploadId: $uploadId,
            );
        });
    }

    /**
     * @param  array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null  $checkpoint
     * @param  array<string, mixed>  $rule
     */
    private function clearCheckpointIfPresent(BucketInfo $observedBucket, array $rule, string $action, ?array $checkpoint): void
    {
        if ($checkpoint !== null) {
            $this->metadata->transaction(function () use ($observedBucket, $rule, $action): void {
                OwnerWriteLock::acquire($this->metadata, $observedBucket->ownerId);
                BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
                $this->metadata->deleteLifecycleCheckpoint($observedBucket->name, $this->ruleId($rule), $action);
            });
        }
    }

    /** @param array<string, mixed> $rule */
    private function ruleId(array $rule): string
    {
        return (string) ($rule['id'] ?? 'default');
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, string>
     */
    private function ruleTags(array $rule): array
    {
        $tags = [];
        $seenKeys = [];
        $filter = $rule['filter'] ?? null;

        if (! is_array($filter)) {
            return [];
        }

        if (isset($filter['tag']['key'], $filter['tag']['value'])) {
            $key = (string) $filter['tag']['key'];
            $tags[$key] = (string) $filter['tag']['value'];
            $seenKeys["\0{$key}"] = true;
        }

        foreach ($filter['and']['tags'] ?? [] as $tag) {
            if (isset($tag['key'], $tag['value'])) {
                $key = (string) $tag['key'];
                if (isset($seenKeys["\0{$key}"])) {
                    throw new \UnexpectedValueException('Stored lifecycle filter contains duplicate tag keys.');
                }
                $tags[$key] = (string) $tag['value'];
                $seenKeys["\0{$key}"] = true;
            }
        }

        return $tags;
    }

    private function processAllBuckets(): void
    {
        $sweepCursor = $this->metadata->getLifecycleCheckpoint(
            self::SWEEP_CURSOR_BUCKET,
            self::SWEEP_CURSOR_RULE,
            self::SWEEP_CURSOR_ACTION,
        );
        $buckets = $this->rotateBuckets($this->metadata->listAllBuckets(), $sweepCursor['cursorKey'] ?? null);

        $processed = 0;
        $appliedActions = 0;
        $scannedCandidates = 0;
        $remainingActions = $this->maxActionsPerRun;
        $remainingCandidates = $this->maxActionsPerRun;
        $nextBucketName = null;
        foreach ($buckets as $index => $bucket) {
            $this->renewLeaseIfNeeded();
            $bucketActions = 0;
            $bucketCandidates = 0;
            try {
                $this->processBucketWithBudget(
                    $bucket->name,
                    $remainingActions,
                    $remainingCandidates,
                    $bucketActions,
                    $bucketCandidates,
                );
                $processed++;
            } catch (\Throwable $e) {
                if ($e instanceof \Amp\CancelledException || $e instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $e;
                }
                $this->logger->warning(
                    'Lifecycle bucket processing failed.',
                    $this->lifecycleContext([
                        'event' => 'bucket_failed',
                        'bucket' => $bucket->name,
                        'exception' => $e::class,
                    ]),
                );
            } finally {
                $appliedActions += $bucketActions;
                $scannedCandidates += $bucketCandidates;
                $remainingActions -= $bucketActions;
                $remainingCandidates -= $bucketCandidates;
            }

            if ($remainingActions <= 0 || $remainingCandidates <= 0) {
                $nextIndex = ($index + 1) % count($buckets);
                $nextBucketName = $buckets[$nextIndex]->name;

                break;
            }
        }

        if ($nextBucketName !== null) {
            $this->metadata->putLifecycleCheckpoint(
                self::SWEEP_CURSOR_BUCKET,
                self::SWEEP_CURSOR_RULE,
                self::SWEEP_CURSOR_ACTION,
                $nextBucketName,
            );
        } else {
            $this->metadata->deleteLifecycleCheckpoint(
                self::SWEEP_CURSOR_BUCKET,
                self::SWEEP_CURSOR_RULE,
                self::SWEEP_CURSOR_ACTION,
            );
        }

        $this->logger->debug('Lifecycle bucket sweep completed.', $this->lifecycleContext([
            'event' => 'bucket_sweep_completed',
            'processed_buckets' => $processed,
            'total_buckets' => count($buckets),
            'applied_actions' => $appliedActions,
            'scanned_candidates' => $scannedCandidates,
            'remaining_actions' => $remainingActions,
            'remaining_candidates' => $remainingCandidates,
        ]));
    }

    /**
     * Continue after the bucket that exhausted the previous global budget.
     * The cursor is stored under the global lease so another node can resume it.
     *
     * @param  list<BucketInfo>  $buckets
     * @return list<BucketInfo>
     */
    private function rotateBuckets(array $buckets, ?string $nextBucketName): array
    {
        if ($nextBucketName === null || count($buckets) < 2) {
            return $buckets;
        }

        foreach ($buckets as $index => $bucket) {
            if (strcmp($bucket->name, $nextBucketName) >= 0) {
                return array_values(array_merge(array_slice($buckets, $index), array_slice($buckets, 0, $index)));
            }
        }

        return $buckets;
    }

    private function renewLeaseIfNeeded(): void
    {
        $this->assertNotStopping();

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

    private function assertNotStopping(): void
    {
        if ($this->stopRequested || $this->runnerStopRequested) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Lifecycle shutdown was requested.',
            );
        }
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /** The runner's stop signal can be cleared only after its sweep has ended. */
    public function requestRunnerStop(): void
    {
        $this->runnerStopRequested = true;
    }

    public function resumeRunnerAfterStop(): void
    {
        $this->runnerStopRequested = false;
    }

    /**
     * @param-out int $consumedActions
     * @param-out int $consumedCandidates
     * @return array{actions: int, candidates: int}
     */
    private function cleanupStaleMultipartUploads(
        BucketInfo $observedBucket,
        ?int $actionBudget = null,
        ?int $candidateBudget = null,
        ?int &$consumedActions = null,
        ?int &$consumedCandidates = null,
    ): array {
        $consumedActions ??= 0;
        $consumedCandidates ??= 0;
        $initialActions = $consumedActions;
        $initialCandidates = $consumedCandidates;
        $actionBudget ??= $this->maxActionsPerRun;
        $candidateBudget ??= $this->maxActionsPerRun;
        if ($this->multipartMaxAgeSeconds === 0 || $actionBudget <= 0 || $candidateBudget <= 0) {
            return ['actions' => 0, 'candidates' => 0];
        }

        $bucket = $observedBucket->name;
        $checkpoint = $this->staleMultipartCheckpoint($observedBucket);
        $limit = min($this->batchSize, $actionBudget, $candidateBudget);
        $uploads = $this->metadata->listExpiredMultipartUploads(
            $bucket,
            0,
            $limit,
            afterKey: $checkpoint['cursorKey'] ?? null,
            afterUploadId: $checkpoint['cursorUploadId'] ?? null,
            createdBefore: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify("-{$this->multipartMaxAgeSeconds} seconds"),
        );
        $consumedCandidates += count($uploads);
        if ($uploads === []) {
            $this->clearStaleMultipartCheckpointIfPresent($observedBucket, $checkpoint);

            return ['actions' => 0, 'candidates' => 0];
        }

        foreach ($uploads as $upload) {
            $this->renewLeaseIfNeeded();
            $this->saveStaleMultipartCheckpoint($observedBucket, $upload['key_name'], $upload['upload_id']);
            $checkpoint = [
                'cursorKey' => $upload['key_name'],
                'cursorVersionId' => null,
                'cursorUploadId' => $upload['upload_id'],
            ];
            try {
                if ($this->abortMultipartUploadDurably($bucket, $upload['key_name'], $upload['upload_id'], $observedBucket)) {
                    $consumedActions++;
                    $this->metrics?->recordLifecycleAction('abort_stale_multipart');
                }
            } catch (\Throwable $error) {
                if ($error instanceof \Amp\CancelledException || $error instanceof \OpsFour\S3Server\Exception\OperationAbortedException) {
                    throw $error;
                }
                $this->logger->warning('Stale multipart upload cleanup failed.', [
                    'bucket' => $bucket,
                    'key' => $upload['key_name'],
                    'upload_id' => $upload['upload_id'],
                    'exception' => $error::class,
                ]);
            }
        }

        if (count($uploads) < $limit) {
            $this->clearStaleMultipartCheckpointIfPresent($observedBucket, $checkpoint);
        }

        return [
            'actions' => $consumedActions - $initialActions,
            'candidates' => $consumedCandidates - $initialCandidates,
        ];
    }

    /** @return array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null */
    private function staleMultipartCheckpoint(BucketInfo $observedBucket): ?array
    {
        BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);

        return $this->metadata->getLifecycleCheckpoint(
            $observedBucket->name,
            self::STALE_MULTIPART_CHECKPOINT_RULE,
            self::STALE_MULTIPART_CHECKPOINT_ACTION,
        );
    }

    private function saveStaleMultipartCheckpoint(BucketInfo $observedBucket, string $key, string $uploadId): void
    {
        $this->renewLeaseIfNeeded();
        $this->metadata->transaction(function () use ($observedBucket, $key, $uploadId): void {
            OwnerWriteLock::acquire($this->metadata, $observedBucket->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            $this->metadata->putLifecycleCheckpoint(
                $observedBucket->name,
                self::STALE_MULTIPART_CHECKPOINT_RULE,
                self::STALE_MULTIPART_CHECKPOINT_ACTION,
                $key,
                cursorUploadId: $uploadId,
            );
        });
    }

    /** @param array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null $checkpoint */
    private function clearStaleMultipartCheckpointIfPresent(BucketInfo $observedBucket, ?array $checkpoint): void
    {
        if ($checkpoint === null) {
            return;
        }

        $this->metadata->transaction(function () use ($observedBucket): void {
            OwnerWriteLock::acquire($this->metadata, $observedBucket->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
            $this->metadata->deleteLifecycleCheckpoint(
                $observedBucket->name,
                self::STALE_MULTIPART_CHECKPOINT_RULE,
                self::STALE_MULTIPART_CHECKPOINT_ACTION,
            );
        });
    }

    /** @param array<string, mixed>|null $observedRule */
    private function abortMultipartUploadDurably(
        string $bucket,
        string $key,
        string $uploadId,
        ?BucketInfo $observedBucket = null,
        ?array $observedRule = null,
    ): bool {
        $this->assertNotStopping();
        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null) {
            return false;
        }
        $uploadGeneration = $upload['bucket_generation'] ?? '';
        if ($observedBucket !== null && $uploadGeneration !== $observedBucket->generation
            && ! ($uploadGeneration === '' && $upload['owner_id'] === $observedBucket->ownerId)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Multipart upload belongs to another bucket generation.',
            );
        }
        $tier = MultipartUploadTierResolver::resolve($upload, $this->storageTiers);

        try {
            $bucketOwner = $this->metadata->getBucketOwner($bucket);
            $parts = $this->metadata->transaction(function () use ($bucket, $key, $uploadId, $upload, $uploadGeneration, $bucketOwner, $observedBucket, $observedRule, $tier): array|false {
                OwnerWriteLock::acquire(
                    $this->metadata,
                    $bucketOwner ?? '',
                    $upload['owner_id'],
                );
                if ($observedBucket !== null) {
                    BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket);
                }
                if ($observedRule !== null && ! $this->ruleStillCurrent($bucket, $observedRule)) {
                    return false;
                }

                return MultipartCleanup::stage(
                    $this->metadata,
                    $bucket,
                    $key,
                    $uploadId,
                    $upload['owner_id'],
                    $tier->name,
                    $uploadGeneration,
                );
            });
        } catch (NoSuchUploadException) {
            return false;
        }

        if ($parts === false) {
            return false;
        }

        $this->assertNotStopping();
        MultipartCleanup::clean(
            $this->metadata,
            $tier->backend,
            $bucket,
            $key,
            $uploadId,
            $tier->name,
            $parts,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
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
