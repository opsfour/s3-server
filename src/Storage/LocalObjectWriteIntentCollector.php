<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects only one configured local tier. SQL claims/CAS remain on the
 * calling fiber; blocking EFS seal calls run in a process worker.
 * Runtime scheduling and PUT/Copy admission are separate integration steps.
 */
final class LocalObjectWriteIntentCollector implements ShutdownAwareStorageBackend
{
    private readonly WorkerPool $sealPool;

    private readonly bool $ownsPool;

    private readonly LocalObjectWriteIntentReconciler $reconciler;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ObjectWriteIntentStore $metadata,
        StorageTierRegistry $tiers,
        private readonly string $tierName,
        LoggerInterface $logger = new NullLogger(),
        ?WorkerPool $sealPool = null,
        private readonly float $claimLeaseSeconds = 300.0,
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if (! is_finite($claimLeaseSeconds) || $claimLeaseSeconds <= 0.0 || $claimLeaseSeconds > 86400.0) {
            throw new \InvalidArgumentException('Local object claim lease must be between 0 and 86400 seconds.');
        }
        // Validate the selected backend before allocating a process pool.
        new LocalObjectWriteIntentReconciler($metadata, $tiers, $tierName, efsEvidence: $efsEvidence);
        $this->ownsPool = $sealPool === null;
        $this->sealPool = $sealPool ?? new ContextWorkerPool(2);
        $this->reconciler = new LocalObjectWriteIntentReconciler(
            $metadata,
            $tiers,
            $tierName,
            sealPool: $this->sealPool,
            efsEvidence: $efsEvidence,
        );
    }

    /** Keep the recovery worker on the exact SQL store, tier and root used for publication. */
    public function assertBoundTo(MetadataStore $metadata, LocalFencedObjectWriter $writer): void
    {
        if ($this->metadata !== $metadata
            || $this->tierName !== $writer->storageTierName()
            || $this->reconciler->storageRoot() !== $writer->storageRoot()) {
            throw new \RuntimeException('Local object intent collector differs from the fenced writer metadata, tier, or root.');
        }
    }

    /** Return completed retirements plus seals; the limit bounds their total per pass. */
    public function collect(int $limit = 100, ?Cancellation $cancellation = null): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Local object collection limit must be between 1 and 1000.');
        }

        // A NULL dispatch_id proves no physical task was ever submitted. Its
        // reservation can be retired without creating a seal at the path.
        $cancellation?->throwIfRequested();
        $this->efsEvidence?->assertFresh();
        $retired = $this->metadata->retireExpiredUndispatchedObjectWriteIntents($this->tierName, $limit);

        $sealed = 0;
        for ($i = $retired; $i < $limit; $i++) {
            $cancellation?->throwIfRequested();
            $this->efsEvidence?->assertFresh();
            $claimed = $this->metadata->claimDueObjectWriteIntents($this->tierName, 1, $this->claimLeaseSeconds);
            if ($claimed === []) {
                break;
            }
            $intent = $claimed[0];
            try {
                $this->reconciler->reconcile($intent, $cancellation, $this->claimLeaseSeconds);
                $sealed++;
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                // Keep the claimed row. After its lease expires another claim
                // can retry the same path without any overwrite by a writer.
                $this->logger->warning('Local object write seal failed and will be retried.', [
                    'component' => 'local_object_intent_gc',
                    'storage_tier' => $this->tierName,
                    'attempt_id' => $intent->attemptId,
                    'exception' => $error::class,
                ]);
            }
        }

        return $retired + $sealed;
    }

    public function shutdown(): void
    {
        if ($this->ownsPool) {
            $this->sealPool->shutdown();
        }
    }
}
