<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** PostgreSQL-only cluster claim capability; legacy metadata APIs remain standalone. */
interface ClusterWorkFencingStore
{
    public function claimClusterLifecycle(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterNotification(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterTierTransition(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterRestore(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterStorageGarbage(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterMultipartIntent(string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function claimClusterObjectIntent(string $storageTier, string $workerId, float $leaseSeconds): ?ClusterWorkClaim;

    public function renewClusterWork(ClusterWorkToken $token, float $leaseSeconds): ?ClusterWorkToken;

    /** Must be called inside the metadata transaction that performs the guarded mutation. */
    public function assertClusterWorkToken(ClusterWorkToken $token): void;

    public function transactionWithClusterWork(ClusterWorkToken $token, callable $callback): mixed;

    public function releaseClusterWork(ClusterWorkToken $token): bool;
}
