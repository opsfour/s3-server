<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Generation-fenced renewal while a physical garbage operation is running. */
interface StorageGarbageLeaseStore
{
    public function renewStorageGarbageLease(int $id, float $newLeaseExpiresAt, float $expectedLeaseExpiresAt, int $leaseGeneration): bool;
}
