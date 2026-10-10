<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Fresh SQL proof before a collector replaces an uncommitted local part with a seal. */
interface LocalMultipartPartSealGuard
{
    public function canSealLocalMultipartPartClaim(
        int $garbageId,
        string $storagePath,
        int $leaseGeneration,
    ): bool;
}
