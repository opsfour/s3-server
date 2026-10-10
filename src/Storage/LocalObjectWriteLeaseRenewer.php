<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/**
 * Cooperative, asynchronous SQL renewal capability for local physical writes.
 * A caller must treat a timeout as permanent lease loss even if this call later
 * succeeds in SQL. Implementations must not use blocking PDO on the API loop.
 */
interface LocalObjectWriteLeaseRenewer
{
    public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent;
}
