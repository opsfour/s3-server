<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Storage\LocalObjectWriteIo;

/** Terminates the isolated worker before it can return a task result. */
final class LocalObjectWriteCrashIo implements LocalObjectWriteIo
{
    /** @param resource $file */
    public function syncFile($file): bool
    {
        exit(71);
    }

    public function syncDirectory(string $path): bool
    {
        return true;
    }

    public function link(string $source, string $target): bool
    {
        return true;
    }

    public function rename(string $source, string $target): bool
    {
        return true;
    }
}
