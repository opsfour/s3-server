<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/** Reconciles one current generation-fenced AWS SQL claim. */
final readonly class AwsS3ObjectWriteIntentReconciler
{
    public function __construct(
        private ObjectWriteIntentStore $metadata,
        private string $tierName,
        private AwsS3SameKeySealWriter $seals,
    ) {
        if ($tierName === '') {
            throw new \InvalidArgumentException('AWS reconciliation requires a storage tier.');
        }
    }

    public function reconcile(
        ObjectWriteIntent $claimed,
        ?Cancellation $cancellation = null,
        float $claimLeaseSeconds = 300.0,
    ): void {
        if (! is_finite($claimLeaseSeconds) || $claimLeaseSeconds <= 0.0 || $claimLeaseSeconds > 86390.0
            || $claimed->storageTier !== $this->tierName || ! $claimed->sealReserved) {
            throw new \InvalidArgumentException('AWS object claim is invalid for this reconciler.');
        }
        $current = $this->metadata->getObjectWriteIntent($claimed->attemptId);
        if ($current === null || ! self::sameClaim($claimed, $current)) {
            throw new \RuntimeException('AWS object write claim is no longer current in SQL.');
        }
        $heartbeatCount = 0;
        $heartbeat = function () use (&$current, &$heartbeatCount, $claimLeaseSeconds, $cancellation): void {
            $cancellation?->throwIfRequested();
            // Provider commands can begin within one database clock tick. An
            // increasing duration guarantees every heartbeat extends rather
            // than accidentally reproducing the just-claimed deadline.
            ++$heartbeatCount;
            $renewed = $this->metadata->renewObjectWriteClaim(
                $current->attemptId,
                $current->claimGeneration,
                $current->leaseUntil,
                $claimLeaseSeconds + $heartbeatCount,
            );
            if ($renewed === null || ! self::sameClaim($current, $renewed)) {
                throw new \RuntimeException('AWS object write claim was lost during reconciliation.');
            }
            $current = $renewed;
        };

        $this->seals->reconcile($current, $heartbeat);
        $cancellation?->throwIfRequested();
        if (! $this->metadata->sealObjectWriteIntent($current->attemptId, $current->claimGeneration)) {
            throw new \RuntimeException('Verified AWS object seal could not be committed in SQL.');
        }
    }

    private static function sameClaim(ObjectWriteIntent $expected, ObjectWriteIntent $actual): bool
    {
        return $actual->state === 'reconciling'
            && $expected->attemptId === $actual->attemptId
            && $expected->bucket === $actual->bucket
            && $expected->bucketGeneration === $actual->bucketGeneration
            && $expected->ownerId === $actual->ownerId
            && $expected->storageTier === $actual->storageTier
            && $expected->storagePath === $actual->storagePath
            && $expected->purpose === $actual->purpose
            && $expected->claimGeneration === $actual->claimGeneration
            && $expected->dispatchId === $actual->dispatchId
            && $expected->expectedSize === $actual->expectedSize
            && $expected->expectedSha256 === $actual->expectedSha256
            && $actual->sealReserved;
    }
}
