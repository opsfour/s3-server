<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Metadata\ClusterWorkClaim;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder;
use OpsFour\S3Server\Metadata\ClusterWorkToken;

final class RecordingClusterWorkFencingStore implements ClusterWorkFencingStore, ClusterWorkOutcomeRecorder
{
    /** @var list<array{token: ClusterWorkToken, outcome: string}> */
    public array $outcomes = [];

    public function __construct(public ?ClusterWorkClaim $claim = null) {}

    public static function takeoverToken(string $kind, string $workId = '1'): ClusterWorkToken
    {
        return new ClusterWorkToken($kind, $workId, 'node:worker', 2, 100.0, 200.0, true);
    }

    public function claimClusterLifecycle(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::LIFECYCLE);
    }

    public function claimClusterNotification(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::NOTIFICATION);
    }

    public function claimClusterTierTransition(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::TIER_TRANSITION);
    }

    public function claimClusterRestore(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::RESTORE);
    }

    public function claimClusterStorageGarbage(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::STORAGE_GARBAGE);
    }

    public function claimClusterMultipartIntent(string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::MULTIPART_INTENT);
    }

    public function claimClusterObjectIntent(string $storageTier, string $workerId, float $leaseSeconds): ?ClusterWorkClaim
    {
        return $this->claimFor(ClusterWorkToken::OBJECT_INTENT);
    }

    public function renewClusterWork(ClusterWorkToken $token, float $leaseSeconds): ?ClusterWorkToken
    {
        return $this->claim === null ? null : $token;
    }

    public function assertClusterWorkToken(ClusterWorkToken $token): void {}

    public function transactionWithClusterWork(ClusterWorkToken $token, callable $callback): mixed
    {
        return $callback();
    }

    public function releaseClusterWork(ClusterWorkToken $token, bool $failedTakeover = false): bool
    {
        if ($failedTakeover) {
            $this->recordClusterWorkOutcome($token, self::FAILED_TAKEOVER);
        }

        return true;
    }

    public function recordClusterWorkOutcome(ClusterWorkToken $token, string $outcome): void
    {
        $this->outcomes[] = ['token' => $token, 'outcome' => $outcome];
    }

    private function claimFor(string $kind): ?ClusterWorkClaim
    {
        return $this->claim?->token->kind === $kind ? $this->claim : null;
    }
}
