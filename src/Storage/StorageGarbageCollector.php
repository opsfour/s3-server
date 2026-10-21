<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\StorageGarbageLeaseStore;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartPartSealGuard;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class StorageGarbageCollector
{
    private LoggerInterface $logger;

    public function __construct(
        private MetadataStore $metadata,
        private StorageTierRegistry $tiers,
        LoggerInterface $logger = new NullLogger(),
        private float $renewIntervalSeconds = 100.0,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if (! is_finite($renewIntervalSeconds) || $renewIntervalSeconds <= 0 || $renewIntervalSeconds >= 150) {
            throw new \InvalidArgumentException('Storage garbage renewal interval must fit within its lease.');
        }
    }

    public function collect(int $limit = 100, ?Cancellation $cancellation = null): int
    {
        if (! $this->metadata instanceof FencedStorageGarbageStore || ! $this->metadata instanceof StorageGarbageLeaseStore) {
            throw new \LogicException('Storage garbage collection requires generation-fenced lease renewal.');
        }
        $completed = 0;
        $processed = 0;
        $limit = max(1, $limit);

        if ($this->metadata instanceof FencedMultipartPartWriteIntentStore) {
            foreach ($this->tiers->all() as $tier) {
                $backend = $tier->backend;
                while ($backend instanceof ObservedStorageBackend) {
                    $backend = $backend->innerBackend();
                }
                if ($backend instanceof FilesystemBackend) {
                    $retired = $this->metadata->retireExpiredUndispatchedLocalMultipartPartWriteIntents(
                        $tier->name,
                        $limit - $processed,
                    );
                    $processed += $retired;
                    $completed += $retired;
                    if ($processed >= $limit) {
                        return $completed;
                    }
                }
            }
        }

        while ($processed < $limit) {
            $cancellation?->throwIfRequested();
            $items = $this->metadata->dequeueStorageGarbage(1);
            if ($items === []) {
                break;
            }

            $item = $items[0];
            $processed++;
            if (! isset($item['lease_generation'])) {
                throw new \LogicException('Storage garbage claim has no generation.');
            }
            $leaseUntil = $item['lease_expires_at'];
            $heartbeat = new QueueLeaseHeartbeat(function (float $_) use ($item, &$leaseUntil): bool {
                $now = $this->metadata instanceof MultipartWriteIntentStore
                    ? $this->metadata->multipartWriteIntentNow() : microtime(true);
                $next = $now + 300;
                if (! $this->metadata->renewStorageGarbageLease($item['id'], $next, $leaseUntil, $item['lease_generation'])) {
                    return false;
                }
                $leaseUntil = $next;

                return true;
            }, $this->renewIntervalSeconds);
            $failed = null;
            $retainedPartIntent = false;
            $localBackend = null;
            try {
                $backend = $this->tiers->tier($item['storage_tier'])->backend;
                if ($item['operation'] === 'abort_multipart') {
                    if ($item['key_name'] === null || $item['upload_id'] === null) {
                        throw new \RuntimeException('Multipart cleanup item is missing its key or upload ID.');
                    }
                    $backend->abortMultipartUpload($item['bucket'], $item['key_name'], $item['upload_id']);
                } elseif ($item['operation'] === 'delete_path') {
                    $backend->deleteObjectByPath($item['storage_path'], $item['bucket']);
                } elseif ($item['operation'] === 'part_write_intent') {
                    $localBackend = $backend;
                    while ($localBackend instanceof ObservedStorageBackend) {
                        $localBackend = $localBackend->innerBackend();
                    }
                    if ($localBackend instanceof FilesystemBackend) {
                        $dispatchId = $item['dispatch_id'] ?? null;
                        if ($dispatchId === null || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
                            throw new \RuntimeException('Local multipart part dispatch history is unknown.');
                        }
                        if (! $this->metadata instanceof LocalMultipartPartSealGuard
                            || ! $this->metadata->canSealLocalMultipartPartClaim(
                                $item['id'],
                                $item['storage_path'],
                                $item['lease_generation'],
                            )) {
                            throw new \RuntimeException('Local multipart part claim is referenced or no longer safe to seal.');
                        }
                        $this->sealLocalPart($localBackend, $item['storage_path'], $item['upload_id']);
                    } else {
                        // A remote worker can finish after deletion. Retain
                        // the intent and revisit its path periodically.
                        $backend->deleteObjectByPath($item['storage_path'], $item['bucket']);
                        try {
                            $stream = $backend->getObjectByPath($item['storage_path']);
                        } catch (NoSuchKeyException) {
                            $stream = null;
                        }
                        if ($stream !== null) {
                            $stream->close();
                            throw new \RuntimeException('Uncommitted multipart part is still readable after delete.');
                        }
                    }
                    $retainedPartIntent = true;
                } else {
                    throw new \RuntimeException('Unknown storage garbage operation.');
                }
            } catch (CancelledException $e) {
                // The worker may be shutting down while the backend is still
                // resolving a delete. Let the lease recover without charging
                // a failed attempt for an interrupted operation.
                try {
                    $heartbeat->stop();
                } catch (\Throwable) {
                    // The claim will expire for another collector.
                }
                throw $e;
            } catch (\Throwable $e) {
                $failed = $e;
            }
            try {
                $heartbeat->stop();
            } catch (\Throwable $e) {
                $this->logger->warning('Storage garbage claim was lost during physical cleanup.', [
                    'component' => 'storage_gc',
                    'garbage_id' => $item['id'],
                    'exception' => $e::class,
                ]);
                continue;
            }
            $item['lease_expires_at'] = $leaseUntil;
            if ($failed !== null) {
                $attempts = $item['attempts'] + 1;
                $retryScheduled = $this->retryClaim(
                    $item,
                    'Physical storage cleanup failed.',
                    ($this->metadata instanceof MultipartWriteIntentStore
                        ? $this->metadata->multipartWriteIntentNow() : microtime(true))
                        + min(300, 2 ** min($attempts, 8)),
                );
                if ($retryScheduled) {
                    $this->logger->warning('Physical storage garbage deletion failed and will be retried.', [
                        'component' => 'storage_gc',
                        'garbage_id' => $item['id'],
                        'bucket' => $item['bucket'],
                        'storage_tier' => $item['storage_tier'],
                        'operation' => $item['operation'],
                        'storage_path_hash' => hash('sha256', $item['storage_path']),
                        'attempts' => $attempts,
                        'exception' => $failed::class,
                    ]);
                }
            } elseif ($retainedPartIntent) {
                if ($this->retryClaim(
                    $item,
                    'Uncommitted part path retained for late-write reconciliation.',
                    $localBackend instanceof FilesystemBackend ? 1.0e300
                        : ($this->metadata instanceof MultipartWriteIntentStore
                            ? $this->metadata->multipartWriteIntentNow() : microtime(true))
                            + min(86_400, 3600 * (2 ** min($item['attempts'], 5))),
                )) {
                    $completed++;
                }
            } else {
                if ($this->completeClaim($item)) {
                    $completed++;
                }
            }
            $cancellation?->throwIfRequested();
        }

        return $completed;
    }

    private function sealLocalPart(FilesystemBackend $backend, string $path, ?string $uploadId): void
    {
        if ($uploadId === null || preg_match('/^([1-9][0-9]{0,4})-[a-f0-9]{32}-([a-f0-9]{32})$/D', basename($path), $matches) !== 1) {
            throw new \RuntimeException('Local multipart part path is not a planned attempt.');
        }
        try {
            StoragePath::assertPlannedPartPath($backend->getBasePath(), $path, $uploadId, (int) $matches[1]);
        } catch (\InvalidArgumentException $error) {
            $resolvedRoot = realpath($backend->getBasePath());
            if ($resolvedRoot === false || rtrim($resolvedRoot, '/') === rtrim($backend->getBasePath(), '/')) {
                throw $error;
            }
            StoragePath::assertPlannedPartPath(rtrim($resolvedRoot, '/'), $path, $uploadId, (int) $matches[1]);
        }
        $backend->sealFencedMultipartPath($path, $matches[2]);
    }

    /** @param array{id: int, lease_expires_at: float, lease_generation?: int} $item */
    private function completeClaim(array $item): bool
    {
        if ($this->metadata instanceof FencedStorageGarbageStore && isset($item['lease_generation'])) {
            return $this->metadata->completeStorageGarbageLease($item['id'], $item['lease_expires_at'], $item['lease_generation']);
        }

        return $this->metadata->completeStorageGarbage($item['id'], $item['lease_expires_at']);
    }

    /** @param array{id: int, lease_expires_at: float, lease_generation?: int} $item */
    private function retryClaim(array $item, string $error, float $nextAttemptAt): bool
    {
        if ($this->metadata instanceof FencedStorageGarbageStore && isset($item['lease_generation'])) {
            return $this->metadata->retryStorageGarbageLease($item['id'], $error, $nextAttemptAt, $item['lease_expires_at'], $item['lease_generation']);
        }

        return $this->metadata->retryStorageGarbage($item['id'], $error, $nextAttemptAt, $item['lease_expires_at']);
    }
}
