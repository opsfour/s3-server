<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/**
 * Type-safe test adapter combining the exact intent store and summary graph.
 *
 * @phpstan-type AwsSummary array{
 *   open: int,
 *   ambiguous: int,
 *   seal_reserved: int,
 *   sealed: int,
 *   multipart: int,
 *   part: int,
 *   cleanup: int,
 *   failures: int,
 *   oldest_age_seconds: float,
 *   max_owner_open: int,
 *   max_owner_sealed: int,
 *   max_owner_multipart: int,
 *   max_owner_part: int,
 *   global_open: int,
 *   global_seal_reserved: int,
 *   global_multipart: int,
 *   global_part: int,
 *   global_cleanup: int
 * }
 */
final readonly class FixtureAwsS3IntentSummaryStore implements ObjectWriteIntentStore, AwsS3OperationalSummaryReader
{
    /** @param \Closure(string): AwsSummary $summary */
    public function __construct(
        private ObjectWriteIntentStore $inner,
        private \Closure $summary,
    ) {}

    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        return ($this->summary)($storageTier);
    }

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent
    {
        return $this->inner->reserveObjectWriteIntent($intent, $leaseSeconds);
    }

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent
    {
        return $this->inner->getObjectWriteIntent($attemptId);
    }

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewObjectWriteIntent($attemptId, $expectedLeaseUntil, $leaseSeconds);
    }

    public function armObjectWriteIntent(
        string $attemptId,
        float $expectedLeaseUntil,
        string $dispatchId,
        float $leaseSeconds,
        ?int $expectedSize = null,
        ?string $expectedSha256 = null,
    ): ?ObjectWriteIntent {
        return $this->inner->armObjectWriteIntent(
            $attemptId,
            $expectedLeaseUntil,
            $dispatchId,
            $leaseSeconds,
            $expectedSize,
            $expectedSha256,
        );
    }

    public function renewArmedObjectWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewArmedObjectWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $leaseSeconds);
    }

    public function expireArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->expireArmedObjectWriteIntent($attemptId, $dispatchId);
    }

    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->retireUndispatchedObjectWriteIntent($attemptId);
    }

    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
    }

    public function consumeObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->consumeObjectWriteIntent($attemptId);
    }

    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool
    {
        return $this->inner->consumeArmedObjectWriteIntent($attemptId, $dispatchId);
    }

    public function abandonObjectWriteIntent(string $attemptId): bool
    {
        return $this->inner->abandonObjectWriteIntent($attemptId);
    }

    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array
    {
        return $this->inner->claimDueObjectWriteIntents($storageTier, $limit, $leaseSeconds);
    }

    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int
    {
        return $this->inner->retireExpiredUndispatchedObjectWriteIntents($storageTier, $limit);
    }

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->inner->renewObjectWriteClaim($attemptId, $claimGeneration, $expectedLeaseUntil, $leaseSeconds);
    }

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool
    {
        return $this->inner->sealObjectWriteIntent($attemptId, $claimGeneration);
    }
}
