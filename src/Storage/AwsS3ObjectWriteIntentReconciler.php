<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;

/** Reconciles one current generation-fenced AWS SQL claim. */
final readonly class AwsS3ObjectWriteIntentReconciler
{
    /** @return list<array{id: string, capability: string}> */
    public static function storageMutatorRegistrations(): array
    {
        return [[
            'id' => 'worker.same-key-seal-reconciliation',
            'capability' => StorageMutatorInventory::SEAL,
        ]];
    }

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
        ?ClusterWorkToken $clusterToken = null,
        ?ClusterWorkFencingStore $clusterFencing = null,
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
        $token = $clusterToken;
        $heartbeat = function () use (&$current, &$heartbeatCount, $claimLeaseSeconds, $cancellation, &$token, $clusterFencing): void {
            $cancellation?->throwIfRequested();
            // Provider commands can begin within one database clock tick. An
            // increasing duration guarantees every heartbeat extends rather
            // than accidentally reproducing the just-claimed deadline.
            ++$heartbeatCount;
            if ($token !== null && $clusterFencing !== null) {
                $token = $clusterFencing->renewClusterWork($token, $claimLeaseSeconds + $heartbeatCount);
                if ($token === null) {
                    throw new \RuntimeException('AWS object write claim was lost during reconciliation.');
                }
                return;
            }
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
        $sealed = $clusterToken !== null && $clusterFencing !== null
            ? $clusterFencing->transactionWithClusterWork(
                $clusterToken,
                fn(): bool => $this->metadata->sealObjectWriteIntent($current->attemptId, $current->claimGeneration),
            )
            : $this->metadata->sealObjectWriteIntent($current->attemptId, $current->claimGeneration);
        if (! $sealed) {
            throw new \RuntimeException('Verified AWS object seal could not be committed in SQL.');
        }
    }

    public function isBoundTo(
        ObjectWriteIntentStore $metadata,
        string $tierName,
        AwsS3ProviderValidatedBinding $binding,
    ): bool {
        return $this->metadata === $metadata
            && hash_equals($this->tierName, $tierName)
            && $this->seals->isBoundTo($binding);
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
