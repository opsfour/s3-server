<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Additive generation fences for leased notification, tier and restore jobs. */
interface FencedQueueStore
{
    public function updateNotificationStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, float $leaseExpiresAt, int $leaseGeneration): bool;

    public function updateTierTransitionJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $targetStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool;

    public function renewTierTransitionJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool;

    public function updateRestoreJobStatusLease(int $id, string $status, ?string $error, ?float $nextAttemptAt, bool $incrementAttempts, ?string $restoredStoragePath, float $leaseExpiresAt, int $leaseGeneration): bool;

    public function renewRestoreJobLeaseFenced(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool;
}
