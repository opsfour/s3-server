<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\CancelledException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class DurableStorageDelete
{
    public static function run(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $bucket,
        string $storageTier,
        string $storagePath,
    ): bool {
        try {
            $storage->deleteObjectByPath($storagePath, $bucket);

            return true;
        } catch (CancelledException $deleteError) {
            try {
                self::enqueueForRetry($metadata, $bucket, $storageTier, $storagePath, $deleteError);
            } catch (CancelledException) {
                // The delete cancellation describes the uncertain physical
                // outcome and therefore remains the primary request failure.
            }
            throw $deleteError;
        } catch (\Throwable $deleteError) {
            self::enqueueForRetry($metadata, $bucket, $storageTier, $storagePath, $deleteError);

            return false;
        }
    }

    private static function enqueueForRetry(
        MetadataStore $metadata,
        string $bucket,
        string $storageTier,
        string $storagePath,
        \Throwable $deleteError,
    ): void {
        try {
            $metadata->enqueueStorageGarbage($bucket, $storageTier, $storagePath);
        } catch (CancelledException $queueError) {
            throw $queueError;
        } catch (\Throwable $queueError) {
            throw new \RuntimeException(
                'Physical delete failed and could not be persisted for retry.',
                0,
                new \RuntimeException($queueError->getMessage(), 0, $deleteError),
            );
        }
    }

    public static function runRequired(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $bucket,
        string $storageTier,
        string $storagePath,
    ): void {
        if (!self::run($metadata, $storage, $bucket, $storageTier, $storagePath)) {
            throw new \RuntimeException('Unencrypted staging object could not be physically deleted.');
        }
    }
}
