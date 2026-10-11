<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\CancelledException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\DurableStorageDelete;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('enqueueStorageGarbage')
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
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('enqueueStorageGarbage')->willThrowException($cancellation);

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
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('enqueueStorageGarbage')
            ->willThrowException($queueCancellation);

        try {
            DurableStorageDelete::run($metadata, $storage, 'bucket', 'STANDARD', 'bucket/path');
            self::fail('The original delete cancellation must remain primary.');
        } catch (CancelledException $error) {
            self::assertSame($deleteCancellation, $error);
        }
    }
}
