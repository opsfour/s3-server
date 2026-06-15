<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
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
        self::assertSame('restored', $object->restoreStatus);
        self::assertNotNull($object->restoredStoragePath);
        self::assertNotNull($object->restoreExpiresAt);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame([
            'objectCount' => 1,
            'bytesUsed' => $coldWrite->size,
        ], $this->metadata->getBucketStorageStats('bucket'));
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
}
