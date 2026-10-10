<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Metadata\GenerationBoundBucketMultipartCleanupStore;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class DeleteBucketNamespaceRaceTest extends TestCase
{
    public function test_delete_bucket_rejects_old_owner_upload_from_lagging_store(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            GenerationBoundMultipartListingStore::class,
            GenerationBoundBucketMultipartCleanupStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->method('listMultipartUploadsForBucket')->willReturn([
            'uploads' => [[
                'upload_id' => 'old-upload', 'bucket' => 'bucket', 'key_name' => 'key',
                'owner_id' => 'alice', 'content_type' => 'application/octet-stream',
                'created_at' => '2026-01-01 00:00:00',
            ]],
            'isTruncated' => false,
            'nextKeyMarker' => null,
            'nextUploadIdMarker' => null,
            'commonPrefixes' => [],
        ]);
        $metadata->method('getMultipartUpload')->willReturn([
            'upload_id' => 'old-upload', 'bucket' => 'bucket', 'bucket_generation' => str_repeat('a', 32),
            'key_name' => 'key', 'owner_id' => 'alice', 'storage_tier' => 'STANDARD',
            'user_metadata' => [], 'created_at' => '2026-01-01 00:00:00',
        ]);
        $metadata->expects(self::never())->method('deleteMultipartUpload');
        $metadata->expects(self::never())->method('stageBucketMultipartCleanupForBucket');
        $metadata->expects(self::never())->method('deleteBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('deleteObjectByPath');

        $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'bob');

        $this->expectException(OperationAbortedException::class);
        (new DeleteBucketHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_bulk_cleanup_rejects_hidden_old_generation_before_staging_any_paths(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-stale-bulk-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->transaction(function () use ($metadata): void {
                for ($index = 0; $index < 257; $index++) {
                    $uploadId = sprintf('current-%04d', $index);
                    $metadata->createMultipartUpload($uploadId, 'bucket', $uploadId, 'owner');
                    $metadata->putPart($uploadId, 1, 'etag', 1, 'parts/' . $uploadId);
                }
                $metadata->createMultipartUpload('stale', 'bucket', 'stale-key', 'owner');
                $metadata->putPart('stale', 1, 'etag', 1, 'parts/stale');
            });
            $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
            self::assertInstanceOf(\PDO::class, $connection);
            $connection->exec("UPDATE s3_multipart_uploads SET bucket_generation = 'old-generation' WHERE upload_id = 'stale'");

            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');
            try {
                (new DeleteBucketHandler($metadata, new InMemoryBackend()))->handleRequest($request);
                self::fail('Bulk cleanup must reject hidden uploads from an old generation.');
            } catch (OperationAbortedException) {
                self::assertNotNull($metadata->getBucket('bucket'));
                self::assertNotNull($metadata->getMultipartUpload('stale'));
                $queued = $connection->query('SELECT COUNT(*) FROM s3_storage_garbage');
                self::assertInstanceOf(\PDOStatement::class, $queued);
                self::assertSame(0, (int) $queued->fetchColumn());
            }
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    public function test_inline_cleanup_rejects_hidden_old_generation_before_bucket_delete(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUpload('stale', 'bucket', 'stale-key', 'owner');
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("UPDATE s3_multipart_uploads SET bucket_generation = 'old-generation' WHERE upload_id = 'stale'");

        $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');
        try {
            (new DeleteBucketHandler($metadata, new InMemoryBackend()))->handleRequest($request);
            self::fail('Inline cleanup must reject hidden uploads from an old generation.');
        } catch (OperationAbortedException) {
            self::assertNotNull($metadata->getBucket('bucket'));
            self::assertNotNull($metadata->getMultipartUpload('stale'));
        }
    }

    public function test_custom_store_without_generation_bound_bulk_cleanup_cannot_delete_bucket(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            GenerationBoundMultipartListingStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner', generation: str_repeat('a', 32)));
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->method('listMultipartUploadsForBucket')->willReturn([
            'uploads' => [],
            'isTruncated' => false,
            'nextKeyMarker' => null,
            'nextUploadIdMarker' => null,
            'commonPrefixes' => [],
        ]);
        $metadata->expects(self::never())->method('deleteBucket');

        $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');
        $this->expectException(NotImplementedException::class);
        (new DeleteBucketHandler($metadata, new InMemoryBackend()))->handleRequest($request);
    }

    public function test_legacy_upload_of_current_owner_remains_deletable(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUpload('legacy', 'bucket', 'key', 'owner');
        $metadata->putPart('legacy', 1, 'etag', 1, 'parts/legacy');
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("UPDATE s3_multipart_uploads SET bucket_generation = '' WHERE upload_id = 'legacy'");

        $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('deferred cleanup'));
        self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());
        self::assertNull($metadata->getBucket('bucket'));
        $queued = $connection->query('SELECT storage_path FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $queued);
        self::assertSame('parts/legacy', $queued->fetchColumn());
    }

    public function test_large_multipart_backlog_uses_durable_cleanup_queue(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-backlog-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            for ($index = 0; $index < 257; $index++) {
                $uploadId = sprintf('upload-%04d', $index);
                $metadata->createMultipartUpload($uploadId, 'bucket', sprintf('key-%04d', $index), 'owner');
                $metadata->putPart($uploadId, 1, 'etag', 1, sprintf('parts/%s/1', $uploadId));
            }

            $storage = $this->createMock(StorageBackend::class);
            $deletedPaths = self::pathLog();
            $storage->expects(self::never())->method('abortMultipartUpload');
            $storage->method('deleteObjectByPath')->willReturnCallback(
                static function (string $path, string $bucket) use (&$deletedPaths): void {
                    $deletedPaths[$path] = $bucket;
                },
            );
            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            $foreignRequest = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $foreignRequest->setAttribute('s3.bucket', 'bucket');
            $foreignRequest->setAttribute('ownerId', 'other-owner');
            try {
                (new DeleteBucketHandler($metadata, $storage))->handleRequest($foreignRequest);
                self::fail('A foreign caller must not learn that a bucket exceeds the cleanup cap.');
            } catch (AccessDeniedException) {
                self::assertCount(257, $metadata->listMultipartUploads('bucket')['uploads']);
            }

            self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());
            self::assertNull($metadata->getBucket('bucket'));
            self::assertSame([], $deletedPaths->getArrayCopy());
            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(257, $collector->collect(300));
            self::assertCount(257, $deletedPaths);
            self::assertSame('bucket', $deletedPaths->offsetGet('parts/upload-0000/1'));
            self::assertSame('bucket', $deletedPaths->offsetGet('parts/upload-0256/1'));
            self::assertSame(0, $collector->collect(1));
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    public function test_many_multipart_parts_use_durable_cleanup_queue(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-parts-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUpload('upload-a', 'bucket', 'a', 'owner');
            $metadata->createMultipartUpload('upload-b', 'bucket', 'b', 'owner');
            $metadata->transaction(function () use ($metadata): void {
                for ($part = 1; $part <= 10_000; $part++) {
                    $metadata->putPart('upload-a', $part, 'etag', 1, 'parts/a/' . $part);
                }
                $metadata->putPart('upload-b', 1, 'etag', 1, 'parts/b/1');
            });

            $storage = $this->createMock(StorageBackend::class);
            $deletedPaths = self::pathLog();
            $storage->expects(self::never())->method('abortMultipartUpload');
            $storage->method('deleteObjectByPath')->willReturnCallback(
                static function (string $path, string $bucket) use (&$deletedPaths): void {
                    $deletedPaths[$path] = $bucket;
                },
            );
            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');

            self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());
            self::assertNull($metadata->getBucket('bucket'));
            self::assertSame([], $deletedPaths->getArrayCopy());
            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(10, $collector->collect(10));
            self::assertCount(10, $deletedPaths);
            self::assertSame('bucket', $deletedPaths->offsetGet('parts/a/1'));
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    public function test_failed_bucket_delete_rolls_back_queued_upload_aborts(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-rollback-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            for ($index = 0; $index < 257; $index++) {
                $metadata->createMultipartUpload(sprintf('upload-%04d', $index), 'bucket', sprintf('key-%04d', $index), 'owner');
            }
            $metadata->putPart('upload-0000', 1, 'etag', 1, 'parts/rollback/1');
            $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
            self::assertInstanceOf(\PDO::class, $connection);
            $connection->exec("CREATE TRIGGER reject_bucket_delete BEFORE DELETE ON s3_buckets BEGIN SELECT RAISE(ABORT, 'blocked'); END");

            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'owner');
            try {
                (new DeleteBucketHandler($metadata, new InMemoryBackend()))->handleRequest($request);
                self::fail('A failed metadata deletion must roll back queued aborts.');
            } catch (\PDOException $error) {
                self::assertStringContainsString('blocked', $error->getMessage());
            }

            self::assertSame('owner', $metadata->getBucketOwner('bucket'));
            self::assertCount(257, $metadata->listMultipartUploads('bucket')['uploads']);
            $statement = $connection->query('SELECT COUNT(*) FROM s3_storage_garbage');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            self::assertSame(0, (int) $statement->fetchColumn());
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    public function test_queued_old_upload_abort_keeps_new_bucket_generation_parts(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-queued-reuse-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('old-owner', 'bucket', 'us-east-1');
            $storage = new InMemoryBackend();
            $storage->createBucket('bucket');
            for ($index = 0; $index < 257; $index++) {
                $metadata->createMultipartUpload(sprintf('old-%04d', $index), 'bucket', 'old-key', 'old-owner');
            }
            $oldPart = $storage->putPart('bucket', 'old-key', 'old-0000', 1, new ReadableBuffer('old data'));
            $metadata->putPart('old-0000', 1, $oldPart->md5Hex, $oldPart->size, $oldPart->path);

            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'old-owner');
            self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());

            $metadata->createBucket('new-owner', 'bucket', 'us-east-1');
            $storage->createBucket('bucket');
            $metadata->createMultipartUpload('new-upload', 'bucket', 'new-key', 'new-owner');
            $newPart = $storage->putPart('bucket', 'new-key', 'new-upload', 1, new ReadableBuffer('new data'));
            $metadata->putPart('new-upload', 1, $newPart->md5Hex, $newPart->size, $newPart->path);

            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(300));
            self::assertSame('new-owner', $metadata->getBucketOwner('bucket'));
            self::assertNotNull($metadata->getMultipartUpload('new-upload'));
            $assembled = $storage->assembleMultipartUpload('bucket', 'new-key', 'new-upload', [
                ['partNumber' => 1, 'etag' => $newPart->md5Hex, 'storagePath' => $newPart->path],
            ]);
            self::assertSame('new data', \Amp\ByteStream\buffer($storage->getObjectByPath($assembled->path)));
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    public function test_name_reuse_during_old_multipart_cleanup_keeps_new_owners_data(): void
    {
        $databasePath = sys_get_temp_dir() . '/s3-delete-race-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('old-owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUpload('abcdef123456', 'bucket', 'unfinished.bin', 'old-owner');

            $physical = new InMemoryBackend();
            $physical->createBucket('bucket');
            $oldPart = $physical->putPart('bucket', 'unfinished.bin', 'abcdef123456', 1, new ReadableBuffer('old data'));
            $metadata->putPart('abcdef123456', 1, $oldPart->md5Hex, $oldPart->size, $oldPart->path);
            $newPath = null;
            $storage = $this->createMock(StorageBackend::class);
            $storage->expects(self::never())->method('abortMultipartUpload');
            $storage->method('deleteObjectByPath')->willReturnCallback(
                function (string $path, string $bucket) use ($metadata, $physical, $oldPart, &$newPath): void {
                    self::assertSame([$oldPart->path, 'bucket'], [$path, $bucket]);
                    $physical->deleteObjectByPath($path, $bucket);
                    $metadata->createBucket('new-owner', $bucket, 'us-east-1');
                    $physical->createBucket($bucket);
                    $result = $physical->putObject($bucket, 'new.txt', new ReadableBuffer('new owners data'));
                    $newPath = $result->path;
                    $metadata->putObjectMetadata(
                        $bucket,
                        'new.txt',
                        'new-owner',
                        $result->size,
                        $result->md5Hex,
                        'text/plain',
                        $result->path,
                    );
                },
            );
            $storage->method('deleteBucket')->willReturnCallback($physical->deleteBucket(...));

            $request = new Request(new DeleteBucketRaceTestClient(), 'DELETE', Http::new('http://127.0.0.1/bucket'));
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('ownerId', 'old-owner');

            self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());
            self::assertSame('new-owner', $metadata->getBucketOwner('bucket'));
            self::assertNotNull($metadata->getObjectMetadata('bucket', 'new.txt'));
            self::assertNotNull($newPath);
            self::assertSame('new owners data', \Amp\ByteStream\buffer($physical->getObjectByPath($newPath)));
        } finally {
            @unlink($databasePath);
            @unlink($databasePath . '-wal');
            @unlink($databasePath . '-shm');
        }
    }

    /** @return \ArrayObject<string, string> */
    private static function pathLog(): \ArrayObject
    {
        return new \ArrayObject();
    }
}

final class DeleteBucketRaceTestClient implements Client
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

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}
