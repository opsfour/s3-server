<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;

final readonly class SlowLocalFlysystemFactory implements FlysystemFilesystemFactory
{
    public function __construct(
        private string $rootPath,
        private int $writeDelayMicroseconds = 0,
    ) {}

    public function createFilesystem(): FilesystemOperator
    {
        return new Filesystem(new SlowLocalFlysystemAdapter(
            $this->rootPath,
            $this->writeDelayMicroseconds,
        ));
    }

    public function cacheKey(): string
    {
        return self::class . ':' . hash('sha256', $this->rootPath . "\0" . $this->writeDelayMicroseconds);
    }
}

final class SlowLocalFlysystemAdapter extends LocalFilesystemAdapter
{
    public function __construct(string $rootPath, private readonly int $writeDelayMicroseconds)
    {
        parent::__construct($rootPath);
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if ($this->writeDelayMicroseconds > 0) {
            usleep($this->writeDelayMicroseconds);
        }

        parent::writeStream($path, $contents, $config);
    }
}
