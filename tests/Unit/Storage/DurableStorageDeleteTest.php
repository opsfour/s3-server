<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\CancelledException;
use OpsFour\S3Server\Exception\RecoveryStorageCleanupCapacityException;
use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RecoveryStorageGarbageStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Storage\DurableStorageDelete;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DurableStorageDeleteTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function entryPoints(): iterable
    {
        yield 'best-effort caller' => [false];
        yield 'required caller' => [true];
    }

    #[DataProvider('entryPoints')]
    public function test_cancelled_delete_is_durably_queued_before_same_cancellation_propagates(bool $required): void
    {
        $cancellation = new CancelledException();
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('bucket/path', 'bucket')
            ->willThrowException($cancellation);
        $metadata = $this->recoveryMetadataMock();
        $metadata->expects(self::once())->method('enqueueRecoveryStorageGarbage')
            ->with('bucket', 'STANDARD', 'bucket/path');

        try {
            if ($required) {
                DurableStorageDelete::runRequired($metadata, $storage, 'bucket', 'STANDARD', 'bucket/path');
            } else {
                DurableStorageDelete::run($metadata, $storage, 'bucket', 'STANDARD', 'bucket/path');
            }
            self::fail('Cancellation must propagate after durable cleanup staging.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_queue_cancellation_is_not_converted_when_delete_already_failed(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('storage unavailable'));
        $cancellation = new CancelledException();
        $metadata = $this->recoveryMetadataMock();
        $metadata->method('enqueueRecoveryStorageGarbage')->willThrowException($cancellation);

        try {
            DurableStorageDelete::run($metadata, $storage, 'bucket', 'STANDARD', 'bucket/path');
            self::fail('Queue cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_delete_cancellation_remains_primary_when_retry_queue_is_also_cancelled(): void
    {
        $deleteCancellation = new CancelledException();
        $queueCancellation = new CancelledException();
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')->willThrowException($deleteCancellation);
        $metadata = $this->recoveryMetadataMock();
        $metadata->expects(self::once())->method('enqueueRecoveryStorageGarbage')
            ->willThrowException($queueCancellation);

        try {
            DurableStorageDelete::run($metadata, $storage, 'bucket', 'STANDARD', 'bucket/path');
            self::fail('The original delete cancellation must remain primary.');
        } catch (CancelledException $error) {
            self::assertSame($deleteCancellation, $error);
        }
    }

    public function test_recovery_hard_cap_surfaces_safe_context_and_retains_delete_failure(): void
    {
        $deleteError = new \RuntimeException('storage unavailable');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')->willThrowException($deleteError);
        $metadata = $this->recoveryCapacityMetadataMock();
        $metadata->expects(self::once())->method('enqueueRecoveryStorageGarbage')
            ->willThrowException(new StorageCleanupCapacityException());
        $metadata->expects(self::once())->method('getStorageCleanupCount')->willReturn(1_000_000);

        try {
            DurableStorageDelete::run(
                $metadata,
                $storage,
                'private-bucket',
                'COLD',
                '/private/storage/object',
                'unit_test_cleanup',
            );
            self::fail('Recovery hard-cap rejection must fail visibly.');
        } catch (RecoveryStorageCleanupCapacityException $error) {
            self::assertSame($deleteError, $error->getPrevious());
            self::assertSame('private-bucket', $error->bucket);
            self::assertSame('COLD', $error->storageTier);
            self::assertSame('unit_test_cleanup', $error->origin);
            self::assertSame(1_000_000, $error->cleanupCount);
            self::assertSame(hash('sha256', '/private/storage/object'), $error->storagePathHash);
            self::assertStringNotContainsString('/private/storage/object', (string) $error);
        }
    }

    private function recoveryMetadataMock(): MetadataStore&RecoveryStorageGarbageStore&MockObject
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, RecoveryStorageGarbageStore::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(RecoveryStorageGarbageStore::class, $metadata);

        return $metadata;
    }

    private function recoveryCapacityMetadataMock(): MetadataStore&RecoveryStorageGarbageStore&StorageCleanupCapacityReader&MockObject
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            RecoveryStorageGarbageStore::class,
            StorageCleanupCapacityReader::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(RecoveryStorageGarbageStore::class, $metadata);
        self::assertInstanceOf(StorageCleanupCapacityReader::class, $metadata);

        return $metadata;
    }
}
