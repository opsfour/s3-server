<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

final class NativeLocalEfsDeleteIo implements LocalEfsDeleteIo
{
    public function listDirectory(string $path): array|false
    {
        $entries = @scandir($path);
        return $entries === false ? false : array_values(array_diff($entries, ['.', '..']));
    }

    public function isDirectory(string $path): bool
    {
        clearstatcache(true, $path);
        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        clearstatcache(true, $path);
        return is_file($path);
    }

    public function isLink(string $path): bool
    {
        clearstatcache(true, $path);
        return is_link($path);
    }

    public function realPath(string $path): string|false
    {
        clearstatcache(true, $path);
        return realpath($path);
    }

    public function unlinkFile(string $path): bool
    {
        return @unlink($path);
    }

    public function syncDirectory(string $path): bool
    {
        return (new NativeLocalObjectWriteIo())->syncDirectory($path);
    }
}
