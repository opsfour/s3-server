<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\CancelledException;
use OpsFour\S3Server\Exception\RecoveryStorageCleanupCapacityException;
use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RecoveryStorageGarbageStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

final class DurableStorageDelete
{
    public static function run(
        MetadataStore $metadata,
        StorageBackend $storage,
        string $bucket,
        string $storageTier,
        string $storagePath,
        string $origin = 'unspecified',
        ?string $ownerId = null,
    ): bool {
        $physical = $storage;
        while ($physical instanceof ObservedStorageBackend) {
            $physical = $physical->innerBackend();
        }
        if ($physical instanceof AwsS3StorageBackend) {
            if ($ownerId === null || $ownerId === '') {
                throw new \LogicException('AWS recovery cleanup requires the original owner.');
            }
            self::enqueueForRetry($metadata, $bucket, $storageTier, $storagePath, new \RuntimeException('AWS cleanup requires a claimed path.'), $origin, $ownerId);
            return false;
        }
        try {
            $storage->deleteObjectByPath($storagePath, $bucket);

            return true;
        } catch (CancelledException $deleteError) {
            try {
                self::enqueueForRetry($metadata, $bucket, $storageTier, $storagePath, $deleteError, $origin, $ownerId);
            } catch (\Throwable) {
                // The delete cancellation describes the uncertain physical
                // outcome and therefore remains the primary request failure.
            }
            throw $deleteError;
        } catch (\Throwable $deleteError) {
            self::enqueueForRetry($metadata, $bucket, $storageTier, $storagePath, $deleteError, $origin, $ownerId);

            return false;
        }
    }

    private static function enqueueForRetry(
        MetadataStore $metadata,
        string $bucket,
        string $storageTier,
        string $storagePath,
        \Throwable $deleteError,
        string $origin,
        ?string $ownerId,
    ): void {
        try {
            if (! $metadata instanceof RecoveryStorageGarbageStore) {
                throw new \RuntimeException('Protected storage cleanup recovery admission is unavailable.');
            }
            if ($ownerId === null) {
                $metadata->enqueueRecoveryStorageGarbage($bucket, $storageTier, $storagePath);
            } else {
                $metadata->enqueueRecoveryStorageGarbage($bucket, $storageTier, $storagePath, $ownerId);
            }
        } catch (CancelledException $queueError) {
            throw $queueError;
        } catch (StorageCleanupCapacityException $queueError) {
            $count = $metadata instanceof StorageCleanupCapacityReader
                ? self::safeCleanupCount($metadata)
                : null;
            $failure = new RecoveryStorageCleanupCapacityException(
                $bucket,
                $storageTier,
                hash('sha256', $storagePath),
                $origin,
                $count,
                $deleteError,
                $queueError::class,
            );
            self::logRecoveryRejection($failure, $deleteError);
            throw $failure;
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
        string $origin = 'unspecified',
        ?string $ownerId = null,
    ): void {
        if (!self::run($metadata, $storage, $bucket, $storageTier, $storagePath, $origin, $ownerId)) {
            throw new \RuntimeException('Unencrypted staging object could not be physically deleted.');
        }
    }

    private static function safeCleanupCount(StorageCleanupCapacityReader $metadata): ?int
    {
        try {
            return $metadata->getStorageCleanupCount();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function logRecoveryRejection(RecoveryStorageCleanupCapacityException $failure, \Throwable $deleteError): void
    {
        $encoded = json_encode([
            'event' => 'storage_cleanup_recovery_rejected',
            'bucket' => $failure->bucket,
            'storage_tier' => $failure->storageTier,
            'origin' => $failure->origin,
            'storage_path_sha256' => $failure->storagePathHash,
            'cleanup_count' => $failure->cleanupCount,
            'delete_exception' => $deleteError::class,
            'queue_exception' => $failure->queueException,
        ], JSON_UNESCAPED_SLASHES);
        error_log(is_string($encoded) ? $encoded : '{"event":"storage_cleanup_recovery_rejected"}');
    }
}
