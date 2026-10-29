<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Fixed-cardinality node-local temporary-capacity state for health and metrics. */
final readonly class StorageTempBudgetSnapshot
{
    public function __construct(
        public int $budgetBytes,
        public int $reservedBytes,
        public int $logicalAvailableBytes,
        public int $filesystemFreeBytes,
        public int $filesystemReserveBytes,
        public int $effectiveAvailableBytes,
        public int $activeReservations,
        public int $unsafeEntries,
        public int $orphanedBytes,
    ) {}

    public function mutationReady(): bool
    {
        return $this->unsafeEntries === 0
            && $this->filesystemFreeBytes >= $this->filesystemReserveBytes
            && $this->effectiveAvailableBytes === $this->logicalAvailableBytes;
    }
}
