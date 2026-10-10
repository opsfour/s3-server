<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional read capability for the shared object and part intent counters. */
interface WriteIntentCapacityReader
{
    /**
     * Null means the wrapped metadata store does not support this metric.
     *
     * @return array{object: int, part: int}|null
     */
    public function getWriteIntentCapacityCounts(): ?array;
}
