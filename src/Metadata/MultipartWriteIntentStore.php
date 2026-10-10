<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Metadata operations that must participate in the caller's transaction. */
interface MultipartWriteIntentStore
{
    /** Maximum retained completion attempts for each upload owner. */
    public const int MAX_OUTSTANDING_COMPLETION_INTENTS = 4096;

    /** Maximum retained completion attempts across all owners. */
    public const int MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL = 1_000_000;

    /** Shared clock for intent deadlines across API nodes. */
    public function multipartWriteIntentNow(): float;

    /** Unique active_upload_id prevents simultaneous completion attempts. */
    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void;

    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent;

    /** Extend an unexpired active claim and its collector deadline by CAS. */
    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool;

    /** Release the active claim while retaining both paths for recovery. */
    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool;

    /** Fence the active completion when its upload is explicitly aborted. */
    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int;

    /** Consume the active attempt in the same transaction as object metadata. */
    public function consumeMultipartWriteIntent(string $attemptId): bool;

    /**
     * Claim expired active attempts and due abandoned tombstones for exact-path
     * reconciliation. The limit is clamped to 1..1000. Claims require their
     * own committed transaction. Claimed rows remain durable and are never
     * deleted here.
     *
     * @return list<MultipartWriteIntent>
     */
    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array;

    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool;
}
