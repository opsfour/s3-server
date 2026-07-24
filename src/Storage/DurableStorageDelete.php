<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MetadataStore;

final class DurableStorageDelete
{
    public static function run(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $bucket,
        string $storageTier,
        string $storagePath,
    ): void {
        try {
            $storage->deleteObjectByPath($storagePath, $bucket);
        } catch (\Throwable $deleteError) {
            try {
                $metadata->enqueueStorageGarbage($bucket, $storageTier, $storagePath);
            } catch (\Throwable $queueError) {
                throw new \RuntimeException(
                    'Physical delete failed and could not be persisted for retry.',
                    0,
                    new \RuntimeException($queueError->getMessage(), 0, $deleteError),
                );
            }
        }
    }
}
