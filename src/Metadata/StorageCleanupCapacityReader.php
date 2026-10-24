<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** O(1) access to the transactionally maintained cleanup queue count. */
interface StorageCleanupCapacityReader
{
    public const int NORMAL_LIMIT = 900_000;

    public const int RECOVERY_LIMIT = 1_000_000;

    public function getStorageCleanupCount(): ?int;
}
