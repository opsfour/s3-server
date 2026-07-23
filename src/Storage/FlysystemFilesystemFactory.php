<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Reconstructs a Flysystem filesystem inside a worker process.
 *
 * Implementations and all constructor properties must be serializable.
 */
interface FlysystemFilesystemFactory
{
    public function createFilesystem(): FilesystemOperator;

    /**
     * Stable non-secret identity used to cache the filesystem in each worker.
     */
    public function cacheKey(): string;
}
