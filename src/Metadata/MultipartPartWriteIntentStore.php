<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional durable reservation for ciphertext part paths before storage I/O. */
interface MultipartPartWriteIntentStore
{
    /** Maximum retained recovery intents for each upload owner. */
    public const int MAX_OUTSTANDING_INTENTS = 4096;

    /** Maximum retained recovery intents across all upload owners. */
    public const int MAX_OUTSTANDING_GLOBAL_PART_INTENTS = 1_000_000;

    /** Starts its own owner-locked transaction; callers must not already hold one. */
    public function createMultipartPartWriteIntent(
        string $bucket,
        string $bucketGeneration,
        string $storageTier,
        string $uploadId,
        string $storagePath,
    ): void;

    /** Must be called in the same transaction that commits the part metadata. */
    public function consumeMultipartPartWriteIntent(string $bucket, string $uploadId, string $storagePath): bool;
}
