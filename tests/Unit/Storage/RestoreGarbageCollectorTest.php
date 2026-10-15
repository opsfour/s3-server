<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RestoreGarbageCollectorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-gc-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_collect_removes_expired_restore_copy_and_clears_restore_state(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');

        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $restored->path,
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
            'STANDARD',
        );

        $stats = (new RestoreGarbageCollector($this->metadata, $hot))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], $stats);
        self::assertNotNull($object);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertNull($object->restoreExpiresAt);

        $this->expectException(NoSuchKeyException::class);
        $hot->getObjectByPath($restored->path)->read();
    }

    public function test_collect_keeps_non_expired_restore_copy(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');

        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $restored->path,
            new \DateTimeImmutable('2020-01-03T00:00:00Z'),
            'STANDARD',
        );

        $stats = (new RestoreGarbageCollector($this->metadata, $hot))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertSame(['scanned' => 0, 'expired' => 0, 'failed' => 0], $stats);
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('restored-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
    }

    public function test_recreated_bucket_generation_does_not_clear_expired_restore_snapshot(): void
    {
        $expired = new ObjectInfo(
            bucket: 'bucket',
            key: 'archive.bin',
            size: 4,
            etag: '"etag"',
            restoredStoragePath: 'stable/restore/path',
            restoreStatus: 'restored',
            restoreExpiresAt: new \DateTimeImmutable('2020-01-01T00:00:00Z'),
            ownerId: 'owner',
            systemMetadata: [
                'storagePath' => 'stable/source/path',
                'bucketGeneration' => str_repeat('a', 32),
            ],
            restoredStorageTier: 'STANDARD',
        );
        $metadata = $this->createMock(\OpsFour\S3Server\Metadata\MetadataStore::class);
        $metadata->method('listExpiredRestoredObjects')->willReturn([$expired]);
        $metadata->method('getBucketOwner')->willReturn('owner');
        $metadata->method('getObjectMetadataByVersion')->willReturn($expired);
        $metadata->method('getBucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: str_repeat('b', 32),
        ));
        $metadata->method('transaction')->willReturnCallback(
            static fn(callable $callback): mixed => $callback(),
        );
        $metadata->expects(self::never())->method('enqueueStorageGarbage');
        $metadata->expects(self::never())->method('updateObjectRestoreState');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('deleteObjectByPath');

        self::assertSame(
            ['scanned' => 1, 'expired' => 0, 'failed' => 0],
            (new RestoreGarbageCollector($metadata, $storage))
                ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')),
        );
    }

    public function test_expired_copy_uses_persisted_tier_after_default_changes(): void
    {
        $originalTier = new InMemoryBackend();
        $newDefault = new InMemoryBackend();
        $sourceTier = new InMemoryBackend();
        foreach ([$originalTier, $newDefault, $sourceTier] as $backend) {
            $backend->createBucket('bucket');
        }

        // Both backends generate the same relative path for their first write.
        $restored = $originalTier->putObject('bucket', 'archive.bin', new ReadableBuffer('expired-copy'));
        $sentinel = $newDefault->putObject('bucket', 'archive.bin', new ReadableBuffer('keep-this-copy'));
        self::assertSame($restored->path, $sentinel->path);
        $source = $sourceTier->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-source'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'HOT_A');

        $tiers = new StorageTierRegistry([
            new StorageTier('HOT_A', $originalTier),
            new StorageTier('HOT_B', $newDefault, defaultWriteTier: true),
            new StorageTier('GLACIER', $sourceTier, restoreRequired: true),
        ]);
        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], (new RestoreGarbageCollector($this->metadata, $tiers))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        self::assertSame('keep-this-copy', \Amp\ByteStream\buffer($newDefault->getObjectByPath($sentinel->path)));
        self::assertSame('cold-source', \Amp\ByteStream\buffer($sourceTier->getObjectByPath($source->path)));
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
        $this->expectException(NoSuchKeyException::class);
        $originalTier->getObjectByPath($restored->path)->read();
    }

    public function test_missing_persisted_tier_keeps_expired_copy_referenced(): void
    {
        $originalTier = new InMemoryBackend();
        $originalTier->createBucket('bucket');
        $source = $originalTier->putObject('bucket', 'archive.bin', new ReadableBuffer('source'));
        $restored = $originalTier->putObject('bucket', 'archive.bin', new ReadableBuffer('restore'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'HOT_A');

        $wrongTier = new InMemoryBackend();
        $wrongTier->createBucket('bucket');
        $tiers = StorageTierRegistry::single($wrongTier, 'HOT_B');
        self::assertSame(['scanned' => 1, 'expired' => 0, 'failed' => 1], (new RestoreGarbageCollector($this->metadata, $tiers))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        self::assertSame($restored->path, $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
        self::assertSame('restore', \Amp\ByteStream\buffer($originalTier->getObjectByPath($restored->path)));
    }

    public function test_new_restore_queues_expired_copy_before_clearing_its_reference(): void
    {
        $restoredTier = new InMemoryBackend();
        $newDefault = new InMemoryBackend();
        $coldTier = new InMemoryBackend();
        foreach ([$restoredTier, $newDefault, $coldTier] as $backend) {
            $backend->createBucket('bucket');
        }
        $oldCopy = $restoredTier->putObject('bucket', 'archive.bin', new ReadableBuffer('old-copy'));
        $sentinel = $newDefault->putObject('bucket', 'archive.bin', new ReadableBuffer('sentinel'));
        self::assertSame($oldCopy->path, $sentinel->path);
        $source = $coldTier->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-source'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $oldCopy->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'HOT_A');
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT_A', $restoredTier),
            new StorageTier('HOT_B', $newDefault, defaultWriteTier: true),
            new StorageTier('GLACIER', $coldTier, restoreRequired: true),
        ]);

        $request = new Request(
            new RestoreCleanupTestClient(),
            'POST',
            Http::new('http://127.0.0.1/bucket/archive.bin?restore'),
            [],
            '<RestoreRequest><Days>1</Days></RestoreRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'archive.bin');
        $request->setAttribute('ownerId', 'owner');
        self::assertSame(202, (new RestoreObjectHandler($this->metadata, $tiers))->handleRequest($request)->getStatus());
        $pending = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($pending);
        self::assertSame('pending', $pending->restoreStatus);
        self::assertNull($pending->restoredStoragePath);
        self::assertNull($pending->restoredStorageTier);

        self::assertSame(1, (new StorageGarbageCollector($this->metadata, $tiers))->collect(1));
        self::assertSame('sentinel', \Amp\ByteStream\buffer($newDefault->getObjectByPath($sentinel->path)));
        self::assertSame('cold-source', \Amp\ByteStream\buffer($coldTier->getObjectByPath($source->path)));
        $this->expectException(NoSuchKeyException::class);
        $restoredTier->getObjectByPath($oldCopy->path)->read();
    }

    public function test_delete_object_removes_restored_copy_from_its_persisted_tier(): void
    {
        $restoredTier = new InMemoryBackend();
        $newDefault = new InMemoryBackend();
        $coldTier = new InMemoryBackend();
        foreach ([$restoredTier, $newDefault, $coldTier] as $backend) {
            $backend->createBucket('bucket');
        }
        $restored = $restoredTier->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $sentinel = $newDefault->putObject('bucket', 'archive.bin', new ReadableBuffer('sentinel'));
        self::assertSame($restored->path, $sentinel->path);
        $source = $coldTier->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-source'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('+1 day'), 'HOT_A');
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT_A', $restoredTier),
            new StorageTier('HOT_B', $newDefault, defaultWriteTier: true),
            new StorageTier('GLACIER', $coldTier, restoreRequired: true),
        ]);

        $request = new Request(new RestoreCleanupTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket/archive.bin'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'archive.bin');
        $request->setAttribute('ownerId', 'owner');
        self::assertSame(204, (new DeleteObjectHandler($this->metadata, $tiers))->handleRequest($request)->getStatus());
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin'));
        self::assertSame('sentinel', \Amp\ByteStream\buffer($newDefault->getObjectByPath($sentinel->path)));
        $this->expectException(NoSuchKeyException::class);
        $restoredTier->getObjectByPath($restored->path)->read();
    }

    public function test_delete_object_preserves_metadata_when_restored_tier_is_unavailable(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $source = $storage->putObject('bucket', 'archive.bin', new ReadableBuffer('source'));
        $restored = $storage->putObject('bucket', 'archive.bin', new ReadableBuffer('restored'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('+1 day'), 'MISSING');

        $request = new Request(new RestoreCleanupTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket/archive.bin'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'archive.bin');
        $request->setAttribute('ownerId', 'owner');
        try {
            (new DeleteObjectHandler($this->metadata, StorageTierRegistry::single($storage)))->handleRequest($request);
            self::fail('Deleting an object with an unknown restored tier must fail.');
        } catch (InternalErrorException) {
            self::assertSame($restored->path, $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
            self::assertSame('restored', \Amp\ByteStream\buffer($storage->getObjectByPath($restored->path)));
        }
    }

    public function test_lifecycle_expiration_queues_and_deletes_restored_copy_from_persisted_tier(): void
    {
        $restoredTier = new InMemoryBackend();
        $newDefault = new InMemoryBackend();
        $coldTier = new InMemoryBackend();
        foreach ([$restoredTier, $newDefault, $coldTier] as $backend) {
            $backend->createBucket('bucket');
        }
        $restored = $restoredTier->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $sentinel = $newDefault->putObject('bucket', 'archive.bin', new ReadableBuffer('sentinel'));
        self::assertSame($restored->path, $sentinel->path);
        $source = $coldTier->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-source'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('+1 day'), 'HOT_A');
        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("UPDATE s3_objects SET created_at = '2020-01-01T00:00:00Z', updated_at = '2020-01-01T00:00:00Z' WHERE bucket = 'bucket' AND key_name = 'archive.bin'");
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-archive',
            'status' => 'Enabled',
            'prefix' => '',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT_A', $restoredTier),
            new StorageTier('HOT_B', $newDefault, defaultWriteTier: true),
            new StorageTier('GLACIER', $coldTier, restoreRequired: true),
        ]);

        (new LifecycleExecutor($this->metadata, $newDefault, storageTiers: $tiers))->processBucket('bucket');
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin'));
        self::assertSame('sentinel', \Amp\ByteStream\buffer($newDefault->getObjectByPath($sentinel->path)));
        $this->expectException(NoSuchKeyException::class);
        $restoredTier->getObjectByPath($restored->path)->read();
    }

    public function test_collect_cleans_expired_restore_of_protected_noncurrent_null_version(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');

        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('protected-source'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('expired-restore'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->putObjectLegalHold('bucket', 'archive.bin', 'ON', 'null');
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', 'null', 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');

        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $current = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('current'));
        $currentVersion = $this->metadata->putObjectVersioned('bucket', 'archive.bin', 'owner', $current->size, '"' . $current->md5Hex . '"', 'application/octet-stream', $current->path);

        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], (new RestoreGarbageCollector($this->metadata, $hot))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        $nullVersion = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', 'null');
        $latest = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $currentVersion);
        self::assertNotNull($nullVersion);
        self::assertNotNull($latest);
        self::assertNull($nullVersion->restoredStoragePath);
        self::assertSame('ON', $this->metadata->getObjectLegalHold('bucket', 'archive.bin', 'null'));
        self::assertSame('protected-source', \Amp\ByteStream\buffer($hot->getObjectByPath($source->path)));
        self::assertSame('current', \Amp\ByteStream\buffer($hot->getObjectByPath($current->path)));
        self::assertNull($latest->restoredStoragePath);
        $this->expectException(NoSuchKeyException::class);
        $hot->getObjectByPath($restored->path)->read();
    }

    public function test_new_restore_created_during_old_copy_cleanup_is_preserved(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');

        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $expired = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('expired-copy'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $expired->path,
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
            'STANDARD',
        );

        $backend = new CallbackStorageBackend($hot, afterDelete: function () use ($hot): void {
            $new = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('new-restore'));
            $this->metadata->updateObjectRestoreStateWithTier(
                'bucket',
                'archive.bin',
                null,
                'restored',
                $new->path,
                new \DateTimeImmutable('2020-02-01T00:00:00Z'),
                'STANDARD',
            );
        });

        $stats = (new RestoreGarbageCollector($this->metadata, $backend))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], $stats);
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertNotNull($object->restoredStoragePath);
        self::assertSame('new-restore', \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
    }

    public function test_failed_garbage_queue_insert_keeps_restore_reference_and_bytes_for_retry(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');

        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("CREATE TRIGGER reject_restore_garbage BEFORE INSERT ON s3_storage_garbage BEGIN SELECT RAISE(ABORT, 'queue unavailable'); END");

        $collector = new RestoreGarbageCollector($this->metadata, $hot);
        self::assertSame(['scanned' => 1, 'expired' => 0, 'failed' => 1], $collector->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        self::assertSame($restored->path, $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
        self::assertSame('restored-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));

        $connection->exec('DROP TRIGGER reject_restore_garbage');
        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], $collector->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
    }

    public function test_failed_physical_restore_delete_keeps_durable_exact_path_cleanup(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');

        $failingStorage = $this->createMock(StorageBackend::class);
        $secret = 'backend-password-private-test-token';
        $failingStorage->expects(self::once())->method('deleteObjectByPath')
            ->with($restored->path, 'bucket')->willThrowException(new \RuntimeException($secret));
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $collector = new RestoreGarbageCollector($this->metadata, $failingStorage, $logger);
        self::assertSame(['scanned' => 1, 'expired' => 0, 'failed' => 1], $collector->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
        $rendered = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringNotContainsString($restored->path, $rendered);
        self::assertSame(hash('sha256', $restored->path), $logger->records[0]['context']['restored_storage_path_hash'] ?? null);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
        self::assertSame('restored-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));

        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($hot)))->collect(1));
        self::assertSame('cold-placeholder', \Amp\ByteStream\buffer($hot->getObjectByPath($original->path)));
        $this->expectException(NoSuchKeyException::class);
        $hot->getObjectByPath($restored->path)->read();
    }

    public function test_cancelled_delete_stops_restore_sweep_and_keeps_exact_cleanup_path(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('source'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');
        $cancelledStorage = new CallbackStorageBackend(
            $hot,
            beforeDelete: static function (): never {
                throw new \Amp\CancelledException();
            },
        );

        try {
            (new RestoreGarbageCollector($this->metadata, $cancelledStorage))
                ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));
            self::fail('Expected storage cancellation to stop the restore sweep.');
        } catch (\Amp\CancelledException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
            self::assertSame('restored', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
            self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($hot)))->collect(1));
            $this->expectException(NoSuchKeyException::class);
            $hot->getObjectByPath($restored->path)->read();
        }
    }
}

final class RestoreCleanupTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
