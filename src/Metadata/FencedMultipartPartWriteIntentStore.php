<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Durable one-shot local part dispatch and undispatched retirement. */
interface FencedMultipartPartWriteIntentStore extends MultipartPartWriteIntentStore
{
    public function reserveLocalMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
    ): void;

    public function armLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool;

    /** Must be called in the same transaction that publishes part metadata. */
    public function consumeLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool;

    public function retireUndispatchedLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool;

    /** Retire only after the exact worker returned terminally without attempting publication. */
    public function retireTerminalLocalMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath, string $dispatchId): bool;

    public function retireExpiredUndispatchedLocalMultipartPartWriteIntents(string $storageTier, int $limit): int;
}
