<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\BucketInfo;

/**
 * Atomically stages multipart parts for one observed bucket incarnation.
 *
 * Implementations must reject a mismatched upload before staging anything.
 * Legacy uploads with no generation may be included only for the observed
 * owner when bucket deletion consumes all uploads before name reuse.
 */
interface GenerationBoundBucketMultipartCleanupStore
{
    /** The caller holds the owner lock inside a metadata transaction. */
    public function stageBucketMultipartCleanupForBucket(BucketInfo $bucket, string $storageTier): void;
}
