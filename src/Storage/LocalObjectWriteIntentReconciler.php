<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\LocalObjectSealTask;

use function Amp\async;
use function Amp\delay;

/**
 * Reconciles one already committed SQL claim on a specifically selected local
 * tier. Claim discovery and worker scheduling belong to a separate adapter.
 * Blocking filesystem calls must never run on the API event loop.
 */
final readonly class LocalObjectWriteIntentReconciler
{
    private LocalObjectWriteFence $fence;

    private string $root;

    public function __construct(
        private ObjectWriteIntentStore $metadata,
        StorageTierRegistry $tiers,
        private string $tierName,
        ?LocalObjectWriteIo $io = null,
        private ?WorkerPool $sealPool = null,
    ) {
        $backend = $tiers->tier($tierName)->backend;
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof FilesystemBackend) {
            throw new \InvalidArgumentException('Object write reconciliation requires a local filesystem tier.');
        }

        $root = realpath($backend->getBasePath());
        if ($root === false) {
            throw new \InvalidArgumentException('Local object storage root must exist.');
        }
        $this->root = $root;
        $this->fence = new LocalObjectWriteFence($root, $io ?? new NativeLocalObjectWriteIo());
    }

    public function storageRoot(): string
    {
        return $this->root;
    }

    /** Seal the exact path first; only then mark its SQL intent as sealed. */
    public function reconcile(
        ObjectWriteIntent $claimed,
        ?Cancellation $cancellation = null,
        float $claimLeaseSeconds = 300.0,
        ?ClusterWorkToken $clusterToken = null,
        ?ClusterWorkFencingStore $clusterFencing = null,
    ): void {
        if (! is_finite($claimLeaseSeconds) || $claimLeaseSeconds <= 0.0 || $claimLeaseSeconds > 86400.0) {
            throw new \InvalidArgumentException('Local object claim lease must be between 0 and 86400 seconds.');
        }
        $cancellation?->throwIfRequested();
        if ($claimed->storageTier !== $this->tierName) {
            throw new \InvalidArgumentException('Object write claim belongs to another tier.');
        }

        $persisted = $this->metadata->getObjectWriteIntent($claimed->attemptId);
        if ($persisted === null
            || $persisted->state !== 'reconciling'
            || $persisted->claimGeneration !== $claimed->claimGeneration
            || $persisted->attemptId !== $claimed->attemptId
            || $persisted->bucket !== $claimed->bucket
            || $persisted->bucketGeneration !== $claimed->bucketGeneration
            || $persisted->ownerId !== $claimed->ownerId
            || $persisted->storageTier !== $claimed->storageTier
            || $persisted->storagePath !== $claimed->storagePath
            || $persisted->purpose !== $claimed->purpose
        ) {
            throw new \RuntimeException('Object write claim is no longer current in SQL.');
        }

        if ($this->sealPool === null) {
            $this->fence->seal($persisted);
        } else {
            $cancellation?->throwIfRequested();
            $this->submitAndAwaitSealWithHeartbeat($this->sealPool, $persisted, $claimLeaseSeconds, $clusterToken, $clusterFencing);
        }
        $cancellation?->throwIfRequested();
        $sealed = $clusterToken !== null && $clusterFencing !== null
            ? $clusterFencing->transactionWithClusterWork(
                $clusterToken,
                fn(): bool => $this->metadata->sealObjectWriteIntent($persisted->attemptId, $persisted->claimGeneration),
            )
            : $this->metadata->sealObjectWriteIntent($persisted->attemptId, $persisted->claimGeneration);
        if (! $sealed) {
            throw new \RuntimeException('Object write seal could not be committed in SQL.');
        }
    }

    private function submitAndAwaitSealWithHeartbeat(WorkerPool $pool, ObjectWriteIntent $claimed, float $leaseSeconds, ?ClusterWorkToken $clusterToken, ?ClusterWorkFencingStore $clusterFencing): void
    {
        $stop = new DeferredCancellation();
        $interval = min(60.0, max(0.001, $leaseSeconds / 3.0));
        $heartbeat = async(function () use ($claimed, $leaseSeconds, $interval, $stop, $clusterToken, $clusterFencing): void {
            $current = $claimed;
            $token = $clusterToken;
            while (true) {
                try {
                    delay($interval, cancellation: $stop->getCancellation());
                } catch (CancelledException) {
                    return;
                }
                if ($token !== null && $clusterFencing !== null) {
                    $token = $clusterFencing->renewClusterWork($token, $leaseSeconds);
                    if ($token === null) {
                        throw new \RuntimeException('Object write claim was lost while its seal worker was running.');
                    }
                    continue;
                }
                $renewed = $this->metadata->renewObjectWriteClaim($current->attemptId, $current->claimGeneration, $current->leaseUntil, $leaseSeconds);
                if ($renewed === null) {
                    throw new \RuntimeException('Object write claim was lost while its seal worker was running.');
                }
                $current = $renewed;
            }
        })->ignore();

        $workerCancellation = null;
        try {
            // Pool submission can itself wait for capacity. Start renewing
            // before it and never pass caller cancellation into submission:
            // after acceptance, the worker may still rename the target path.
            $worker = $pool->submit(new LocalObjectSealTask($this->root, $claimed))->getFuture();
            $worker->await();
        } catch (CancelledException $error) {
            $workerCancellation = $error;
            throw $error;
        } finally {
            $stop->cancel();
            try {
                $heartbeat->await();
            } catch (\Throwable $heartbeatError) {
                if ($workerCancellation === null) {
                    throw $heartbeatError;
                }
            }
        }
    }
}
