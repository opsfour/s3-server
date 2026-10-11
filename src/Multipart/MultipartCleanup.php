<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Multipart;

use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

final class MultipartCleanup
{
    /** @param array<string, mixed> $upload */
    public static function pinnedStorageTier(array $upload): string
    {
        $storageTier = $upload['storage_tier'] ?? null;
        if (! is_string($storageTier) || $storageTier === '') {
            throw new InternalErrorException('Multipart upload has no pinned storage tier.');
        }

        return $storageTier;
    }

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
        ?string $bucketGeneration = null,
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
        if ($bucketGeneration !== null && ($upload['bucket_generation'] ?? '') !== $bucketGeneration) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }
        if (self::pinnedStorageTier($upload) !== $storageTier) {
            throw new InternalErrorException('Multipart cleanup storage tier does not match the upload.');
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
        foreach ($parts as $part) {
            try {
                // Only committed parts were staged above. Other files in this
                // upload directory may belong to an in-flight write attempt;
                // its own write intent remains responsible for those bytes.
                \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                    $metadata,
                    $storage,
                    $bucket,
                    $storageTier,
                    $part['storage_path'],
                );
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // The staged delete_path job remains for the collector.
                continue;
            }
        }
    }
}
