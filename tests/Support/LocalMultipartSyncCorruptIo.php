<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;

/** Serializable worker fault: a synchronized EFS temp inode changes at the same length. */
final class LocalMultipartSyncCorruptIo implements LocalObjectWriteIo
{
    private readonly NativeLocalObjectWriteIo $inner;

    public function __construct()
    {
        $this->inner = new NativeLocalObjectWriteIo();
    }

    public function syncFile($file): bool
    {
        if (! $this->inner->syncFile($file)
            || @fseek($file, 0, SEEK_SET) !== 0
            || @fwrite($file, 'X') !== 1) {
            return false;
        }

        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        return $this->inner->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }
}
