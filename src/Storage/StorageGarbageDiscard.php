<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

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
        $physical = $backend;
        while ($physical instanceof ObservedStorageBackend) {
            $physical = $physical->innerBackend();
        }
        if ($physical instanceof AwsS3StorageBackend) {
            // The exact queued path remains until the generation-leased AWS collector proves absence.
            return;
        }
        $backend->deleteObjectByPath($storagePath, $bucket);

        $metadata->discardStorageGarbage($bucket, $storageTier, $storagePath);
    }
}
