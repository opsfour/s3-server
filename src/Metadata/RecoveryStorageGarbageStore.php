<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Protected cleanup admission available only after physical deletion fails. */
interface RecoveryStorageGarbageStore
{
    public function enqueueRecoveryStorageGarbage(string $bucket, string $storageTier, string $storagePath, ?string $ownerId = null): void;
}
