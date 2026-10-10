<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartPartBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;

/** Optional capabilities must be present beneath every built-in decorator. */
final class MultipartPartWriteCapabilities
{
    public static function storage(StorageBackend $storage): ?RecoverableMultipartPartBackend
    {
        $inner = $storage;
        while ($inner instanceof ObservedStorageBackend) {
            $inner = $inner->innerBackend();
        }

        return $inner instanceof RecoverableMultipartPartBackend
            && $storage instanceof RecoverableMultipartPartBackend ? $storage : null;
    }

    public static function localFilesystem(StorageBackend $storage): bool
    {
        $inner = $storage;
        while ($inner instanceof ObservedStorageBackend) {
            $inner = $inner->innerBackend();
        }

        return $inner instanceof FilesystemBackend;
    }

    public static function metadata(MetadataStore $metadata): ?MultipartPartWriteIntentStore
    {
        $inner = $metadata;
        while ($inner instanceof ObservedMetadataStore || $inner instanceof CachedMetadataStoreDecorator) {
            $inner = $inner->innerStore();
        }

        return $inner instanceof MultipartPartWriteIntentStore
            && $metadata instanceof MultipartPartWriteIntentStore ? $metadata : null;
    }
}
