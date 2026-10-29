<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Bounded tier-scoped AWS ambiguous-write collector. */
final class AwsS3ObjectWriteIntentCollector
{
    private AwsS3ObjectWriteIntentReconciler $reconciler;
    private LoggerInterface $logger;
    private ?ClusterWorkFencingStore $clusterFencing = null;
    private ?string $clusterWorkerId = null;

    public function __construct(
        private ObjectWriteIntentStore $metadata,
        private string $tierName,
        AwsS3SameKeySealWriter $seals,
        LoggerInterface $logger = new NullLogger(),
        private ?MetricsCollector $metrics = null,
        private float $claimLeaseSeconds = 300.0,
        private ?AwsS3OperationalState $operationalState = null,
    ) {
        if (! is_finite($claimLeaseSeconds) || $claimLeaseSeconds <= 0.0 || $claimLeaseSeconds > 86390.0) {
            throw new \InvalidArgumentException('AWS object claim lease must be between 0 and 86390 seconds.');
        }
        $this->logger = new FailureIsolatingLogger($logger);
        $this->reconciler = new AwsS3ObjectWriteIntentReconciler($metadata, $tierName, $seals);
    }

    public function collect(int $limit = 100, ?Cancellation $cancellation = null): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('AWS object collection limit must be between 1 and 1000.');
        }
        $cancellation?->throwIfRequested();
        $this->operationalState?->assertRecoveryReady();
        try {
            $retired = $this->metadata->retireExpiredUndispatchedObjectWriteIntents($this->tierName, $limit);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->metrics?->recordAwsWriteReconciliation('retry');
            $this->operationalState?->markCollectorFailure();
            throw $error;
        }
        $sealed = 0;
        $failed = false;
        for ($i = $retired; $i < $limit; ++$i) {
            $cancellation?->throwIfRequested();
            try {
                if ($this->clusterFencing !== null && $this->clusterWorkerId !== null) {
                    $clusterClaim = $this->clusterFencing->claimClusterObjectIntent($this->tierName, $this->clusterWorkerId, $this->claimLeaseSeconds);
                    $claims = $clusterClaim === null ? [] : [$clusterClaim->work];
                } else {
                    $clusterClaim = null;
                    $claims = $this->metadata->claimDueObjectWriteIntents($this->tierName, 1, $this->claimLeaseSeconds);
                }
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->metrics?->recordAwsWriteReconciliation('retry');
                $this->operationalState?->markCollectorFailure();
                throw $error;
            }
            if ($claims === []) {
                break;
            }
            if (! $claims[0] instanceof \OpsFour\S3Server\Metadata\ObjectWriteIntent) {
                throw new \LogicException('AWS object cluster claim returned an invalid payload.');
            }
            try {
                $this->reconciler->reconcile(
                    $claims[0],
                    $cancellation,
                    $this->claimLeaseSeconds,
                    $clusterClaim?->token,
                    $this->clusterFencing,
                );
                if ($claims[0]->dispatchId !== null) {
                    $this->operationalState?->resolveObjectRecoveryPending(
                        $claims[0]->attemptId,
                        $claims[0]->dispatchId,
                    );
                }
                ++$sealed;
                $this->metrics?->recordAwsWriteReconciliation('sealed');
            } catch (CancelledException $error) {
                throw $error;
            } catch (AwsS3SealIntegrityException $error) {
                $this->metrics?->recordAwsWriteReconciliation('integrity_conflict');
                $this->operationalState?->markIntegrityConflict();
                if ($claims[0]->dispatchId !== null) {
                    $this->operationalState?->resolveObjectRecoveryPending(
                        $claims[0]->attemptId,
                        $claims[0]->dispatchId,
                    );
                }
                $this->logger->error('AWS write reconciliation found conflicting immutable evidence.', [
                    'component' => 'aws_object_intent_reconciler',
                    'storage_tier' => $this->tierName,
                    'exception' => $error::class,
                ]);
            } catch (\Throwable $error) {
                $failed = true;
                $this->metrics?->recordAwsWriteReconciliation('retry');
                $this->operationalState?->markCollectorFailure();
                $this->logger->warning('AWS write reconciliation remains unresolved.', [
                    'component' => 'aws_object_intent_reconciler',
                    'storage_tier' => $this->tierName,
                    'exception' => $error::class,
                ]);
            }
        }
        if (! $failed) {
            $this->operationalState?->markCollectorHealthy();
        }

        return $retired + $sealed;
    }

    public function shutdown(): void
    {
        // The admitted SDK client is owned by the storage backend.
    }

    public function enableClusterFencing(ClusterWorkFencingStore $fencing, string $workerId): void
    {
        if ($this->clusterFencing !== null) {
            throw new \LogicException('AWS object collector cluster fencing is already configured.');
        }
        $this->clusterFencing = $fencing;
        $this->clusterWorkerId = $workerId;
    }

    public function isBoundTo(
        ObjectWriteIntentStore $metadata,
        string $tierName,
        AwsS3OperationalState $operationalState,
        AwsS3ProviderValidatedBinding $binding,
    ): bool {
        return $this->metadata === $metadata
            && hash_equals($this->tierName, $tierName)
            && $this->operationalState === $operationalState
            && $this->reconciler->isBoundTo($metadata, $tierName, $binding);
    }
}
