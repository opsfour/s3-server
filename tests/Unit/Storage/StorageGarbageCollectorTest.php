<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Process;

final class StorageGarbageCollectorTest extends TestCase
{
    public function test_direct_discard_cannot_remove_another_connection_claimed_delete_job(): void
    {
        $path = sys_get_temp_dir() . '/s3-garbage-discard-claim-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $collector = new SqliteMetadataStore($path);
            $collector->initialize();
            $directDelete = new SqliteMetadataStore($path);
            $directDelete->initialize();
            $collector->enqueueStorageGarbage('bucket', 'STANDARD', 'bucket/claimed');
            $claimed = $collector->dequeueStorageGarbage(1);
            self::assertCount(1, $claimed);
            $generation = $claimed[0]['lease_generation'] ?? null;
            self::assertIsInt($generation);

            $directDelete->discardStorageGarbage('bucket', 'STANDARD', 'bucket/claimed');
            $row = (new \PDO('sqlite:' . $path))->query(
                "SELECT status, lease_generation FROM s3_storage_garbage WHERE storage_path = 'bucket/claimed'",
            );
            self::assertInstanceOf(\PDOStatement::class, $row);
            self::assertSame(
                ['status' => 'processing', 'lease_generation' => $generation],
                $row->fetch(\PDO::FETCH_ASSOC),
            );
            self::assertTrue($collector->completeStorageGarbageLease(
                $claimed[0]['id'],
                $claimed[0]['lease_expires_at'],
                $generation,
            ));
            $directDelete->enqueueStorageGarbage('bucket', 'STANDARD', 'bucket/pending');
            $directDelete->discardStorageGarbage('bucket', 'STANDARD', 'bucket/pending');
            self::assertSame([], $collector->dequeueStorageGarbage(1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_lease_is_renewed_during_yielding_delete_and_stale_collector_cannot_acknowledge(): void
    {
        $path = sys_get_temp_dir() . '/s3-garbage-claim-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = new SqliteMetadataStore($path);
            $first->initialize();
            $second = new SqliteMetadataStore($path);
            $second->initialize();
            $first->enqueueStorageGarbage('bucket', 'STANDARD', 'old/path');
            $initial = null;
            $generation = null;
            $id = null;
            $backend = $this->createMock(StorageBackend::class);
            $backend->method('deleteObjectByPath')->willReturnCallback(static function () use ($path, &$initial, &$generation, &$id): void {
                $pdo = new \PDO('sqlite:' . $path);
                $statement = $pdo->query('SELECT id, next_attempt_at, lease_generation FROM s3_storage_garbage');
                self::assertInstanceOf(\PDOStatement::class, $statement);
                $row = $statement->fetch(\PDO::FETCH_ASSOC);
                self::assertIsArray($row);
                $id = (int) $row['id'];
                $initial = (float) $row['next_attempt_at'];
                $generation = (int) $row['lease_generation'];
                \Amp\delay(0.22);
            });
            $tiers = StorageTierRegistry::single($backend);
            $future = \Amp\async(static fn(): int => (new StorageGarbageCollector($first, $tiers, renewIntervalSeconds: 0.04))->collect(1));
            \Amp\delay(0.14);
            self::assertNotNull($id);
            self::assertNotNull($initial);
            self::assertNotNull($generation);
            $pdo = new \PDO('sqlite:' . $path);
            $statement = $pdo->query('SELECT next_attempt_at, lease_generation FROM s3_storage_garbage');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertGreaterThan($initial, (float) $row['next_attempt_at']);
            self::assertSame($generation, (int) $row['lease_generation']);
            self::assertSame(0, (new StorageGarbageCollector($second, $tiers, renewIntervalSeconds: 0.04))->collect(1));
            self::assertFalse($second->completeStorageGarbageLease($id, $initial, $generation));
            self::assertSame(1, $future->await());
            self::assertSame([], $second->dequeueStorageGarbage(1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_cancelled_backend_delete_keeps_lease_without_charging_a_failure(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', '/part/path');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')->willThrowException(new \Amp\CancelledException());

        try {
            (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(1);
            self::fail('Backend cancellation must stop the collector.');
        } catch (\Amp\CancelledException) {
            $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
            self::assertInstanceOf(\PDO::class, $connection);
            $row = $connection->query('SELECT status, attempts, last_error FROM s3_storage_garbage');
            self::assertInstanceOf(\PDOStatement::class, $row);
            self::assertSame(
                ['status' => 'processing', 'attempts' => 0, 'last_error' => null],
                $row->fetch(\PDO::FETCH_ASSOC),
            );
        }
    }

    public function test_failed_cleanup_redacts_backend_error_and_storage_path_in_queue_and_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $path = 's3://storage-private-test-token/old-object';
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $path);
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('deleteObjectByPath')
            ->willThrowException(new \RuntimeException('backend-password-private-test-token'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };

        $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage), $logger);
        self::assertSame(0, $collector->collect(1));

        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $row = $connection->query('SELECT id, status, attempts, last_error FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $row);
        $persisted = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($persisted);
        self::assertSame('pending', $persisted['status']);
        self::assertSame(1, (int) $persisted['attempts']);
        self::assertSame('Physical storage cleanup failed.', $persisted['last_error']);

        self::assertCount(1, $logger->records);
        self::assertSame((int) $persisted['id'], $logger->records[0]['context']['garbage_id']);
        self::assertSame(hash('sha256', $path), $logger->records[0]['context']['storage_path_hash']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertStringNotContainsString('private-test-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_queued_multipart_abort_deletes_flysystem_part_directory(): void
    {
        $path = sys_get_temp_dir() . '/s3-storage-gc-flysystem-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUpload('flysystem-upload', 'bucket', 'key', 'owner');
            $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
            $storage = new FlysystemBackend($filesystem);
            $part = $storage->putPart('bucket', 'key', 'flysystem-upload', 1, new ReadableBuffer('part data'));
            self::assertTrue($filesystem->fileExists($part->path));
            $metadata->putPart('flysystem-upload', 1, $part->md5Hex, $part->size, $part->path);
            $metadata->transaction(function () use ($metadata): void {
                $metadata->stageBucketMultipartCleanup('bucket', 'STANDARD');
                $metadata->deleteBucket('owner', 'bucket');
            });

            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(1));
            self::assertFalse($filesystem->fileExists($part->path));
            self::assertSame([], $metadata->dequeueStorageGarbage(1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_abandoned_multipart_abort_is_reclaimed_after_worker_exit(): void
    {
        $path = sys_get_temp_dir() . '/s3-storage-gc-crash-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUpload('crashed-upload', 'bucket', 'key', 'owner');
            $storage = new InMemoryBackend();
            $storage->createBucket('bucket');
            $part = $storage->putPart('bucket', 'key', 'crashed-upload', 1, new ReadableBuffer('part data'));
            $metadata->putPart('crashed-upload', 1, $part->md5Hex, $part->size, $part->path);
            $metadata->deleteBucket('owner', 'bucket');

            // Existing deployments can still contain old directory-abort jobs.
            // Exercise their crash recovery without producing a new legacy job.
            $connection = new \PDO('sqlite:' . $path);
            $connection->exec("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, next_attempt_at)
                VALUES ('bucket', 'STANDARD', '', 'abort_multipart', 'key', 'crashed-upload', 0)");

            $worker = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Support/metadata-garbage-claim-worker.php', $path]);
            $worker->run();
            self::assertSame(86, $worker->getExitCode(), $worker->getErrorOutput());
            self::assertSame('1', $worker->getOutput());

            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(0, $collector->collect(1));
            $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
            self::assertInstanceOf(\PDO::class, $connection);
            $connection->exec('UPDATE s3_storage_garbage SET next_attempt_at = 0');
            self::assertSame(1, $collector->collect(1));
            self::assertSame([], $metadata->dequeueStorageGarbage(1));

            $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
            $storage->assembleMultipartUpload('bucket', 'key', 'crashed-upload', [
                ['partNumber' => 1, 'etag' => $part->md5Hex, 'storagePath' => $part->path],
            ]);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_failed_queued_multipart_abort_is_retried_after_its_backoff(): void
    {
        $path = sys_get_temp_dir() . '/s3-storage-gc-multipart-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUpload('upload-id', 'bucket', 'key', 'owner');
            $metadata->transaction(function () use ($metadata): void {
                $metadata->stageBucketMultipartCleanup('bucket', 'STANDARD');
                $metadata->deleteBucket('owner', 'bucket');
            });

            // A legacy queue row survives upgrades; new staging creates none
            // for an upload without committed parts.
            $connection = new \PDO('sqlite:' . $path);
            $connection->exec("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, key_name, upload_id, next_attempt_at)
                VALUES ('bucket', 'STANDARD', '', 'abort_multipart', 'key', 'upload-id', 0)");

            $attempts = 0;
            $storage = $this->createMock(StorageBackend::class);
            $storage->method('abortMultipartUpload')->willReturnCallback(
                static function (string $bucket, string $key, string $uploadId) use (&$attempts): void {
                    self::assertSame(['bucket', 'key', 'upload-id'], [$bucket, $key, $uploadId]);
                    if (++$attempts === 1) {
                        throw new \RuntimeException('Temporary storage failure.');
                    }
                },
            );
            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(0, $collector->collect(1));

            $row = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
            self::assertInstanceOf(\PDOStatement::class, $row);
            self::assertSame(['status' => 'pending', 'attempts' => 1], $row->fetch(\PDO::FETCH_ASSOC));

            $connection->exec('UPDATE s3_storage_garbage SET next_attempt_at = 0');
            self::assertSame(1, $collector->collect(1));
            self::assertSame(2, $attempts);
            self::assertSame([], $metadata->dequeueStorageGarbage(1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

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
