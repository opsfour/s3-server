<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/** Acknowledge an exact queued path only after physical deletion and its final local evidence check. */
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

        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if ($backend instanceof FilesystemBackend) {
            $backend->assertHostEvidenceFresh();
        }

        $metadata->discardStorageGarbage($bucket, $storageTier, $storagePath);
    }
}
