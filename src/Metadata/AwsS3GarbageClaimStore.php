<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Storage\AwsS3GarbageDeletion;

/** PostgreSQL proof for one exact, generation-leased AWS cleanup path. */
interface AwsS3GarbageClaimStore
{
    /**
     * Hold the owner reference-write lock across provider I/O, stop renewal
     * after provider absence is confirmed, and complete the exact live claim.
     *
     * @param \Closure(): float $currentLeaseUntil
     * @param \Closure(): void $stopRenewal
     */
    public function deleteAwsGarbageClaim(
        int $id,
        string $bucket,
        string $tier,
        string $path,
        string $ownerId,
        int $generation,
        \Closure $currentLeaseUntil,
        \Closure $stopRenewal,
        AwsS3GarbageDeletion $deletion,
    ): bool;
}
