<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Future;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Parallel\SelectProcessingTask;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class SelectRestoreGenerationRaceTest extends TestCase
{
    public function test_select_does_not_return_old_object_data_after_bucket_reuse_during_storage_read(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $data = "name\nAlice\n";
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            strlen($data),
            '"alice-etag"',
            systemMetadata: ['storagePath' => 'old-path'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback(static function () use (&$currentBucket, $newBucket, $data): ReadableBuffer {
            $currentBucket = $newBucket;

            return new ReadableBuffer($data);
        });
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?select&select-type=2'),
            [],
            '<SelectObjectContentRequest><Expression>SELECT * FROM s3object</Expression>'
            . '<ExpressionType>SQL</ExpressionType><InputSerialization><CSV/></InputSerialization>'
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $oldBucket->generation);

        $this->expectException(OperationAbortedException::class);
        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_restore_rechecks_reused_bucket_before_missing_object_error(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturnCallback(static function () use (&$currentBucket, $newBucket): null {
            $currentBucket = $newBucket;
            return null;
        });

        $this->expectException(OperationAbortedException::class);
        (new RestoreObjectHandler($metadata, StorageTierRegistry::single(new InMemoryBackend())))
            ->handleRequest($this->restoreRequest($oldBucket));
    }

    public function test_select_rechecks_reused_bucket_before_missing_object_error(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturnCallback(static function () use (&$currentBucket, $newBucket): null {
            $currentBucket = $newBucket;
            return null;
        });
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(OperationAbortedException::class);
        (new SelectObjectContentHandler($metadata, $storage))
            ->handleRequest($this->selectRequest($oldBucket));
    }

    public function test_select_does_not_reveal_missing_key_without_list_bucket_access(): void
    {
        $bucket = new BucketInfo('bucket', 'alice');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getObjectMetadata')->willReturn(null);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $request = $this->selectRequest($bucket);
        $request->setAttribute('ownerId', 'bob');

        $this->expectException(AccessDeniedException::class);
        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_select_treats_missing_committed_storage_path_as_internal_corruption(): void
    {
        $bucket = new BucketInfo('bucket', 'alice');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, '"etag"'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Object storage path missing.');
        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($this->selectRequest($bucket));
    }

    public function test_select_treats_unknown_committed_storage_tier_as_internal_corruption(): void
    {
        $bucket = new BucketInfo('bucket', 'alice');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            storageTier: 'MISSING',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Object storage tier is not configured.');
        (new SelectObjectContentHandler($metadata, $storage))->handleRequest($this->selectRequest($bucket));
    }

    public function test_select_rechecks_reused_bucket_before_returning_completed_worker_result(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $data = "name\nAlice\n";
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            strlen($data),
            '"old-etag"',
            systemMetadata: ['storagePath' => 'old-path'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer($data));

        $worker = $this->createMock(Worker::class);
        $worker->method('submit')->willReturnCallback(function () use (&$currentBucket, $newBucket): Execution {
            $currentBucket = $newBucket;

            return new Execution(
                new SelectProcessingTask('', 'SELECT * FROM s3object', [], []),
                $this->createMock(Channel::class),
                Future::complete(['eventStream' => 'old-owner-output']),
            );
        });
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('getWorker')->willReturn($worker);

        $this->expectException(OperationAbortedException::class);
        (new SelectObjectContentHandler($metadata, $storage, workerPool: $pool))
            ->handleRequest($this->selectRequest($oldBucket));
    }

    public function test_select_rechecks_reused_bucket_before_missing_source_error(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"old-etag"',
            systemMetadata: ['storagePath' => 'old-path'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback(static function () use (&$currentBucket, $newBucket): never {
            $currentBucket = $newBucket;
            throw new NoSuchKeyException();
        });

        $this->expectException(OperationAbortedException::class);
        (new SelectObjectContentHandler($metadata, $storage))
            ->handleRequest($this->selectRequest($oldBucket));
    }

    public function test_restore_rechecks_reused_bucket_before_wrong_tier_error(): void
    {
        [$oldBucket, $newBucket] = self::buckets();
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturnCallback(static function () use (&$currentBucket, $newBucket): ObjectInfo {
            $currentBucket = $newBucket;
            return new ObjectInfo('bucket', 'key', 3, '"old-etag"', storageTier: 'STANDARD');
        });

        $this->expectException(OperationAbortedException::class);
        (new RestoreObjectHandler($metadata, StorageTierRegistry::single(new InMemoryBackend())))
            ->handleRequest($this->restoreRequest($oldBucket));
    }

    private function selectRequest(BucketInfo $bucket): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?select&select-type=2'),
            [],
            '<SelectObjectContentRequest><Expression>SELECT * FROM s3object</Expression>'
            . '<ExpressionType>SQL</ExpressionType><InputSerialization><CSV/></InputSerialization>'
            . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);

        return $request;
    }

    private function restoreRequest(BucketInfo $bucket): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?restore'),
            [],
            '<RestoreRequest><Days>1</Days></RestoreRequest>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);

        return $request;
    }

    /** @return array{BucketInfo, BucketInfo} */
    private static function buckets(): array
    {
        return [
            new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32)),
            new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)),
        ];
    }
}
