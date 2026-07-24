<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class RestoreExecutorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-executor-' . bin2hex(random_bytes(4)) . '.sqlite';
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

    public function test_processes_restore_job_into_hot_copy_and_restore_metadata(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $coldWrite->path, 2);
        $event = null;
        $notifications = new NotificationDispatcher($this->metadata);
        $notifications->listen('s3:ObjectRestore:Completed', static function (S3Event $received) use (&$event): void {
            $event = $received;
        });

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]), notifications: $notifications);

        $stats = $executor->processNext(10);
        \Amp\delay(0.01);

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertNotNull($object->restoredStoragePath);
        self::assertNotNull($object->restoreExpiresAt);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame([
            'objectCount' => 1,
            'bytesUsed' => $coldWrite->size,
        ], $this->metadata->getBucketStorageStats('bucket'));
        self::assertInstanceOf(S3Event::class, $event);
        self::assertSame('s3:ObjectRestore:Completed', $event->name);
        self::assertSame('archive.bin', $event->key);
        self::assertSame('GLACIER', $event->attributes['sourceTier']);
        self::assertSame(2, $event->attributes['restoreDays']);
    }

    public function test_restores_encrypted_ciphertext_when_physical_size_differs_from_plaintext_metadata(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $ciphertext = 'encrypted-payload-plus-authentication-tag';
        $coldWrite = $cold->putObject('bucket', 'encrypted.bin', new ReadableBuffer($ciphertext));
        $this->metadata->putObjectMetadata(
            'bucket',
            'encrypted.bin',
            'owner',
            5,
            '"' . md5('plain') . '"',
            'application/octet-stream',
            $coldWrite->path,
            userMetadata: ['__sse-algorithm' => 'AES256'],
        );
        $this->metadata->updateObjectPlacement('bucket', 'encrypted.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->enqueueRestoreJob('bucket', 'encrypted.bin', null, 'GLACIER', $coldWrite->path, 2);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($object);
        self::assertNotNull($object->restoredStoragePath);
        self::assertSame($ciphertext, \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame(5, $object->size);
    }

    public function test_stale_restore_job_after_overwrite_is_completed_without_restoring_old_data(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('old-cold'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $coldWrite->path, 2);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $hotWrite = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('new-hot'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $hotWrite->size, '"' . $hotWrite->md5Hex . '"', 'application/octet-stream', $hotWrite->path);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $stats = $executor->processNext(10);
        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertSame($hotWrite->path, $object->systemMetadata['storagePath']);
        self::assertSame('new-hot', \Amp\ByteStream\buffer($hot->getObjectByPath($object->systemMetadata['storagePath'])));
    }

    public function test_overwrite_during_physical_restore_does_not_attach_old_restore_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $old = $cold->putObject('bucket', 'racing.bin', new ReadableBuffer('old-cold'));
        $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'racing.bin', null, 'GLACIER', 'GLACIER', $old->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'racing.bin', null, 'GLACIER', $old->path, 2);
        $this->metadata->updateObjectRestoreState('bucket', 'racing.bin', null, 'pending');

        $target = new CallbackStorageBackend($hot, afterPut: function () use ($hot): void {
            $new = $hot->putObject('bucket', 'racing.bin', new ReadableBuffer('new-hot'));
            $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        });
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $target, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'racing.bin');
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertSame('new-hot', \Amp\ByteStream\buffer($hot->getObjectByPath($object->systemMetadata['storagePath'])));
        self::assertNotNull($target->lastWrite);

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $hot->getObjectByPath($target->lastWrite->path)->read();
    }

    public function test_deleted_object_restore_job_is_completed_as_stale_noop(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'deleted.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'deleted.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'deleted.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'deleted.bin', null, 'GLACIER', $coldWrite->path, 2);
        $this->metadata->deleteObjectMetadata('bucket', 'deleted.bin');

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $stats = $executor->processNext(10);
        $job = $this->metadata->getRestoreJob($jobId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'deleted.bin'));
    }

    public function test_lost_processing_lease_cannot_attach_or_reopen_completed_restore_job(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $source = $cold->putObject('bucket', 'lease.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'lease.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'lease.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'lease.bin', null, 'GLACIER', $source->path, 1);
        $target = new CallbackStorageBackend(
            $hot,
            afterPut: fn() => $this->metadata->updateRestoreJobStatus(
                $jobId,
                'completed',
                incrementAttempts: false,
            ),
        );
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $target, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'lease.bin');
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
        self::assertNotNull($object);
        self::assertNull($object->restoredStoragePath);
    }

    public function test_cancelled_batch_does_not_claim_another_restore_job(): void
    {
        $storage = new InMemoryBackend();
        $cancellation = new \Amp\DeferredCancellation();
        $cancellation->cancel();
        $executor = new RestoreExecutor(
            $this->metadata,
            StorageTierRegistry::single($storage),
        );

        $this->expectException(\Amp\CancelledException::class);
        $executor->processNext(10, $cancellation->getCancellation());
    }
}
