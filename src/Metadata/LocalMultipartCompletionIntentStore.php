<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** One physical local completion dispatch per durable attempt. */
interface LocalMultipartCompletionIntentStore extends MultipartWriteIntentStore
{
    /** Remove expired local attempts that were durably reserved but never dispatched. */
    public function retireDueUndispatchedLocalMultipartWriteIntents(string $storageTier, int $limit): int;

    public function armMultipartWriteIntent(string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil): bool;

    /**
     * Remove an attempt before any worker submission, whether arming did not
     * commit or committed with a lost acknowledgement.
     */
    public function retireLocalMultipartWriteIntentBeforeDispatch(string $attemptId, string $dispatchId): bool;

    /** Must be called in the transaction that publishes the completed object. */
    public function consumeMultipartWriteIntentWithDispatch(string $attemptId, string $dispatchId): bool;
}
