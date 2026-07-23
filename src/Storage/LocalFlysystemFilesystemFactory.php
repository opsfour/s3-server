<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

final readonly class LocalFlysystemFilesystemFactory implements FlysystemFilesystemFactory
{
    public function __construct(private string $rootPath) {}

    public function createFilesystem(): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter($this->rootPath));
    }

    public function cacheKey(): string
    {
        return self::class . ':' . hash('sha256', $this->rootPath);
    }
}
