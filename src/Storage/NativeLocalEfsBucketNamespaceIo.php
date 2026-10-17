<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;

final class NativeLocalEfsBucketNamespaceIo implements LocalEfsBucketNamespaceIo
{
    public function lstat(string $path): array|false
    {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    public function listDirectory(string $path): array|false
    {
        $entries = @scandir($path);
        return $entries === false ? false : array_values(array_diff($entries, ['.', '..']));
    }

    public function mkdir(string $path): bool
    {
        return @mkdir($path, 0700, false);
    }

    public function syncDirectory(string $path, ?Closure $afterSyscall = null): bool
    {
        return (new NativeLocalObjectWriteIo())->syncDirectoryWithCallback($path, $afterSyscall);
    }
}
