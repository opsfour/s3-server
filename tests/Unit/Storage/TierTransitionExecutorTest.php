<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use PHPUnit\Framework\TestCase;

final class TierTransitionExecutorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-tier-transition-executor-' . bin2hex(random_bytes(4)) . '.sqlite';
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

    public function test_processes_transition_job_with_copy_verify_commit_and_source_cleanup(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'archive.bin',
            ownerId: 'owner',
            size: $source->size,
            etag: '"' . $source->md5Hex . '"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            sourceTier: 'STANDARD',
            targetTier: 'GLACIER',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: $source->path,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);

        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertNotNull($job);
        self::assertNotNull($object);
        self::assertSame('completed', $job['status']);
        self::assertSame('GLACIER', $object->storageClass);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame($object->systemMetadata['storagePath'], $job['targetStoragePath']);

        $targetData = $archive->getObjectByPath($object->systemMetadata['storagePath'])->read();
        self::assertSame('archive-data', $targetData);
        self::assertSame([
            'objectCount' => 1,
            'bytesUsed' => $source->size,
        ], $this->metadata->getBucketStorageStats('bucket'));

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $hot->getObjectByPath($source->path)->read();
    }

    public function test_retries_failed_transition_without_switching_metadata(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'corrupt.bin', new ReadableBuffer('good-data'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'corrupt.bin',
            ownerId: 'owner',
            size: $source->size + 1,
            etag: '"00000000000000000000000000000000"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'corrupt.bin',
            versionId: null,
            sourceTier: 'STANDARD',
            targetTier: 'GLACIER',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: $source->path,
            maxAttempts: 2,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0], $stats);

        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'corrupt.bin');

        self::assertNotNull($job);
        self::assertNotNull($object);
        self::assertSame('pending', $job['status']);
        self::assertSame(1, $job['attempts']);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame($source->path, $object->systemMetadata['storagePath']);
        self::assertSame('pending', $object->transitionStatus);
        self::assertSame('GLACIER', $object->transitionTargetTier);
        self::assertNotNull($object->transitionError);
    }

    public function test_stale_unversioned_transition_job_after_overwrite_is_completed_without_switching_new_object(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $old = $hot->putObject('bucket', 'same-size.bin', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'same-size.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'same-size.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'same-size.bin', null, 'STANDARD', 'STANDARD', $old->path, 'pending', 'GLACIER');

        $new = $hot->putObject('bucket', 'same-size.bin', new ReadableBuffer('new'));
        $this->metadata->putObjectMetadata('bucket', 'same-size.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'same-size.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame($new->path, $object->systemMetadata['storagePath']);
        self::assertSame('new', $hot->getObjectByPath($object->systemMetadata['storagePath'])->read());
    }

    public function test_versioned_transition_job_moves_only_target_version_when_latest_changes(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $old = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('old-version'));
        $oldVersionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $new = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('new-version'));
        $newVersionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', $oldVersionId, 'STANDARD', 'GLACIER', 'GLACIER', $old->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);
        $oldObject = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $oldVersionId);
        $newObject = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $newVersionId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($oldObject);
        self::assertSame('GLACIER', $oldObject->storageTier);
        self::assertSame('old-version', $archive->getObjectByPath($oldObject->systemMetadata['storagePath'])->read());
        self::assertNotNull($newObject);
        self::assertSame('STANDARD', $newObject->storageTier);
        self::assertSame($new->path, $newObject->systemMetadata['storagePath']);
    }

    public function test_deleted_object_transition_job_is_completed_as_stale_noop(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'deleted.bin', new ReadableBuffer('deleted-data'));
        $this->metadata->putObjectMetadata('bucket', 'deleted.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'deleted.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->deleteObjectMetadata('bucket', 'deleted.bin');

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'deleted.bin'));
    }

    public function test_object_lock_legal_hold_does_not_block_internal_transition(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'locked.bin', new ReadableBuffer('locked-data'));
        $this->metadata->putObjectMetadata('bucket', 'locked.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->putObjectLegalHold('bucket', 'locked.bin', 'ON');
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'locked.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'locked.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame('ON', $this->metadata->getObjectLegalHold('bucket', 'locked.bin'));
        self::assertSame('locked-data', $archive->getObjectByPath($object->systemMetadata['storagePath'])->read());
    }
}
