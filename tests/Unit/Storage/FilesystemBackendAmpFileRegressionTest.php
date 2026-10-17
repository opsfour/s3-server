<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\File\FilesystemDriver;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTempFileLease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function Amp\ByteStream\buffer;
use function Amp\async;
use function Amp\Future\await as awaitFutures;

final class FilesystemBackendAmpFileRegressionTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/s3server-amp-file-regression-' . bin2hex(random_bytes(8));
        mkdir($this->basePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);
    }

    public function test_malformed_upload_id_cannot_escape_part_directory_during_abort(): void
    {
        mkdir($this->basePath . '/.parts');
        file_put_contents($this->basePath . '/unrelated.txt', 'keep');
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->basePath, $filesystem, $filesystem);

        foreach (['..', '../unrelated.txt', 'safe/../..', 'safe\\..', ''] as $uploadId) {
            try {
                $backend->abortMultipartUpload('bucket', 'key', $uploadId);
                self::fail('Malformed upload ID must fail before directory deletion.');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame('keep', file_get_contents($this->basePath . '/unrelated.txt'));
        }
    }

    public function test_planned_object_write_is_exact_and_rejects_other_key_or_traversal(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');
        $generation = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $path = $backend->planObjectWritePath('bucket', 'key', $generation, $attempt);
        self::assertStringEndsWith('/' . $generation . '-' . $attempt, $path);

        foreach ([['bucket', 'other-key', $path], ['bucket', 'key', $this->basePath . '/bucket/../escape']] as [$bucket, $key, $invalid]) {
            try {
                $backend->putObjectAtPath($bucket, $key, new ReadableBuffer('wrong'), $invalid);
                self::fail('Invalid planned object path must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $write = $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('planned'), $path);
        self::assertSame($path, $write->path);
        self::assertSame('planned', buffer($backend->getObjectByPath($path)));
        self::assertFileDoesNotExist($this->basePath . '/escape');
    }

    public function test_normal_planned_publication_never_replaces_an_existing_target(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');
        $path = $backend->planObjectWritePath(
            'bucket',
            'key',
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
        );
        $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('first'), $path);

        try {
            $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('second'), $path);
            self::fail('A normal create-only publication replaced an existing target.');
        } catch (InternalErrorException) {
            self::assertSame('first', file_get_contents($path));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_cancelled_object_write_cleans_staging_file_and_propagates_same_cancellation(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');
        $cancellation = new CancelledException();
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::once())->method('read')->willThrowException($cancellation);

        try {
            $backend->putObject('bucket', 'key', $body);
            self::fail('Cancellation must leave the filesystem write boundary unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], glob($this->basePath . '/.tmp/*'));
        }
    }

    public function test_close_failure_does_not_replace_object_write_cancellation(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $file = $this->createMock(File::class);
        $file->method('close')->willThrowException(new \RuntimeException('close failed'));
        $driver->method('openFile')->willReturn($file);
        $backend = new FilesystemBackend($this->basePath, new Filesystem($driver));
        $backend->createBucket('bucket');
        $cancellation = new CancelledException();
        $body = $this->createMock(ReadableStream::class);
        $body->method('read')->willThrowException($cancellation);

        try {
            $backend->putObject('bucket', 'key', $body);
            self::fail('Close failure must not replace request cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], glob($this->basePath . '/.tmp/*'));
        }
    }

    public function test_close_failure_does_not_replace_ranged_read_cancellation(): void
    {
        $normal = new FilesystemBackend($this->basePath);
        $normal->createBucket('bucket');
        $stored = $normal->putObject('bucket', 'key', new ReadableBuffer('payload'));
        $cancellation = new CancelledException();
        $driver = $this->createMock(FilesystemDriver::class);
        $file = $this->createMock(File::class);
        $file->method('seek')->willThrowException($cancellation);
        $file->method('close')->willThrowException(new \RuntimeException('close failed'));
        $driver->method('openFile')->willReturn($file);
        $backend = new FilesystemBackend($this->basePath, new Filesystem($driver));

        try {
            $backend->getObjectByPath($stored->path, 1);
            self::fail('Close failure must not replace ranged-read cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_name_only_filesystem_bucket_deletion_does_not_remove_recreated_marker(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');

        try {
            $backend->deleteBucket('bucket');
            self::fail('Name-only bucket deletion must be rejected.');
        } catch (\LogicException) {
            self::assertDirectoryExists($this->basePath . '/bucket');
        }
    }

    public function test_parallel_puts_do_not_deadlock_amp_file_worker_pool_during_finalize(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');

        $futures = [];
        for ($i = 0; $i < 100; $i++) {
            $futures[] = async(static function () use ($backend, $i) {
                return $backend->putObject(
                    'bucket',
                    "concurrent/{$i}.bin",
                    new ReadableBuffer(str_repeat(chr(65 + ($i % 26)), 1024)),
                );
            });
        }

        $results = awaitFutures($futures, new TimeoutCancellation(10));

        self::assertCount(100, $results);
        foreach ($results as $result) {
            self::assertSame(1024, $result->size);
            self::assertFileExists($result->path);
        }
    }

    public function test_bounded_copy_stages_only_metadata_size_plus_one_byte(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');
        $source = $backend->putObject('bucket', 'source', new ReadableBuffer(str_repeat('x', 1_000_000)));

        $copy = $backend->copyObjectBounded($source->path, 'bucket', 'copy', 4);

        self::assertSame(4, $copy->size);
        self::assertSame('xxxx', buffer($backend->getObjectByPath($copy->path)));
        self::assertSame([], glob($this->basePath . '/.tmp/*'));
    }

    public function test_short_amp_file_writes_reject_all_filesystem_publication_paths(): void
    {
        $normal = new FilesystemBackend($this->basePath);
        $normal->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));
        $source = $normal->putObject('bucket', 'source', new ReadableBuffer('abcdef'));
        $part = $normal->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer('abcdef'));

        $realDriver = new BlockingFilesystemDriver();
        $shortDriver = $this->createMock(FilesystemDriver::class);
        $shortDriver->method('openFile')->willReturnCallback(function (string $path, string $mode) use ($realDriver): File {
            if ($mode !== 'w') {
                return $realDriver->openFile($path, $mode);
            }
            $file = $this->createMock(File::class);
            $file->method('write')->willReturnCallback(static function (string $bytes) use ($path): void {
                file_put_contents($path, substr($bytes, 0, 2), FILE_APPEND);
            });

            return $file;
        });
        $backend = new FilesystemBackend($this->basePath, new Filesystem($shortDriver));

        try {
            foreach (['put', 'part', 'assembly', 'copy'] as $operation) {
                try {
                    match ($operation) {
                        'put' => $backend->putObject('bucket', 'failed', new ReadableBuffer('abcdef')),
                        'part' => $backend->putPart('bucket', 'failed', $uploadId, 2, new ReadableBuffer('abcdef')),
                        'assembly' => $backend->assembleMultipartUpload('bucket', 'failed', $uploadId, [[
                            'partNumber' => 1, 'etag' => $part->md5Hex, 'storagePath' => $part->path,
                        ]]),
                        'copy' => $backend->copyObject($source->path, 'bucket', 'failed'),
                    };
                    self::fail("A short {$operation} staging write must fail before final move.");
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('staging file is incomplete', $error->getPrevious()?->getMessage() ?? $error->getMessage());
                    self::assertSame([], glob($this->basePath . '/.tmp/*') ?: []);
                    self::assertSame([$source->path], glob($this->basePath . '/bucket/*/*/*') ?: []);
                    self::assertSame([$part->path], glob($this->basePath . '/.parts/' . $uploadId . '/*') ?: []);
                }
            }
        } finally {
            $backend->shutdown();
            $normal->shutdown();
        }
    }

    public function test_deleting_a_part_keeps_the_shared_multipart_root(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('abc');
        $uploadId = bin2hex(random_bytes(8));
        $part = $backend->putPart('abc', 'key', $uploadId, 1, new ReadableBuffer('part bytes'));

        $backend->deleteObjectByPath($part->path, 'abc');

        self::assertDirectoryExists($this->basePath . '/.parts');
        self::assertDirectoryExists($this->basePath . '/.parts/' . $uploadId);
        self::assertFileDoesNotExist($part->path);
        self::assertDirectoryExists($this->basePath . '/abc');
    }

    public function test_stale_temp_cleanup_keeps_an_active_transfer_until_its_lock_is_released(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $tempDir = $this->basePath . '/.tmp';
        self::assertTrue(mkdir($tempDir));
        $path = $tempDir . '/' . bin2hex(random_bytes(16));
        $lease = StorageTempFileLease::acquire($path);
        self::assertTrue(touch($path, time() - 7200));

        self::assertSame(0, $backend->cleanupStaleTempFiles());
        self::assertFileExists($path);

        $lease->release();
        self::assertSame(1, $backend->cleanupStaleTempFiles());
        self::assertFileDoesNotExist($path);
    }

    public function test_multipart_assembly_rejects_corrupt_part_without_publishing_object(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $backend->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));
        $part = $backend->putPart('bucket', 'object.bin', $uploadId, 1, new ReadableBuffer('original'));
        file_put_contents($part->path, 'tampered');

        try {
            $backend->assembleMultipartUpload('bucket', 'object.bin', $uploadId, [[
                'partNumber' => 1,
                'etag' => $part->md5Hex,
                'storagePath' => $part->path,
            ]]);
            self::fail('Changed part bytes were accepted.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('checksum verification', $error->getMessage());
        }

        self::assertSame([], glob($this->basePath . '/.tmp/*'));
        self::assertSame([], glob($this->basePath . '/bucket/*/*/*'));
    }

    public function test_abort_reports_remaining_parts_instead_of_acknowledging_cleanup(): void
    {
        $backend = new FilesystemBackend($this->basePath);
        $uploadId = bin2hex(random_bytes(8));
        $blockedPath = $this->basePath . '/.parts/' . $uploadId . '/blocked';
        mkdir($blockedPath, 0755, true);
        file_put_contents($blockedPath . '/part', 'still present');

        $this->expectException(InternalErrorException::class);
        try {
            $backend->abortMultipartUpload('abc', 'key', $uploadId);
        } finally {
            self::assertFileExists($blockedPath . '/part');
        }
    }

    public function test_failed_physical_abort_stays_queued_until_a_later_retry_succeeds(): void
    {
        $metadata = new SqliteMetadataStore($this->basePath . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'abc', 'us-east-1');
        $uploadId = bin2hex(random_bytes(8));
        $metadata->createMultipartUpload($uploadId, 'abc', 'key', 'owner');

        $storagePath = $this->basePath . '/storage';
        $backend = new FilesystemBackend($storagePath);
        $backend->createBucket('abc');
        $part = $backend->putPart('abc', 'key', $uploadId, 1, new ReadableBuffer('part bytes'));
        $metadata->putPart($uploadId, 1, $part->md5Hex, $part->size, $part->path);
        $metadata->deleteBucket('owner', 'abc');
        // Old installations may still have directory-abort queue entries.
        $legacy = new \PDO('sqlite:' . $this->basePath . '/metadata.sqlite');
        $legacy->prepare("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, key_name, upload_id, next_attempt_at)
            VALUES ('abc', 'STANDARD', '', 'abort_multipart', 'key', ?, 0)")->execute([$uploadId]);

        $blockedPath = $storagePath . '/.parts/' . $uploadId . '/blocked';
        mkdir($blockedPath);
        file_put_contents($blockedPath . '/data', 'still present');
        $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend));

        self::assertSame(0, $collector->collect(1));
        self::assertFileExists($blockedPath . '/data');
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $row = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $row);
        self::assertSame(['status' => 'pending', 'attempts' => 1], $row->fetch(\PDO::FETCH_ASSOC));

        unlink($blockedPath . '/data');
        rmdir($blockedPath);
        $connection->exec('UPDATE s3_storage_garbage SET next_attempt_at = 0');
        self::assertSame(1, $collector->collect(1));
        self::assertDirectoryExists($storagePath . '/.parts/' . $uploadId);
        self::assertFileDoesNotExist($part->path);
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_worker_exit_after_exact_part_delete_is_recovered_idempotently(): void
    {
        $databasePath = $this->basePath . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'abc', 'us-east-1');
        $uploadId = bin2hex(random_bytes(8));
        $metadata->createMultipartUpload($uploadId, 'abc', 'key', 'owner');

        $storagePath = $this->basePath . '/storage';
        $backend = new FilesystemBackend($storagePath);
        $backend->createBucket('abc');
        $part = $backend->putPart('abc', 'key', $uploadId, 1, new ReadableBuffer('part bytes'));
        $metadata->putPart($uploadId, 1, $part->md5Hex, $part->size, $part->path);
        $metadata->transaction(function () use ($metadata): void {
            $metadata->stageBucketMultipartCleanup('abc', 'STANDARD');
            $metadata->deleteBucket('owner', 'abc');
        });

        $worker = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/metadata-garbage-claim-worker.php',
            $databasePath,
            $storagePath,
        ]);
        $worker->run();
        self::assertSame(86, $worker->getExitCode(), $worker->getErrorOutput());
        self::assertFileDoesNotExist($part->path);

        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $row = $connection->query('SELECT id, status FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $row);
        self::assertSame(['id' => (int) $worker->getOutput(), 'status' => 'processing'], $row->fetch(\PDO::FETCH_ASSOC));

        $connection->exec('UPDATE s3_storage_garbage SET next_attempt_at = 0');
        self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_separate_cleanup_worker_keeps_reused_bucket_objects(): void
    {
        $databasePath = $this->basePath . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('old-owner', 'abc', 'us-east-1');
        $uploadId = bin2hex(random_bytes(8));
        $metadata->createMultipartUpload($uploadId, 'abc', 'old-key', 'old-owner');

        $storagePath = $this->basePath . '/storage';
        $backend = new FilesystemBackend($storagePath);
        $backend->createBucket('abc');
        $oldPart = $backend->putPart('abc', 'old-key', $uploadId, 1, new ReadableBuffer('old part'));
        $metadata->putPart($uploadId, 1, $oldPart->md5Hex, $oldPart->size, $oldPart->path);
        $metadata->transaction(function () use ($metadata): void {
            $metadata->stageBucketMultipartCleanup('abc', 'STANDARD');
            $metadata->deleteBucket('old-owner', 'abc');
        });

        $metadata->createBucket('new-owner', 'abc', 'us-east-1');
        $backend->createBucket('abc');
        $newObject = $backend->putObject('abc', 'new-key', new ReadableBuffer('new owner data'));
        $metadata->putObjectMetadata('abc', 'new-key', 'new-owner', $newObject->size, $newObject->md5Hex, 'text/plain', $newObject->path);

        $worker = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/Support/metadata-garbage-collect-worker.php',
            $databasePath,
            $storagePath,
        ]);
        $worker->mustRun();

        self::assertSame('1', $worker->getOutput());
        self::assertFileDoesNotExist($oldPart->path);
        self::assertSame('new-owner', $metadata->getBucketOwner('abc'));
        self::assertSame('new owner data', buffer($backend->getObjectByPath($newObject->path)));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_inline_cleanup_only_deletes_staged_paths_and_never_recurses_into_upload_directory(): void
    {
        $metadata = new SqliteMetadataStore($this->basePath . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'abc', 'us-east-1');
        $metadata->createBucket('owner', 'other', 'us-east-1');
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'abc', 'key', 'owner');
        $foreignUploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($foreignUploadId, 'abc', 'foreign-key', 'owner');
        $otherBucketUploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($otherBucketUploadId, 'other', 'other-key', 'owner');

        $storagePath = $this->basePath . '/storage';
        $backend = new FilesystemBackend($storagePath);
        $backend->createBucket('abc');
        $backend->createBucket('other');
        $part = $backend->putPart('abc', 'key', $uploadId, 1, new ReadableBuffer('part bytes'));
        $metadata->putPart($uploadId, 1, $part->md5Hex, $part->size, $part->path);
        $unreferenced = $backend->putPart('abc', 'key', $uploadId, 2, new ReadableBuffer('unreferenced bytes'));
        $plannedPath = $backend->planMultipartPartPath('abc', bin2hex(random_bytes(16)), $uploadId, 3, bin2hex(random_bytes(16)));
        $planned = $backend->putPartAtPath('abc', 'key', $uploadId, 3, new ReadableBuffer('planned bytes'), $plannedPath);
        $foreign = $backend->putPart('abc', 'foreign-key', $foreignUploadId, 1, new ReadableBuffer('foreign bytes'));
        $otherBucketPart = $backend->putPart('other', 'other-key', $otherBucketUploadId, 1, new ReadableBuffer('other bucket bytes'));
        $parts = $metadata->transaction(function () use ($metadata, $uploadId): array {
            $metadata->lockOwnerForUpdate('owner');

            return MultipartCleanup::stage($metadata, 'abc', 'key', $uploadId, 'owner', 'STANDARD');
        });

        MultipartCleanup::clean($metadata, $backend, 'abc', 'key', $uploadId, 'STANDARD', $parts);

        self::assertFileDoesNotExist($part->path);
        self::assertSame('unreferenced bytes', buffer($backend->getObjectByPath($unreferenced->path)));
        self::assertSame('planned bytes', buffer($backend->getObjectByPath($planned->path)));
        self::assertSame('foreign bytes', buffer($backend->getObjectByPath($foreign->path)));
        self::assertSame('other bucket bytes', buffer($backend->getObjectByPath($otherBucketPart->path)));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_inline_cleanup_keeps_failed_second_delete_queued_without_bulk_abort(): void
    {
        $metadata = new SqliteMetadataStore($this->basePath . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'abc', 'us-east-1');
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'abc', 'key', 'owner');

        $backend = new FilesystemBackend($this->basePath . '/storage');
        $backend->createBucket('abc');
        $first = $backend->putPart('abc', 'key', $uploadId, 1, new ReadableBuffer('first'));
        $second = $backend->putPart('abc', 'key', $uploadId, 2, new ReadableBuffer('second'));
        foreach ([$first, $second] as $index => $part) {
            $metadata->putPart($uploadId, $index + 1, $part->md5Hex, $part->size, $part->path);
        }
        $parts = $metadata->transaction(function () use ($metadata, $uploadId): array {
            $metadata->lockOwnerForUpdate('owner');

            return MultipartCleanup::stage($metadata, 'abc', 'key', $uploadId, 'owner', 'STANDARD');
        });

        $failing = $this->createMock(StorageBackend::class);
        $failing->expects(self::never())->method('abortMultipartUpload');
        $failing->method('deleteObjectByPath')->willReturnCallback(static function (string $path, string $bucket) use ($backend, $second): void {
            if ($path === $second->path) {
                throw new InternalErrorException('Injected second part delete failure.');
            }
            $backend->deleteObjectByPath($path, $bucket);
        });

        MultipartCleanup::clean($metadata, $failing, 'abc', 'key', $uploadId, 'STANDARD', $parts);

        self::assertFileDoesNotExist($first->path);
        self::assertFileExists($second->path);
        $queued = $metadata->dequeueStorageGarbage(10);
        self::assertCount(1, $queued);
        self::assertSame($second->path, $queued[0]['storage_path']);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
