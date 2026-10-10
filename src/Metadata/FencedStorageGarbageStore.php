<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/**
 * Additive claim-generation fence for physical storage cleanup workers.
 *
 * MetadataStore's older deadline-only methods remain available for custom
 * stores. Built-in workers use both the deadline and generation so a rapid
 * reclaim cannot reuse the prior worker's acknowledgement authority.
 */
interface FencedStorageGarbageStore
{
    public function completeStorageGarbageLease(int $id, float $leaseExpiresAt, int $leaseGeneration): bool;

    public function retryStorageGarbageLease(int $id, string $error, float $nextAttemptAt, float $leaseExpiresAt, int $leaseGeneration): bool;
}
