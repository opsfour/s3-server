<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Linux/POSIX file operations; actual EFS semantics require a two-host gate. */
final class NativeLocalObjectWriteIo implements LocalObjectWriteIo
{
    public function syncFile($file): bool
    {
        return @fsync($file);
    }

    public function syncDirectory(string $path): bool
    {
        $directory = @fopen($path, 'rb');
        if ($directory === false) {
            return false;
        }

        $synchronized = @fsync($directory);

        return @fclose($directory) && $synchronized;
    }

    public function link(string $source, string $target): bool
    {
        return @link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return @rename($source, $target);
    }
}
