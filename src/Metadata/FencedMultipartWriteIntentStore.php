<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Generation-fenced renewal and release of an abandoned completion claim. */
interface FencedMultipartWriteIntentStore
{
    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool;

    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool;
}
