<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/** Blocking staged-size verification confined to an optional EFS worker. */
final readonly class LocalEfsTempSizeFence
{
    public function __construct(
        private string $root,
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
    ) {
        if ($root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded staging root differs from EFS evidence.');
        }
    }

    public function assertSize(string $path, int $expectedSize): void
    {
        if ($expectedSize < 0 || preg_match('~^' . preg_quote($this->root, '~') . '/\.tmp/[a-f0-9]{32}$~D', $path) !== 1) {
            throw new \InvalidArgumentException('Invalid guarded staging path or size.');
        }

        $this->evidence->assertFresh();
        $directory = dirname($path);
        $linked = is_link($directory);
        $this->evidence->assertFresh();
        $resolved = realpath($directory);
        $this->evidence->assertFresh();
        if ($linked || $resolved !== $directory) {
            throw new InternalErrorException('Filesystem staging directory is unsafe.');
        }
        $this->evidence->assertFresh();
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $this->evidence->assertFresh();
        if ($stat === false || ! is_int($stat['mode'] ?? null)
            || ($stat['mode'] & 0o170000) !== 0o100000
            || ($stat['size'] ?? null) !== $expectedSize) {
            throw new InternalErrorException('Filesystem staging file is incomplete.');
        }
    }
}
