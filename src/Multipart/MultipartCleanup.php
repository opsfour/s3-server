<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Multipart;

use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

final class MultipartCleanup
{
    /**
     * Atomically consumes an upload and persists cleanup work.
     *
     * The caller must hold the owner write lock inside a metadata transaction.
     *
     * @return list<array{part_number: int, etag: string, size: int, storage_path: string}>
     */
    public static function stage(
        MetadataStore $metadata,
        string $bucket,
        string $key,
        string $uploadId,
        string $ownerId,
        string $storageTier,
    ): array {
        $upload = $metadata->getMultipartUpload($uploadId);
        if (
            $upload === null
            || $upload['bucket'] !== $bucket
            || $upload['key_name'] !== $key
            || $upload['owner_id'] !== $ownerId
        ) {
            throw new NoSuchUploadException();
        }

        $parts = $metadata->getParts($uploadId);
        foreach ($parts as $part) {
            $metadata->enqueueStorageGarbage($bucket, $storageTier, $part['storage_path']);
        }
        $metadata->deleteMultipartUpload($uploadId);

        return $parts;
    }

    /**
     * @param list<array{part_number: int, etag: string, size: int, storage_path: string}> $parts
     */
    public static function clean(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $bucket,
        string $key,
        string $uploadId,
        string $storageTier,
        array $parts,
    ): void {
        try {
            $storage->abortMultipartUpload($bucket, $key, $uploadId);
        } catch (\Throwable) {
            return;
        }

        foreach ($parts as $part) {
            try {
                $metadata->discardStorageGarbage($bucket, $storageTier, $part['storage_path']);
            } catch (\Throwable) {
                // The collector will remove an already-deleted path idempotently.
            }
        }
    }
}
