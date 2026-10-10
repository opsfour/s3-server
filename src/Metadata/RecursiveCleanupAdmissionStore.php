<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Read-only admission check for storage cleanup that is not exact-path bound. */
interface RecursiveCleanupAdmissionStore
{
    public function assertNoUnsafeRecursiveCleanup(string $storageTier): void;
}
