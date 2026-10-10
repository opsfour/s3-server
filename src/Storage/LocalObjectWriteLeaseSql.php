<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** Cooperative SQL calls; implementations must not block the API event loop. */
interface LocalObjectWriteLeaseSql
{
    public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent;

    public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent;
}
