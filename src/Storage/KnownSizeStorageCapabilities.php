<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Observability\ObservedStorageBackend;

/** Optional exact-size capabilities must survive decorators without being invented by them. */
final class KnownSizeStorageCapabilities
{
    public static function storage(StorageBackend $storage): ?KnownSizeStorageBackend
    {
        $inner = self::inner($storage);

        return $inner instanceof KnownSizeStorageBackend && $storage instanceof KnownSizeStorageBackend
            ? $storage
            : null;
    }

    public static function recoverable(StorageBackend $storage): ?KnownSizeRecoverableStorageBackend
    {
        $inner = self::inner($storage);

        return $inner instanceof KnownSizeRecoverableStorageBackend
            && $storage instanceof KnownSizeRecoverableStorageBackend
                ? $storage
                : null;
    }

    private static function inner(StorageBackend $storage): StorageBackend
    {
        while ($storage instanceof ObservedStorageBackend) {
            $storage = $storage->innerBackend();
        }

        return $storage;
    }
}
