<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class StorageGarbageCollectorTest extends TestCase
{
    public function test_persisted_storage_garbage_is_deleted_and_acknowledged(): void
    {
        $path = sys_get_temp_dir() . '/s3-storage-gc-' . bin2hex(random_bytes(5)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $write = $storage->putObject('bucket', 'old.txt', new ReadableBuffer('obsolete'));

        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $write->path);
        $collector = new StorageGarbageCollector(
            $metadata,
            StorageTierRegistry::single($storage),
        );

        self::assertSame(1, $collector->collect());
        self::assertSame([], $metadata->dequeueStorageGarbage(10));

        @unlink($path);
        @unlink($path . '-wal');
        @unlink($path . '-shm');
    }

    public function test_cancelled_collection_does_not_claim_storage_garbage(): void
    {
        $path = sys_get_temp_dir() . '/s3-storage-gc-cancel-' . bin2hex(random_bytes(5)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $storage = new InMemoryBackend();
        $cancellation = new \Amp\DeferredCancellation();
        $cancellation->cancel();
        $collector = new StorageGarbageCollector(
            $metadata,
            StorageTierRegistry::single($storage),
        );

        $this->expectException(\Amp\CancelledException::class);
        try {
            $collector->collect(10, $cancellation->getCancellation());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
