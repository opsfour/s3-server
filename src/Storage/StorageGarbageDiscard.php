<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;

/** Acknowledge an exact queued path only after physical deletion. */
final class StorageGarbageDiscard
{
    public static function afterDelete(
        MetadataStore $metadata,
        StorageBackend $backend,
        string $bucket,
        string $storageTier,
        string $storagePath,
    ): void {
        $backend->deleteObjectByPath($storagePath, $bucket);

        $metadata->discardStorageGarbage($bucket, $storageTier, $storagePath);
    }
}
