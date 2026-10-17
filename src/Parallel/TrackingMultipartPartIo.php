<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;
use OpsFour\S3Server\Storage\EfsAwareLocalObjectWriteIo;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;

/** Records whether a link call can have reached the shared filesystem. */
final class TrackingMultipartPartIo implements EfsAwareLocalObjectWriteIo
{
    private bool $publicationAttempted = false;

    public function __construct(private readonly LocalObjectWriteIo $inner) {}

    public function publicationAttempted(): bool
    {
        return $this->publicationAttempted;
    }

    public function syncFile($file): bool
    {
        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function syncDirectoryWithEvidence(
        string $path,
        EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
    ): bool {
        if (! $this->inner instanceof EfsAwareLocalObjectWriteIo) {
            throw new \RuntimeException('Guarded multipart worker I/O cannot synchronize EFS safely.');
        }

        return $this->inner->syncDirectoryWithEvidence($path, $evidence);
    }

    public function link(string $source, string $target): bool
    {
        $this->publicationAttempted = true;
        return $this->inner->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }
}
