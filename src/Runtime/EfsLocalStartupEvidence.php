<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Internal startup capability: construction and assertFresh() must only read
 * root-owned local state, never the storage root or its parent mount. Root
 * identity can be checked only after the preflight confirms the mount.
 * No production implementation or public configuration exists yet.
 */
interface EfsLocalStartupEvidence extends EfsHostEvidence
{
    /** Compare the post-mount-check root dev/inode to the pinned heartbeat. */
    public function assertStorageIdentity(string $canonicalRoot): void;
}
