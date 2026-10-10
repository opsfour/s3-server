<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Atomically persists a restored copy's physical path and tier. */
interface RestoredCopyTierStore
{
    public function updateObjectRestoreStateWithTier(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
        ?string $restoredStorageTier = null,
    ): void;
}
