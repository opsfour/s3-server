<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/**
 * CAS boundary between publishing metadata and a collector claiming an
 * uncommitted physical write. A successful publish must consume the intent
 * inside the same metadata transaction that makes the object visible.
 * Admission and collector claims each require their own committed transaction
 * before the caller starts physical work.
 */
interface ObjectWriteIntentStore
{
    public const int MAX_OUTSTANDING_PER_OWNER = 4096;

    public const int MAX_OUTSTANDING_GLOBAL = 1_000_000;

    public function reserveObjectWriteIntent(ObjectWriteIntent $intent, float $leaseSeconds): ObjectWriteIntent;

    public function getObjectWriteIntent(string $attemptId): ?ObjectWriteIntent;

    public function renewObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent;

    /** Commit a one-shot local dispatch before submitting any physical task. */
    public function armObjectWriteIntent(string $attemptId, float $expectedLeaseUntil, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent;

    /** Retire only a row that could never have started physical work. */
    public function retireUndispatchedObjectWriteIntent(string $attemptId): bool;

    /** Caller must have observed this dispatch's worker terminate before its first link. */
    public function retireTerminalPreLinkObjectWriteIntent(string $attemptId, string $dispatchId): bool;

    /** Compatibility path for unarmed and pre-v28/30/32 legacy rows only. */
    public function consumeObjectWriteIntent(string $attemptId): bool;

    /** Consume an armed local intent in the metadata publication transaction. */
    public function consumeArmedObjectWriteIntent(string $attemptId, string $dispatchId): bool;

    public function abandonObjectWriteIntent(string $attemptId): bool;

    /**
     * Claims only paths assigned to the specified physical storage tier.
     *
     * @return list<ObjectWriteIntent>
     */
    public function claimDueObjectWriteIntents(string $storageTier, int $limit, float $leaseSeconds): array;

    /** Remove expired active reservations that were never dispatched. */
    public function retireExpiredUndispatchedObjectWriteIntents(string $storageTier, int $limit): int;

    public function renewObjectWriteClaim(string $attemptId, int $claimGeneration, float $expectedLeaseUntil, float $leaseSeconds): ?ObjectWriteIntent;

    public function sealObjectWriteIntent(string $attemptId, int $claimGeneration): bool;
}
