<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Bounded tier-scoped AWS ambiguous-write collector. */
final readonly class AwsS3ObjectWriteIntentCollector
{
    private AwsS3ObjectWriteIntentReconciler $reconciler;
    private LoggerInterface $logger;

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
        $this->operationalState?->assertMutationReady();
        $cancellation?->throwIfRequested();
        $retired = $this->metadata->retireExpiredUndispatchedObjectWriteIntents($this->tierName, $limit);
        $sealed = 0;
        for ($i = $retired; $i < $limit; ++$i) {
            $cancellation?->throwIfRequested();
            $claims = $this->metadata->claimDueObjectWriteIntents($this->tierName, 1, $this->claimLeaseSeconds);
            if ($claims === []) {
                break;
            }
            try {
                $this->reconciler->reconcile($claims[0], $cancellation, $this->claimLeaseSeconds);
                ++$sealed;
                $this->metrics?->recordAwsWriteReconciliation('sealed');
                $this->operationalState?->markCollectorHealthy();
            } catch (CancelledException $error) {
                throw $error;
            } catch (AwsS3SealIntegrityException $error) {
                $this->metrics?->recordAwsWriteReconciliation('integrity_conflict');
                $this->operationalState?->markIntegrityConflict();
                $this->logger->error('AWS write reconciliation found conflicting immutable evidence.', [
                    'component' => 'aws_object_intent_reconciler',
                    'storage_tier' => $this->tierName,
                    'exception' => $error::class,
                ]);
            } catch (\Throwable $error) {
                $this->metrics?->recordAwsWriteReconciliation('retry');
                $this->operationalState?->markCollectorFailure();
                $this->logger->warning('AWS write reconciliation remains unresolved.', [
                    'component' => 'aws_object_intent_reconciler',
                    'storage_tier' => $this->tierName,
                    'exception' => $error::class,
                ]);
            }
        }

        return $retired + $sealed;
    }

    public function shutdown(): void
    {
        // The admitted SDK client is owned by the storage backend.
    }
}
