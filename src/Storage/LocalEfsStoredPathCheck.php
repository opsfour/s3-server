<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;

/** Blocking stored-path confinement check for an optional EFS process worker. */
final readonly class LocalEfsStoredPathCheck
{
    public function __construct(
        private string $root,
        private EfsHostEvidenceReader $evidence,
    ) {
        if ($root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded stored-path root differs from EFS evidence.');
        }
    }

    public function isConfined(string $path): bool
    {
        $this->evidence->assertFresh();
        $prefix = rtrim($this->root, '/') . '/';
        if (! str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Stored object path is outside the storage namespace.');
        }

        clearstatcache(true, $this->root);
        clearstatcache(true, $path);
        $root = realpath($this->root);
        $this->evidence->assertFresh();
        if ($root === false || $root !== $this->root) {
            throw new InternalErrorException('Filesystem storage root is unavailable.');
        }

        $resolved = realpath($path);
        $this->evidence->assertFresh();
        if ($resolved === false) {
            // PHP directory iterators cannot reliably distinguish EOF from a
            // silent NFS read failure. A referenced path with no realpath is
            // therefore uncertain; never classify it as a safe absence.
            throw new InternalErrorException('Stored object path cannot be resolved safely.');
        }
        if (! str_starts_with($resolved, $prefix)) {
            throw new \InvalidArgumentException('Stored object path escapes the storage root.');
        }

        return true;
    }
}
