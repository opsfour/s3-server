<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\File\FilesystemDriver;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\LocalEfsAbortMultipartTask;
use OpsFour\S3Server\Parallel\LocalEfsBucketNamespaceTask;
use OpsFour\S3Server\Parallel\LocalEfsDeletePathTask;
use OpsFour\S3Server\Parallel\LocalEfsTempCleanupTask;
use OpsFour\S3Server\Parallel\LocalEfsTempSizeTask;
use OpsFour\S3Server\Parallel\LocalEfsStoredPathCheckTask;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentRetirer;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\LocalObjectWriteOutcome;
use OpsFour\S3Server\Storage\EfsAwareLocalObjectWriteIo;
use OpsFour\S3Server\Storage\LocalMultipartPathFence;
use OpsFour\S3Server\Storage\LocalMultipartAssemblyFence;
use OpsFour\S3Server\Storage\LocalEfsDeleteFence;
use OpsFour\S3Server\Storage\LocalEfsBucketNamespace;
use OpsFour\S3Server\Storage\LocalEfsBucketNamespaceIo;
use OpsFour\S3Server\Storage\LocalEfsTempCleanup;
use OpsFour\S3Server\Storage\LocalEfsTempSizeFence;
use OpsFour\S3Server\Storage\LocalEfsStoredPathCheck;
use OpsFour\S3Server\Storage\LocalEfsDeleteIo;
use OpsFour\S3Server\Storage\NativeLocalEfsDeleteIo;
use OpsFour\S3Server\Storage\NativeLocalEfsBucketNamespaceIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageGarbageDiscard;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\EfsGuardedReadableStream;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTempFileLease;
use OpsFour\S3Server\Tests\Support\EfsEvidenceScopeWorkerTask;
use PHPUnit\Framework\TestCase;

final class EfsHostEvidenceReaderTest extends TestCase
{
    private const string RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string SOURCE_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string BOOT_ID = '12345678-1234-1234-1234-123456789abc';

    private const int NOW_NS = 10_000_000_000_000;

    private string $base;

    private string $evidenceDirectory;

    private string $storageRoot;

    private string $evidencePath;

    private string $mountInfo;

    private int $nowNs = self::NOW_NS;

    private string $bootId = self::BOOT_ID;

    private bool $fakeRootOwner = true;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/s3-evidence-reader-' . bin2hex(random_bytes(8));
        $this->evidenceDirectory = $this->base . '/evidence';
        $this->storageRoot = $this->base . '/storage';
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-postgres.json';
        mkdir($this->base, 0700);
        mkdir($this->evidenceDirectory, 0755);
        mkdir($this->storageRoot, 0700);
        $this->mountInfo = $this->mountInfo();
        $this->writeRecord($this->record());
    }

    protected function tearDown(): void
    {
        if (is_link($this->evidencePath) || is_file($this->evidencePath)) {
            unlink($this->evidencePath);
        }
        if (is_link($this->evidenceDirectory)) {
            unlink($this->evidenceDirectory);
        } elseif (is_dir($this->evidenceDirectory)) {
            rmdir($this->evidenceDirectory);
        }
        if (is_dir($this->storageRoot)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->storageRoot);
        }
        rmdir($this->base);
    }

    public function test_accepts_exact_fresh_heartbeat_and_new_timestamp_on_same_epoch(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $this->nowNs += 5_000_000_000;
        $this->writeRecord($this->record());
        $reader->assertFresh();
    }

    public function test_lifecycle_rejects_a_tripped_watcher_before_any_sql_or_timer_admission(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        self::assertTrue(unlink($this->evidencePath));
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('acquireLock');
        $metadata->expects(self::never())->method('getBucket');
        $metadata->expects(self::never())->method('listAllBuckets');
        $executor = new LifecycleExecutor($metadata, new InMemoryBackend(), efsEvidence: $reader);

        try {
            $executor->execute();
            self::fail('A lifecycle sweep reached SQL after the watcher tripped.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
        }
        try {
            $executor->processBucket('bucket');
            self::fail('A direct bucket sweep reached SQL after the watcher tripped.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
        }
        $runner = new LifecycleRunner($executor);
        try {
            $runner->start();
            self::fail('A lifecycle timer started after the watcher tripped.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
        }
    }

    public function test_lifecycle_stops_after_watcher_trips_during_bucket_listing(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('acquireLock')->willReturn(true);
        $metadata->expects(self::once())->method('listAllBuckets')->willReturnCallback(function (): array {
            self::assertTrue(unlink($this->evidencePath));

            return [new BucketInfo('bucket', 'owner')];
        });
        $metadata->expects(self::never())->method('getBucket');
        $metadata->expects(self::never())->method('releaseLock');
        $executor = new LifecycleExecutor($metadata, new InMemoryBackend(), efsEvidence: $reader);

        try {
            $executor->execute();
            self::fail('A lifecycle bucket was processed after the watcher tripped.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
        }
    }

    public function test_lifecycle_rechecks_watcher_after_bucket_lookup_before_cleanup_or_rules(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getBucket')->willReturnCallback(function (): BucketInfo {
            self::assertTrue(unlink($this->evidencePath));

            return new BucketInfo('bucket', 'owner');
        });
        $metadata->expects(self::never())->method('listExpiredMultipartUploads');
        $metadata->expects(self::never())->method('getBucketLifecycle');
        $executor = new LifecycleExecutor($metadata, new InMemoryBackend(), efsEvidence: $reader);

        try {
            $executor->processBucket('bucket');
            self::fail('Lifecycle continued to cleanup or rules after the watcher tripped.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
        }
    }

    public function test_bucket_namespace_provision_is_idempotent_and_checks_private_directory(): void
    {
        $native = new \OpsFour\S3Server\Storage\NativeLocalEfsBucketNamespaceIo();
        $io = $this->createMock(LocalEfsBucketNamespaceIo::class);
        $io->method('lstat')->willReturnCallback($native->lstat(...));
        $io->method('listDirectory')->willReturnCallback($native->listDirectory(...));
        $io->expects(self::once())->method('mkdir')->willReturnCallback($native->mkdir(...));
        $io->expects(self::exactly(2))->method('syncDirectory')->willReturn(true);
        $namespace = new LocalEfsBucketNamespace($this->storageRoot, $this->reader(), $io);

        self::assertFalse($namespace->bucketExists('bucket'));
        $namespace->createBucket('bucket');
        self::assertTrue($namespace->bucketExists('bucket'));
        $namespace->createBucket('bucket');
        self::assertSame(0700, fileperms($this->storageRoot . '/bucket') & 0o7777);
    }

    public function test_native_bucket_and_delete_directory_sync_stop_after_evidence_loss_at_open(): void
    {
        foreach ([new NativeLocalEfsBucketNamespaceIo(), new NativeLocalEfsDeleteIo()] as $io) {
            $this->writeRecord($this->record());
            $reader = $this->reader();
            $checks = 0;

            try {
                $io->syncDirectory($this->storageRoot, function () use ($reader, &$checks): void {
                    $checks++;
                    if ($checks === 1) {
                        self::assertTrue(unlink($this->evidencePath));
                    }
                    $reader->assertFresh();
                });
                self::fail('Directory sync continued after EFS host evidence loss.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }

            self::assertSame(1, $checks);
        }
    }

    public function test_bucket_namespace_rejects_symlink_and_loose_permissions(): void
    {
        $native = new \OpsFour\S3Server\Storage\NativeLocalEfsBucketNamespaceIo();
        $io = $this->createMock(LocalEfsBucketNamespaceIo::class);
        $io->method('lstat')->willReturnCallback($native->lstat(...));
        $io->method('listDirectory')->willReturnCallback($native->listDirectory(...));
        $io->expects(self::never())->method('mkdir');
        $io->expects(self::never())->method('syncDirectory');
        $namespace = new LocalEfsBucketNamespace($this->storageRoot, $this->reader(), $io);

        mkdir($this->storageRoot . '/loose', 0755);
        chmod($this->storageRoot . '/loose', 0755);
        try {
            $namespace->createBucket('loose');
            self::fail('A non-private bucket directory was accepted.');
        } catch (\RuntimeException) {
            // Existing directory is neither repaired nor acknowledged.
        }
        symlink($this->storageRoot . '/loose', $this->storageRoot . '/linked');
        try {
            $namespace->bucketExists('linked');
            self::fail('A symlinked bucket was accepted.');
        } catch (\RuntimeException) {
            // Unsafe namespace remains unacknowledged.
        }
    }

    public function test_bucket_namespace_rejects_unavailable_parent_listing_and_uncertain_sync(): void
    {
        $io = $this->createMock(LocalEfsBucketNamespaceIo::class);
        $io->method('lstat')->willReturn(false);
        $io->method('listDirectory')->willReturn(false);
        $io->expects(self::never())->method('mkdir');
        $namespace = new LocalEfsBucketNamespace($this->storageRoot, $this->reader(), $io);
        try {
            $namespace->bucketExists('bucket');
            self::fail('An unavailable parent listing was accepted as an absent bucket.');
        } catch (\RuntimeException) {
            // Uncertain absence is not a negative existence result.
        }

        $native = new \OpsFour\S3Server\Storage\NativeLocalEfsBucketNamespaceIo();
        $io = $this->createMock(LocalEfsBucketNamespaceIo::class);
        $io->method('lstat')->willReturnCallback($native->lstat(...));
        $io->method('listDirectory')->willReturnCallback($native->listDirectory(...));
        $io->method('mkdir')->willReturnCallback($native->mkdir(...));
        $io->expects(self::once())->method('syncDirectory')->willReturn(false);
        $namespace = new LocalEfsBucketNamespace($this->storageRoot, $this->reader(), $io);
        try {
            $namespace->createBucket('bucket');
            self::fail('A namespace create without a durable parent sync was acknowledged.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('durability', $error->getMessage());
        }
        self::assertDirectoryExists($this->storageRoot . '/bucket');
    }

    public function test_bucket_namespace_retains_uncertain_mkdir_after_evidence_loss(): void
    {
        $native = new \OpsFour\S3Server\Storage\NativeLocalEfsBucketNamespaceIo();
        $io = $this->createMock(LocalEfsBucketNamespaceIo::class);
        $io->method('lstat')->willReturnCallback($native->lstat(...));
        $io->method('listDirectory')->willReturnCallback($native->listDirectory(...));
        $io->method('mkdir')->willReturnCallback(function (string $path) use ($native): bool {
            $created = $native->mkdir($path);
            unlink($this->evidencePath);
            return $created;
        });
        $io->expects(self::never())->method('syncDirectory');
        $namespace = new LocalEfsBucketNamespace($this->storageRoot, $this->reader(), $io);

        try {
            $namespace->createBucket('bucket');
            self::fail('An uncertain namespace create was acknowledged.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertDirectoryExists($this->storageRoot . '/bucket');
    }

    public function test_guarded_read_admission_rejects_third_session_before_submit(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsReadPool: $pool, isolatedEfsRead: true);
        (new \ReflectionProperty($backend, 'efsReadAdmissions'))->setValue($backend, 2);

        $this->expectException(\OpsFour\S3Server\Exception\SlowDownException::class);
        $backend->getObjectByPath($this->storageRoot . '/bucket/object');
    }

    public function test_shared_efs_delete_pool_rejects_third_task_before_submit(): void
    {
        $first = new DeferredFuture();
        $second = new DeferredFuture();
        $submitted = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(static function ($task) use (&$submitted, $first, $second): Execution {
            $submitted++;
            [$channel] = \Amp\Sync\createChannelPair();

            return new Execution($task, $channel, ($submitted === 1 ? $first : $second)->getFuture());
        });
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsDeletePool: $pool);
        $cleanup = \Amp\async(static fn(): int => $backend->cleanupStaleTempFiles());
        $abort = \Amp\async(static function () use ($backend): void {
            $backend->abortMultipartUpload('bucket', 'key', str_repeat('a', 32));
        });
        \Amp\delay(0.001);
        self::assertSame(2, $submitted);
        self::assertSame(2, (new \ReflectionProperty($backend, 'efsDeleteAdmissions'))->getValue($backend));

        $path = StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', str_repeat('b', 32), str_repeat('c', 32));
        try {
            $backend->deleteObjectByPath($path, 'bucket');
            self::fail('A third EFS delete-pool task was queued behind two held workers.');
        } catch (\OpsFour\S3Server\Exception\SlowDownException) {
            self::assertSame(2, $submitted);
        }

        $first->complete(0);
        $second->complete(null);
        self::assertSame(0, $cleanup->await());
        $abort->await();
        self::assertSame(0, (new \ReflectionProperty($backend, 'efsDeleteAdmissions'))->getValue($backend));
    }

    public function test_shared_efs_delete_pool_reuses_only_definitive_fresh_worker_result(): void
    {
        $responses = [
            new \Amp\Parallel\Worker\TaskFailureException(\RuntimeException::class, 'definitive', 0, __FILE__, __LINE__, []),
            new \Amp\Parallel\Worker\WorkerException('uncertain IPC result'),
        ];
        $submitted = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(static function ($task) use (&$responses, &$submitted): Execution {
            [$channel] = \Amp\Sync\createChannelPair();
            $done = new DeferredFuture();
            $done->error($responses[$submitted++]);

            return new Execution($task, $channel, $done->getFuture());
        });
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsDeletePool: $pool);
        try {
            $backend->cleanupStaleTempFiles();
            self::fail('A failed EFS task was acknowledged.');
        } catch (\Amp\Parallel\Worker\TaskFailureException) {
            self::assertSame(0, (new \ReflectionProperty($backend, 'efsDeleteAdmissions'))->getValue($backend));
        }
        try {
            $backend->cleanupStaleTempFiles();
            self::fail('An uncertain EFS task was acknowledged.');
        } catch (\Amp\Parallel\Worker\WorkerException) {
            self::assertSame(1, (new \ReflectionProperty($backend, 'efsDeleteAdmissions'))->getValue($backend));
        }
        self::assertSame(2, $submitted);
    }

    public function test_shared_efs_delete_pool_retains_slot_after_watcher_loss(): void
    {
        $evidencePath = $this->evidencePath;
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(static function ($task) use ($evidencePath): Execution {
            [$channel] = \Amp\Sync\createChannelPair();
            $done = new DeferredFuture();
            $done->complete(0);
            unlink($evidencePath);

            return new Execution($task, $channel, $done->getFuture());
        });
        $reader = $this->reader();
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $reader, efsDeletePool: $pool);
        try {
            $backend->cleanupStaleTempFiles();
            self::fail('A watcher-lost EFS task was acknowledged.');
        } catch (\RuntimeException) {
            self::assertSame(1, (new \ReflectionProperty($backend, 'efsDeleteAdmissions'))->getValue($backend));
        }
    }

    public function test_required_isolated_read_rejects_legacy_backend_before_any_file_open(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        $filesystem = new Filesystem($driver);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require an injected two-worker isolated read pool');
        new FilesystemBackend(
            $this->storageRoot,
            $filesystem,
            $filesystem,
            efsEvidence: $this->reader(),
            requireIsolatedReadSession: true,
        );
    }

    public function test_required_isolated_read_accepts_only_an_injected_two_worker_pool(): void
    {
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $wrongPool = new ContextWorkerPool(3);
        try {
            new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $wrongPool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );
            self::fail('An unbounded EFS read pool was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('two-worker', $error->getMessage());
        } finally {
            $wrongPool->shutdown();
        }

        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );
            self::assertSame($this->reader()->storageRoot(), $backend->getBasePath());
        } finally {
            $pool->shutdown();
        }
    }

    public function test_required_isolated_read_cannot_resurrect_a_worker_after_backend_shutdown(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        $filesystem = new Filesystem($driver);
        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );
            $backend->shutdown();

            try {
                $backend->getObjectByPath($this->storageRoot . '/bucket/object');
                self::fail('A qualified EFS backend reopened a read after shutdown.');
            } catch (InternalErrorException $error) {
                self::assertStringContainsString('shutting down', $error->getMessage());
            }
            self::assertNull((new \ReflectionProperty($backend, 'efsReadPool'))->getValue($backend));
            self::assertSame([], (new \ReflectionProperty($backend, 'ownedWorkerPools'))->getValue($backend));
            self::assertSame(0, $pool->getWorkerCount());
        } finally {
            $pool->shutdown();
        }
    }

    public function test_required_isolated_read_rejects_direct_copy_before_storage_io(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        $filesystem = new Filesystem($driver);
        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );

            $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
            $this->expectExceptionMessage('requires an isolated read and fenced write path');
            $backend->copyObject($this->storageRoot . '/bucket/object', 'bucket', 'copy');
        } finally {
            $pool->shutdown();
        }
    }

    public function test_required_isolated_read_rejects_bounded_copy_before_storage_io(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        $filesystem = new Filesystem($driver);
        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );

            $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
            $this->expectExceptionMessage('requires a fenced destination writer');
            $backend->copyObjectBounded($this->storageRoot . '/bucket/object', 'bucket', 'copy', 1024);
        } finally {
            $pool->shutdown();
        }
    }

    public function test_required_isolated_read_rejects_every_direct_object_and_part_write(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        $filesystem = new Filesystem($driver);
        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );
            $generation = str_repeat('a', 32);
            $attempt = str_repeat('b', 32);
            $uploadId = str_repeat('c', 32);
            $objectPath = StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt);
            $partPath = StoragePath::forPlannedPart($this->storageRoot, 'bucket', $generation, $uploadId, 1, $attempt);
            $completionPath = StoragePath::forMultipartCompletion($this->storageRoot, 'bucket', 'key', $generation, $attempt)['assembly'];

            $writes = [
                'putObject' => static fn() => $backend->putObject('bucket', 'key', new ReadableBuffer('')),
                'putObjectAtPath' => static fn() => $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer(''), $objectPath),
                'putPart' => static fn() => $backend->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer('')),
                'putPartAtPath' => static fn() => $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer(''), $partPath),
                'assembleMultipartUpload' => static fn() => $backend->assembleMultipartUpload('bucket', 'key', $uploadId, []),
                'assembleMultipartUploadAtPath' => static fn() => $backend->assembleMultipartUploadAtPath('bucket', 'key', $uploadId, [], $completionPath),
            ];
            foreach ($writes as $name => $write) {
                try {
                    $write();
                    self::fail("Qualified EFS accepted direct {$name}.");
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('isolated fenced writer', $error->getMessage());
                }
            }
            self::assertSame(['.', '..'], scandir($this->storageRoot));
        } finally {
            $pool->shutdown();
        }
    }

    public function test_required_isolated_read_rejects_all_efs_task_dispatches_after_shutdown(): void
    {
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $pool = new ContextWorkerPool(2);
        try {
            $backend = new FilesystemBackend(
                $this->storageRoot,
                $filesystem,
                $filesystem,
                efsEvidence: $this->reader(),
                efsReadPool: $pool,
                isolatedEfsRead: true,
                requireIsolatedReadSession: true,
            );
            $backend->shutdown();
            $generation = str_repeat('a', 32);
            $attempt = str_repeat('b', 32);
            $uploadId = str_repeat('c', 32);
            $objectPath = StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt);
            $completionPath = StoragePath::forMultipartCompletion($this->storageRoot, 'bucket', 'key', $generation, $attempt)['assembly'];

            $dispatches = [
                'createBucket' => static fn() => $backend->createBucket('bucket'),
                'bucketExists' => static fn() => $backend->bucketExists('bucket'),
                'deleteObjectByPath' => static fn() => $backend->deleteObjectByPath($objectPath, 'bucket'),
                'abortMultipartUpload' => static fn() => $backend->abortMultipartUpload('bucket', 'key', $uploadId),
                'cleanupStaleTempFiles' => static fn() => $backend->cleanupStaleTempFiles(),
                'assembleMultipartUploadFencedAtPath' => static fn() => $backend->assembleMultipartUploadFencedAtPath('bucket', 'key', $generation, $attempt, $uploadId, [], $completionPath),
                'sealFencedMultipartPath' => static fn() => $backend->sealFencedMultipartPath($completionPath, $attempt),
                'getObjectByPath' => static fn() => $backend->getObjectByPath($objectPath),
            ];
            foreach ($dispatches as $name => $dispatch) {
                try {
                    $dispatch();
                    self::fail("Qualified EFS dispatched {$name} after shutdown.");
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('shutting down', $error->getMessage());
                }
                self::assertSame([], (new \ReflectionProperty($backend, 'ownedWorkerPools'))->getValue($backend));
            }
            self::assertSame(['.', '..'], scandir($this->storageRoot));
        } finally {
            $pool->shutdown();
        }
    }

    public function test_guarded_read_releases_slot_only_after_definitive_worker_result_and_fresh_evidence(): void
    {
        [$parent, $worker] = \Amp\Sync\createChannelPair();
        $done = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturn(new Execution(
            $this->createMock(\Amp\Parallel\Worker\Task::class),
            $parent,
            $done->getFuture(),
        ));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsReadPool: $pool, isolatedEfsRead: true);
        $producer = \Amp\async(static function () use ($worker): void {
            $worker->send(['chunk' => 'held']);
        });
        $stream = $backend->getObjectByPath($this->storageRoot . '/bucket/object');
        $admissions = new \ReflectionProperty($backend, 'efsReadAdmissions');
        self::assertSame(1, $admissions->getValue($backend));
        self::assertSame('held', $stream->read());
        $stream->close();
        self::assertSame(1, $admissions->getValue($backend));
        $producer->await();
        self::assertSame('cancel', $worker->receive());
        $done->complete();
        \Amp\delay(0.001);
        self::assertSame(0, $admissions->getValue($backend));
    }

    public function test_guarded_read_keeps_slot_after_worker_result_if_evidence_was_lost(): void
    {
        [$parent, $worker] = \Amp\Sync\createChannelPair();
        $done = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturn(new Execution(
            $this->createMock(\Amp\Parallel\Worker\Task::class),
            $parent,
            $done->getFuture(),
        ));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsReadPool: $pool, isolatedEfsRead: true);
        $producer = \Amp\async(static function () use ($worker): void {
            $worker->send(['chunk' => 'held']);
        });
        $stream = $backend->getObjectByPath($this->storageRoot . '/bucket/object');
        self::assertSame('held', $stream->read());
        $stream->close();
        $producer->await();
        self::assertSame('cancel', $worker->receive());
        unlink($this->evidencePath);
        $done->complete();
        \Amp\delay(0.001);
        self::assertSame(1, (new \ReflectionProperty($backend, 'efsReadAdmissions'))->getValue($backend));
    }

    public function test_guarded_read_keeps_slot_after_ambiguous_ipc_failure(): void
    {
        [$parent, $worker] = \Amp\Sync\createChannelPair();
        $done = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturn(new Execution(
            $this->createMock(\Amp\Parallel\Worker\Task::class),
            $parent,
            $done->getFuture(),
        ));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader(), efsReadPool: $pool, isolatedEfsRead: true);
        $producer = \Amp\async(static function () use ($worker): void {
            $worker->send(['chunk' => 'held']);
        });
        $stream = $backend->getObjectByPath($this->storageRoot . '/bucket/object');
        self::assertSame('held', $stream->read());
        $stream->close();
        $producer->await();
        self::assertSame('cancel', $worker->receive());
        $done->error(new \Amp\Parallel\Worker\WorkerException('IPC lost after submit'));
        \Amp\delay(0.001);
        self::assertSame(1, (new \ReflectionProperty($backend, 'efsReadAdmissions'))->getValue($backend));
    }

    public function test_rejects_stale_missing_and_wrong_boot_records_without_leaking_json(): void
    {
        foreach (['stale', 'missing', 'boot', 'scope'] as $case) {
            $this->writeRecord($this->record());
            $reader = $this->reader();
            if ($case === 'stale') {
                $this->nowNs += 21_000_000_000;
            } elseif ($case === 'missing') {
                unlink($this->evidencePath);
            } elseif ($case === 'boot') {
                $this->bootId = '99999999-1234-1234-1234-123456789abc';
            } else {
                $record = $this->record();
                $record['efsId'] = 'fs-99999999';
                $this->writeRecord($record);
            }
            $this->assertRejected($reader);
            $this->nowNs = self::NOW_NS;
            $this->bootId = self::BOOT_ID;
        }
    }

    public function test_rejects_malformed_duplicate_extra_or_oversized_json(): void
    {
        foreach (['{"secret":"credential-value"}\n', str_repeat('x', 4097), 'duplicate', 'extra'] as $case) {
            $this->writeRecord($this->record());
            $reader = $this->reader();
            if ($case === 'duplicate') {
                $raw = file_get_contents($this->evidencePath);
                self::assertIsString($raw);
                file_put_contents($this->evidencePath, str_replace('"runId":', '"runId":"secret-value","runId":', $raw));
            } elseif ($case === 'extra') {
                $record = $this->record();
                $record['secret'] = 'credential-value';
                $this->writeRecord($record);
            } else {
                file_put_contents($this->evidencePath, $case);
            }
            $this->assertRejected($reader);
        }
    }

    public function test_rejects_changed_watcher_epoch_and_latches_revocation(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $record = $this->record();
        $record['epoch'] = 'dddddddddddddddddddddddddddddddd';
        $this->writeRecord($record);
        $this->assertRejected($reader);
        $this->writeRecord($this->record());
        $this->assertRejected($reader);
    }

    public function test_rejects_storage_identity_change(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $record = $this->record();
        $record['storageIno'] = (int) $record['storageIno'] + 1;
        $this->writeRecord($record);
        $this->assertRejected($reader);
    }

    public function test_rejects_relay_or_host_mount_change_within_same_epoch(): void
    {
        foreach (['relayPid', 'relayPort', 'hostMountId'] as $field) {
            $this->writeRecord($this->record());
            $reader = $this->reader();
            $reader->assertFresh();
            $record = $this->record();
            $record[$field] = $field === 'hostMountId' ? '40' : (int) $record[$field] + 1;
            $this->writeRecord($record);
            $this->assertRejected($reader);
        }
    }

    public function test_rejects_symlink_and_world_writable_evidence(): void
    {
        unlink($this->evidencePath);
        symlink('/etc/passwd', $this->evidencePath);
        $this->assertRejected($this->reader());
        unlink($this->evidencePath);
        $this->writeRecord($this->record());
        chmod($this->evidencePath, 0666);
        $this->assertRejected($this->reader());
    }

    public function test_requires_root_owner_and_exact_read_only_tmpfs_bind(): void
    {
        $this->fakeRootOwner = false;
        self::expectException(\RuntimeException::class);
        $this->reader();
    }

    public function test_rejects_writable_or_overmounted_evidence_bind(): void
    {
        foreach (['rw', 'nested', 'nfs'] as $case) {
            $this->mountInfo = $this->mountInfo($case);
            try {
                $this->reader();
                self::fail('Unsafe evidence bind was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('read-only local bind', $exception->getMessage());
            }
        }
    }

    public function test_rejects_bind_change_after_initial_valid_record(): void
    {
        $reader = $this->reader();
        $reader->assertFresh();
        $this->mountInfo = $this->mountInfo('nfs');
        $this->assertRejected($reader);
    }

    public function test_later_checks_do_not_stat_the_storage_root(): void
    {
        $reader = $this->reader();
        rmdir($this->storageRoot);
        $reader->assertFresh();
        // The startup snapshot cannot prove continued mount health; the host watcher must stop the exact container.
    }

    public function test_worker_scope_serializes_and_rechecks_without_storage_root_stat(): void
    {
        $scope = unserialize(serialize($this->reader()->workerScope()));
        self::assertInstanceOf(EfsWorkerEvidenceScope::class, $scope);
        rmdir($this->storageRoot);
        $worker = EfsHostEvidenceReader::fromWorkerScope(
            $scope,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            fn(): string => $this->mountInfo,
        );
        $worker->assertFresh();
        self::assertInstanceOf(LocalObjectWriteFence::class, new LocalObjectWriteFence(
            $scope->storageRoot,
            new NativeLocalObjectWriteIo(),
            $worker,
        ));

        $raw = file_get_contents($this->evidencePath);
        self::assertIsString($raw);
        $record = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $record['epoch'] = str_repeat('d', 32);
        file_put_contents($this->evidencePath, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
        $this->assertRejected($worker);
    }

    public function test_guarded_object_and_multipart_fences_reject_non_evidence_aware_io(): void
    {
        $io = $this->createStub(LocalObjectWriteIo::class);
        $factories = [
            fn(): LocalObjectWriteFence => new LocalObjectWriteFence($this->storageRoot, $io, $this->reader()),
            fn(): LocalMultipartPathFence => new LocalMultipartPathFence($this->storageRoot, $io, $this->reader()),
        ];

        foreach ($factories as $factory) {
            try {
                $factory();
                self::fail('Guarded storage accepted I/O without evidence-aware directory synchronization.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('evidence-aware', $error->getMessage());
            }
        }
    }

    public function test_worker_scope_crosses_a_real_amp_process_channel(): void
    {
        $scope = $this->reader()->workerScope();
        $pool = new ContextWorkerPool(1);
        try {
            $result = $pool->submit(new EfsEvidenceScopeWorkerTask($scope))->getFuture()->await();
            self::assertSame(self::RUN_ID . ':' . str_repeat('e', 32), $result);
        } finally {
            $pool->shutdown();
        }
    }

    public function test_worker_scope_rejects_replaced_local_evidence_directory(): void
    {
        $scope = $this->reader()->workerScope();
        $wrong = new EfsWorkerEvidenceScope(
            $scope->evidenceDirectory,
            $scope->storageRoot,
            $scope->runId,
            $scope->efsId,
            $scope->node,
            $scope->driver,
            $scope->sourceSha256,
            $scope->storageDev,
            $scope->storageIno,
            $scope->directoryDev,
            '999999999',
            $scope->pinnedIdentity,
        );
        self::expectException(\RuntimeException::class);
        EfsHostEvidenceReader::fromWorkerScope(
            $wrong,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            fn(): string => $this->mountInfo,
        );
    }

    public function test_evidence_loss_after_armed_intent_and_data_sync_prevents_physical_link(): void
    {
        $reader = $this->reader();
        $reader->workerScope();
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath);
        $fence = new LocalObjectWriteFence($this->storageRoot, $io, $reader);
        $attempt = str_repeat('f', 32);
        $intent = new ObjectWriteIntent(
            $attempt,
            'bucket',
            str_repeat('a', 32),
            'owner',
            'STANDARD',
            StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', str_repeat('a', 32), $attempt),
            'put_object',
        );
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $reserved = $metadata->reserveObjectWriteIntent($intent, 60.0);
        $armed = $metadata->armObjectWriteIntent($attempt, $reserved->leaseUntil, str_repeat('1', 32), 60.0);
        self::assertInstanceOf(ObjectWriteIntent::class, $armed);
        try {
            $fence->write($armed, 'key', new ReadableBuffer('payload'));
            self::fail('Lost evidence allowed a physical hard link.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertFalse((new LocalObjectWriteIntentRetirer())->retire(
                $armed,
                LocalObjectWriteOutcome::completed($armed, null, $error),
            ));
        }
        self::assertSame(0, $io->linkCalls);
        self::assertFileDoesNotExist($intent->storagePath);
        self::assertSame('active', $metadata->getObjectWriteIntent($attempt)?->state);
    }

    public function test_object_write_stops_before_the_next_efs_chunk_after_evidence_loss(): void
    {
        $reader = $this->reader();
        $reader->workerScope();
        $fence = new LocalObjectWriteFence($this->storageRoot, new NativeLocalObjectWriteIo(), $reader);
        $attempt = str_repeat('f', 32);
        $generation = str_repeat('a', 32);
        $intent = new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt),
            'put_object',
        );
        $body = new EvidenceDroppingReadableStream($this->evidencePath);

        try {
            $fence->write($intent, 'key', $body);
            self::fail('The EFS write continued after host evidence loss.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }

        self::assertFileDoesNotExist($intent->storagePath);
        $stages = glob($this->storageRoot . '/.tmp/*') ?: [];
        self::assertCount(1, $stages);
        self::assertSame('first-chunk', file_get_contents($stages[0]));
    }

    public function test_guarded_object_writer_rejects_existing_public_namespace_directory(): void
    {
        $bucketDirectory = $this->storageRoot . '/bucket';
        self::assertTrue(mkdir($bucketDirectory, 0o700));
        self::assertTrue(chmod($bucketDirectory, 0o755));
        $reader = $this->reader();
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath);
        $fence = new LocalObjectWriteFence($this->storageRoot, $io, $reader);
        $attempt = str_repeat('f', 32);
        $generation = str_repeat('a', 32);
        $intent = new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt),
            'put_object',
        );

        try {
            $fence->write($intent, 'key', new ReadableBuffer('payload'));
            self::fail('A public EFS namespace directory was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private and process-owned', $error->getMessage());
        }
        self::assertSame(0, $io->linkCalls);
        self::assertFileDoesNotExist($intent->storagePath);
    }

    public function test_collector_does_not_claim_a_due_intent_after_evidence_loss(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $armed = $this->armedIntent($metadata);
        self::assertTrue($metadata->abandonObjectWriteIntent($armed->attemptId));
        $reader = $this->reader();
        $collector = new LocalObjectWriteIntentCollector(
            $metadata,
            $this->localTiers($reader),
            'STANDARD',
            efsEvidence: $reader,
        );
        unlink($this->evidencePath);

        try {
            $collector->collect(1);
            self::fail('Collector claimed an intent without host evidence.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertSame('active', $metadata->getObjectWriteIntent($armed->attemptId)?->state);
            self::assertFileDoesNotExist($armed->storagePath);
        } finally {
            $collector->shutdown();
        }
    }

    public function test_collector_does_not_retire_an_undispatched_intent_after_evidence_loss(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $attempt = str_repeat('e', 32);
        $generation = str_repeat('a', 32);
        $intent = $metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt),
            'put_object',
        ), 60.0);
        self::assertTrue($metadata->abandonObjectWriteIntent($attempt));
        $reader = $this->reader();
        $collector = new LocalObjectWriteIntentCollector(
            $metadata,
            $this->localTiers($reader),
            'STANDARD',
            efsEvidence: $reader,
        );
        unlink($this->evidencePath);

        try {
            $collector->collect(1);
            self::fail('Collector retired an intent without host evidence.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertSame('active', $metadata->getObjectWriteIntent($intent->attemptId)?->state);
            self::assertFileDoesNotExist($intent->storagePath);
        } finally {
            $collector->shutdown();
        }
    }

    public function test_evidence_loss_after_seal_sync_blocks_rename_and_retains_sql_claim(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $armed = $this->armedIntent($metadata);
        self::assertTrue($metadata->abandonObjectWriteIntent($armed->attemptId));
        $claims = $metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claims);
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath);
        $reader = $this->reader();
        $reconciler = new LocalObjectWriteIntentReconciler(
            $metadata,
            $this->localTiers($reader),
            'STANDARD',
            $io,
            efsEvidence: $reader,
        );

        try {
            $reconciler->reconcile($claims[0]);
            self::fail('Seal rename continued after the host evidence disappeared.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertSame(0, $io->renameCalls);
            self::assertSame('reconciling', $metadata->getObjectWriteIntent($armed->attemptId)?->state);
            self::assertFileDoesNotExist($armed->storagePath);
        }
    }

    public function test_late_evidence_loss_after_seal_rename_does_not_confirm_sql(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $armed = $this->armedIntent($metadata);
        self::assertTrue($metadata->abandonObjectWriteIntent($armed->attemptId));
        $claims = $metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claims);
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath, dropAfterRename: true);
        $reader = $this->reader();
        $reconciler = new LocalObjectWriteIntentReconciler(
            $metadata,
            $this->localTiers($reader),
            'STANDARD',
            $io,
            efsEvidence: $reader,
        );

        try {
            $reconciler->reconcile($claims[0]);
            self::fail('A late host trip confirmed the SQL seal.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertSame(1, $io->renameCalls);
            self::assertSame('reconciling', $metadata->getObjectWriteIntent($armed->attemptId)?->state);
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($armed->storagePath));
        }
    }

    public function test_multipart_part_evidence_loss_after_data_sync_blocks_link_and_retains_armed_intent(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = str_repeat('e', 32);
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attempt = str_repeat('f', 32);
        $target = StoragePath::forPlannedPart($this->storageRoot, 'bucket', $generation, $uploadId, 1, $attempt);
        $metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $target);
        self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $target, str_repeat('1', 32)));
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $staged = $this->storageRoot . '/.tmp/staged';
        file_put_contents($staged, 'part payload');
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath);
        $fence = new LocalMultipartPathFence($this->storageRoot, $io, $this->reader());

        try {
            $fence->publish($staged, $target);
            self::fail('Lost evidence allowed a multipart part hard link.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(0, $io->linkCalls);
        self::assertFileDoesNotExist($target);
        self::assertCount(1, $metadata->listMultipartPartWriteIntents('owner', 0, 10));
    }

    public function test_multipart_completion_evidence_loss_after_seal_sync_blocks_rename_and_retains_claim(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUpload('upload', 'bucket', 'key', 'owner');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attempt = str_repeat('f', 32);
        $paths = StoragePath::forMultipartCompletion($this->storageRoot, 'bucket', 'key', $generation, $attempt);
        $now = $metadata->multipartWriteIntentNow();
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            $attempt,
            'upload',
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            $paths['assembly'],
            $paths['final'],
            str_repeat('c', 64),
            null,
            $now - 1,
            $now - 1,
        ));
        $claims = $metadata->claimDueMultipartWriteIntents(1, $now, $now + 60);
        self::assertCount(1, $claims);
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath);
        $fence = new LocalMultipartPathFence($this->storageRoot, $io, $this->reader());

        try {
            $fence->seal($paths['assembly'], $attempt);
            self::fail('Lost evidence allowed a multipart seal rename.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(0, $io->renameCalls);
        self::assertFileDoesNotExist($paths['assembly']);
        self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attempt)?->state);
        self::assertSame(1, $metadata->getMultipartWriteIntent($attempt)->claimGeneration);
    }

    public function test_guarded_multipart_seal_rejects_existing_0755_directory_before_marker_creation(): void
    {
        $target = StoragePath::forPlannedPart(
            $this->storageRoot,
            'bucket',
            str_repeat('a', 32),
            str_repeat('b', 32),
            1,
            str_repeat('c', 32),
        );
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(chmod(dirname($target), 0755));
        $fence = new LocalMultipartPathFence($this->storageRoot, efsEvidence: $this->reader());

        try {
            $fence->seal($target, str_repeat('c', 32));
            self::fail('A broad-permission directory admitted a guarded multipart seal.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private and process-owned', $error->getMessage());
        }
        self::assertFileDoesNotExist($target);
        self::assertSame([], glob(dirname($target) . '/.seal-*') ?: []);
    }

    public function test_multipart_completion_late_evidence_loss_after_rename_retains_claim(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUpload('upload', 'bucket', 'key', 'owner');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attempt = str_repeat('f', 32);
        $paths = StoragePath::forMultipartCompletion($this->storageRoot, 'bucket', 'key', $generation, $attempt);
        $now = $metadata->multipartWriteIntentNow();
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            $attempt,
            'upload',
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            $paths['assembly'],
            $paths['final'],
            str_repeat('c', 64),
            null,
            $now - 1,
            $now - 1,
        ));
        self::assertCount(1, $metadata->claimDueMultipartWriteIntents(1, $now, $now + 60));
        $io = new EvidenceDropBeforeLinkIo($this->evidencePath, dropAfterRename: true);
        $fence = new LocalMultipartPathFence($this->storageRoot, $io, $this->reader());

        try {
            $fence->seal($paths['assembly'], $attempt);
            self::fail('A late evidence loss confirmed the multipart seal.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(1, $io->renameCalls);
        self::assertStringStartsWith('OPSFOUR-LOCAL-MULTIPART-SEAL-V1', (string) file_get_contents($paths['assembly']));
        self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attempt)?->state);
        self::assertSame(1, $metadata->getMultipartWriteIntent($attempt)->claimGeneration);
    }

    public function test_efs_delete_evidence_loss_after_listing_blocks_unlink(): void
    {
        $directory = $this->storageRoot . '/bucket';
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory . '/object';
        file_put_contents($path, 'retained payload');
        $io = new EvidenceDropDeleteIo($this->evidencePath, dropAfterList: true);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        try {
            $fence->deletePath($path, 'bucket');
            self::fail('Lost evidence allowed a physical unlink.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(0, $io->unlinkCalls);
        self::assertSame('retained payload', file_get_contents($path));
    }

    public function test_efs_delete_loss_after_uncertain_unlink_never_reports_success(): void
    {
        $directory = $this->storageRoot . '/bucket';
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory . '/object';
        file_put_contents($path, 'payload');
        $io = new EvidenceDropDeleteIo($this->evidencePath, dropAfterUnlink: true);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        try {
            $fence->deletePath($path, 'bucket');
            self::fail('Delete worker confirmed a result after evidence loss.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(1, $io->unlinkCalls);
        self::assertFileDoesNotExist($path);
    }

    public function test_efs_delete_false_result_after_actual_unlink_remains_uncertain(): void
    {
        $directory = $this->storageRoot . '/bucket';
        self::assertTrue(mkdir($directory, 0700));
        $path = $directory . '/object';
        file_put_contents($path, 'payload');
        $io = new EvidenceDropDeleteIo($this->evidencePath, reportFailureAfterUnlink: true);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        try {
            $fence->deletePath($path, 'bucket');
            self::fail('Ambiguous unlink was reported as a confirmed deletion.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('uncertain', $error->getMessage());
        }
        self::assertSame(1, $io->unlinkCalls);
        self::assertFileDoesNotExist($path);
    }

    public function test_efs_delete_and_abort_confirm_absent_directories_with_fresh_parent_listing(): void
    {
        $io = new EvidenceDropDeleteIo($this->evidencePath);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        $fence->deletePath($this->storageRoot . '/bucket/object', 'bucket');
        $fence->abortMultipart(str_repeat('f', 32));

        self::assertSame(0, $io->unlinkCalls);
        self::assertSame(2, $io->listCalls);
    }

    public function test_efs_delete_missing_directory_rejects_unavailable_parent_listing(): void
    {
        $io = new EvidenceDropDeleteIo($this->evidencePath, failListing: true);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        try {
            $fence->deletePath($this->storageRoot . '/bucket/object', 'bucket');
            self::fail('Unavailable parent listing was accepted as a missing object.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unavailable', $error->getMessage());
        }
        self::assertSame(0, $io->unlinkCalls);
    }

    public function test_efs_delete_missing_directory_rejects_evidence_loss_during_parent_listing(): void
    {
        $io = new EvidenceDropDeleteIo($this->evidencePath, dropAfterList: true);
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), $io);

        try {
            $fence->deletePath($this->storageRoot . '/bucket/object', 'bucket');
            self::fail('Lost evidence was accepted as a missing object.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(0, $io->unlinkCalls);
    }

    public function test_efs_abort_preserves_planned_parts_and_rejects_unknown_debris(): void
    {
        $uploadId = str_repeat('f', 32);
        $directory = $this->storageRoot . '/.parts/' . $uploadId;
        self::assertTrue(mkdir($directory, 0700, true));
        $legacy = $directory . '/1-' . str_repeat('a', 32);
        $planned = $directory . '/2-' . str_repeat('b', 32) . '-' . str_repeat('c', 32);
        file_put_contents($legacy, 'legacy');
        file_put_contents($planned, 'planned');
        $fence = new LocalEfsDeleteFence($this->storageRoot, $this->reader(), new EvidenceDropDeleteIo($this->evidencePath));

        $fence->abortMultipart($uploadId);
        self::assertFileDoesNotExist($legacy);
        self::assertSame('planned', file_get_contents($planned));
        file_put_contents($directory . '/unexpected', 'debris');
        self::expectException(\RuntimeException::class);
        $fence->abortMultipart($uploadId);
    }

    public function test_delete_tasks_cross_amp_channel_and_reject_missing_evidence_before_file_access(): void
    {
        $scope = $this->reader()->workerScope();
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'retained payload');
        unlink($this->evidencePath);
        $pool = new ContextWorkerPool(1);
        try {
            foreach ([
                new LocalEfsDeletePathTask($scope, $path, 'bucket'),
                new LocalEfsAbortMultipartTask($scope, str_repeat('f', 32)),
                new LocalEfsBucketNamespaceTask($scope, 'fresh-bucket', true),
            ] as $task) {
                $rejected = false;
                try {
                    $pool->submit($task)->getFuture()->await();
                } catch (\Throwable $error) {
                    $rejected = true;
                    self::assertStringNotContainsString('retained payload', $error->getMessage());
                }
                self::assertTrue($rejected, 'Worker accepted a task after EFS evidence disappeared.');
            }
            self::assertSame('retained payload', file_get_contents($path));
            self::assertDirectoryDoesNotExist($this->storageRoot . '/.parts');
            self::assertDirectoryDoesNotExist($this->storageRoot . '/fresh-bucket');
        } finally {
            $pool->shutdown();
        }
    }

    public function test_storage_garbage_claim_is_retained_when_evidence_disappears_after_delete(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', 'bucket/old-object');
        $backend = $this->createMock(StorageBackend::class);
        $backend->expects(self::once())->method('deleteObjectByPath')->willReturnCallback(function (): void {
            unlink($this->evidencePath);
        });
        $collector = new StorageGarbageCollector(
            $metadata,
            StorageTierRegistry::single($backend),
            efsEvidence: $this->reader(),
        );

        try {
            $collector->collect(1);
            self::fail('A physical delete was acknowledged without fresh host evidence.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $result = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $result);
        self::assertSame(['status' => 'processing', 'attempts' => 0], $result->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_storage_collectors_do_not_claim_sql_work_without_host_evidence(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'STANDARD');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', 'bucket/old-object');
        $attemptId = str_repeat('a', 32);
        $now = $metadata->multipartWriteIntentNow();
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'upload',
            bucket: 'bucket',
            bucketGeneration: $bucket->generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: 'test://assembly',
            finalPath: 'test://final',
            partDigest: str_repeat('b', 64),
            sseMode: null,
            leaseUntil: $now - 1,
            nextAttemptAt: $now - 1,
        ));
        $backend = $this->createMock(StorageBackend::class);
        $backend->expects(self::never())->method('deleteObjectByPath');
        $tiers = StorageTierRegistry::single($backend);
        $reader = $this->reader();
        unlink($this->evidencePath);

        foreach ([
            new StorageGarbageCollector($metadata, $tiers, efsEvidence: $reader),
            new MultipartWriteIntentCollector($metadata, $tiers, efsEvidence: $reader),
        ] as $collector) {
            try {
                $collector->collect(1);
                self::fail('Collector claimed SQL work without host evidence.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
        }
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $result = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $result);
        self::assertSame(['status' => 'pending', 'attempts' => 0], $result->fetch(\PDO::FETCH_ASSOC));
        $remaining = $metadata->getMultipartWriteIntent($attemptId);
        self::assertNotNull($remaining);
        self::assertSame('active', $remaining->state);
        self::assertSame(0, $remaining->claimGeneration);
    }

    public function test_restore_background_entry_rejects_missing_evidence_before_sql_work(): void
    {
        $reader = $this->reader();
        unlink($this->evidencePath);
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listExpiredRestoredObjects');
        $metadata->expects(self::never())->method('dequeueTierTransitionJobs');
        $metadata->expects(self::never())->method('dequeueRestoreJobs');
        $tiers = StorageTierRegistry::single(new InMemoryBackend());

        foreach ([
            static fn() => (new RestoreGarbageCollector($metadata, $tiers, efsEvidence: $reader))->collect(),
            static fn() => (new TierTransitionExecutor($metadata, $tiers, efsEvidence: $reader))->processNext(),
            static fn() => (new RestoreExecutor($metadata, $tiers, efsEvidence: $reader))->processNext(),
        ] as $run) {
            try {
                $run();
                self::fail('Background worker entered SQL after EFS evidence was lost.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
        }
    }

    public function test_restore_background_stops_processing_when_evidence_trips_after_dequeue(): void
    {
        foreach (['transition', 'restore'] as $kind) {
            $this->writeRecord($this->record());
            $checks = 0;
            $reader = $this->reader(function () use (&$checks): string {
                if (++$checks === 4) {
                    unlink($this->evidencePath);
                }
                return $this->mountInfo;
            });
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->putObjectMetadata('bucket', 'key', 'owner', 4, '"etag"', 'application/octet-stream', 'source-path');
            $jobId = $kind === 'transition'
                ? $metadata->enqueueTierTransitionJob('bucket', 'key', null, 'STANDARD', 'GLACIER', 'GLACIER', 'source-path')
                : $metadata->enqueueRestoreJob('bucket', 'key', null, 'STANDARD', 'source-path', 1);
            $backend = $this->createMock(StorageBackend::class);
            $backend->expects(self::never())->method('getObjectByPath');
            $backend->expects(self::never())->method('putObject');
            $tiers = StorageTierRegistry::single($backend);
            $executor = $kind === 'transition'
                ? new TierTransitionExecutor($metadata, $tiers, efsEvidence: $reader)
                : new RestoreExecutor($metadata, $tiers, efsEvidence: $reader);

            try {
                $executor->processNext();
                self::fail('Background worker processed a claim after EFS evidence was lost.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
            self::assertSame(4, $checks);
            $job = $kind === 'transition' ? $metadata->getTierTransitionJob($jobId) : $metadata->getRestoreJob($jobId);
            self::assertSame('processing', $job['status'] ?? null);
            self::assertSame(0, $job['attempts'] ?? null);
        }
    }

    public function test_restore_cleanup_stops_after_listing_when_evidence_trips(): void
    {
        $reader = $this->reader();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('listExpiredRestoredObjects')
            ->willReturnCallback(function (): array {
                unlink($this->evidencePath);
                return [];
            });
        $metadata->expects(self::never())->method('transaction');

        $this->expectExceptionMessage('EFS host evidence');
        (new RestoreGarbageCollector($metadata, StorageTierRegistry::single(new InMemoryBackend()), efsEvidence: $reader))->collect();
    }

    public function test_restore_cleanup_retains_copy_when_evidence_trips_before_transaction_or_inline_delete(): void
    {
        foreach ([5, 7] as $tripAt) {
            $this->writeRecord($this->record());
            $checks = 0;
            $reader = $this->reader(function () use (&$checks, $tripAt): string {
                if (++$checks === $tripAt) {
                    unlink($this->evidencePath);
                }
                return $this->mountInfo;
            });
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage = new InMemoryBackend();
            $storage->createBucket('bucket');
            $original = $storage->putObject('bucket', 'key', new ReadableBuffer('original'));
            $restored = $storage->putObject('bucket', 'key', new ReadableBuffer('restored'));
            $metadata->putObjectMetadata('bucket', 'key', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
            $metadata->updateObjectRestoreStateWithTier(
                'bucket',
                'key',
                null,
                'restored',
                $restored->path,
                new \DateTimeImmutable('2020-01-01T00:00:00Z'),
                'STANDARD',
            );

            try {
                (new RestoreGarbageCollector($metadata, $storage, efsEvidence: $reader))
                    ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));
                self::fail('Restore cleanup continued after EFS evidence was lost.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
            self::assertSame($tripAt, $checks);
            self::assertSame('restored', \Amp\ByteStream\buffer($storage->getObjectByPath($restored->path)));
            self::assertSame($tripAt === 5 ? $restored->path : null, $metadata->getObjectMetadata('bucket', 'key')?->restoredStoragePath);
        }
    }

    public function test_storage_garbage_claim_is_retained_when_evidence_trips_immediately_before_ack(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', 'bucket/old-object');
        $deleted = false;
        $postDeleteChecks = 0;
        $backend = $this->createMock(StorageBackend::class);
        $backend->expects(self::once())->method('deleteObjectByPath')->willReturnCallback(
            static function () use (&$deleted): void {
                $deleted = true;
            },
        );
        $reader = new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            function () use (&$deleted, &$postDeleteChecks): string {
                if ($deleted && ++$postDeleteChecks === 2) {
                    unlink($this->evidencePath);
                }
                return $this->mountInfo;
            },
        );
        $collector = new StorageGarbageCollector(
            $metadata,
            StorageTierRegistry::single($backend),
            efsEvidence: $reader,
        );

        try {
            $collector->collect(1);
            self::fail('A physical delete was acknowledged after the pre-ack watcher trip.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(2, $postDeleteChecks);
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $result = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $result);
        self::assertSame(['status' => 'processing', 'attempts' => 0], $result->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_multipart_claim_is_retained_when_evidence_disappears_after_real_amp_seals(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'STANDARD');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $attemptId = str_repeat('a', 32);
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem);
        $paths = $backend->planMultipartCompletionPaths('bucket', 'key', $bucket->generation, $attemptId);
        $now = $metadata->multipartWriteIntentNow();
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'upload',
            bucket: 'bucket',
            bucketGeneration: $bucket->generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: $paths['assembly'],
            finalPath: $paths['final'],
            partDigest: str_repeat('b', 64),
            sseMode: null,
            leaseUntil: $now + 30,
            nextAttemptAt: $now + 30,
        ));
        $created = $metadata->getMultipartWriteIntent($attemptId);
        self::assertNotNull($created);
        self::assertTrue($metadata->armMultipartWriteIntent($attemptId, str_repeat('c', 32), $created->leaseUntil, $now + 60));
        self::assertTrue($metadata->abandonMultipartWriteIntent($attemptId, $now - 1));
        $tripped = false;
        $postSealChecks = 0;
        $reader = new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            function () use (&$tripped, &$postSealChecks, $paths): string {
                if (is_file($paths['final'])
                    && str_starts_with((string) file_get_contents($paths['final']), 'OPSFOUR-LOCAL-MULTIPART-SEAL-V1')) {
                    $postSealChecks++;
                    if ($postSealChecks === 2) {
                        unlink($this->evidencePath);
                        $tripped = true;
                    }
                }
                return $this->mountInfo;
            },
        );
        $collector = new MultipartWriteIntentCollector(
            $metadata,
            StorageTierRegistry::single($backend),
            efsEvidence: $reader,
        );

        try {
            $collector->collect(1);
            self::fail('A sealed completion claim was parked without fresh host evidence.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        } finally {
            $backend->shutdown();
        }
        self::assertTrue($tripped, 'The fault was not injected after both physical seals.');
        self::assertSame(2, $postSealChecks);
        $remaining = $metadata->getMultipartWriteIntent($attemptId);
        self::assertNotNull($remaining);
        self::assertSame('abandoned', $remaining->state);
        self::assertSame(1, $remaining->claimGeneration);
        self::assertLessThan(1.0e300, $remaining->nextAttemptAt);
    }

    public function test_efs_temp_cleanup_keeps_locked_file_then_removes_stale_file(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . bin2hex(random_bytes(16));
        $lease = StorageTempFileLease::acquire($path);
        self::assertTrue(touch($path, time() - 7200));
        $cleanup = new LocalEfsTempCleanup($this->storageRoot, $this->reader());

        try {
            self::assertSame(0, $cleanup->run(3600));
            self::assertFileExists($path);
        } finally {
            $lease->release();
        }

        self::assertSame(1, $cleanup->run(3600));
        self::assertFileDoesNotExist($path);
    }

    public function test_efs_temp_cleanup_keeps_active_object_writer_stage_then_reclaims_it_after_worker_crash(): void
    {
        if (! function_exists('proc_open') || ! function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('a', 32) . '-' . str_repeat('b', 32);
        $ready = $this->base . '/object-stage-ready';
        $code = <<<'PHP'
            $file = fopen($argv[1], 'x+b');
            if ($file === false || !chmod($argv[1], 0600) || !flock($file, LOCK_SH | LOCK_NB)
                || file_put_contents($argv[2], 'ready') !== 5) {
                exit(1);
            }
            sleep(60);
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $path, $ready],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($ready, 'The Object writer worker did not acquire its stage lease.');
            self::assertTrue(touch($path, time() - 7200));
            $cleanup = new LocalEfsTempCleanup($this->storageRoot, $this->reader());
            self::assertSame(0, $cleanup->run(3600));
            self::assertFileExists($path);

            self::assertTrue(proc_terminate($process, 9));
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);
            self::assertSame(1, $cleanup->run(3600));
            self::assertFileDoesNotExist($path);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            @unlink($ready);
        }
    }

    public function test_efs_temp_cleanup_rejects_unknown_object_writer_stage_name(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('a', 32) . '-' . str_repeat('b', 32) . '-extra';
        self::assertSame(7, file_put_contents($path, 'payload'));
        self::assertTrue(chmod($path, 0600));

        try {
            (new LocalEfsTempCleanup($this->storageRoot, $this->reader()))->run(0);
            self::fail('An unknown EFS stage name was silently removed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Unexpected EFS temporary path', $error->getMessage());
        }
        self::assertFileExists($path);
    }

    public function test_efs_temp_cleanup_rejects_non_private_object_writer_stage(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('a', 32) . '-' . str_repeat('b', 32);
        self::assertSame(7, file_put_contents($path, 'payload'));
        self::assertTrue(chmod($path, 0644));

        try {
            (new LocalEfsTempCleanup($this->storageRoot, $this->reader()))->run(0);
            self::fail('An EFS stage with broad permissions was silently removed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private and process-owned', $error->getMessage());
        }
        self::assertFileExists($path);
    }

    public function test_efs_temp_cleanup_fails_closed_for_symlink_and_lost_evidence(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('f', 32);
        self::assertSame(7, file_put_contents($path, 'payload'));
        self::assertTrue(chmod($path, 0600));
        self::assertTrue(touch($path, time() - 7200));
        $link = $this->storageRoot . '/.tmp/' . str_repeat('0', 32);
        self::assertTrue(symlink($path, $link));
        try {
            (new LocalEfsTempCleanup($this->storageRoot, $this->reader()))->run(3600);
            self::fail('The symlink was accepted for EFS cleanup.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not a regular file', $error->getMessage());
        }
        self::assertFileExists($path);
        unlink($link);

        $reader = $this->reader();
        unlink($this->evidencePath);
        try {
            (new LocalEfsTempCleanup($this->storageRoot, $reader))->run(3600);
            self::fail('The cleanup accepted a lost host heartbeat.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertFileExists($path);
    }

    public function test_efs_temp_cleanup_rechecks_evidence_after_lock_before_unlink(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('a', 32);
        self::assertSame(7, file_put_contents($path, 'payload'));
        self::assertTrue(chmod($path, 0600));
        self::assertTrue(touch($path, time() - 7200));
        $checks = 0;
        $reader = new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            function () use (&$checks): string {
                if (++$checks === 8) {
                    unlink($this->evidencePath);
                }
                return $this->mountInfo;
            },
        );
        $checks = 0;

        try {
            (new LocalEfsTempCleanup($this->storageRoot, $reader))->run(3600);
            self::fail('Cleanup continued after the host heartbeat disappeared.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(8, $checks);
        self::assertFileExists($path);
    }

    public function test_guarded_backend_temp_cleanup_uses_worker_and_rechecks_acknowledgement(): void
    {
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsTempCleanupTask::class, $task);
            unlink($this->evidencePath);
            $completion->complete(1);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->cleanupStaleTempFiles();
            self::fail('The backend accepted stale evidence after temp-cleanup acknowledgement.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_efs_temp_size_fence_checks_exact_regular_file_and_size(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/.tmp', 0700));
        $path = $this->storageRoot . '/.tmp/' . str_repeat('a', 32);
        self::assertSame(7, file_put_contents($path, 'payload'));
        $fence = new LocalEfsTempSizeFence($this->storageRoot, $this->reader());
        $fence->assertSize($path, 7);

        try {
            $fence->assertSize($path, 8);
            self::fail('The incomplete staging file was accepted.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('incomplete', $error->getMessage());
        }
        $link = $this->storageRoot . '/.tmp/' . str_repeat('b', 32);
        self::assertTrue(symlink($path, $link));
        try {
            $fence->assertSize($link, 7);
            self::fail('The staging symlink was accepted.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('incomplete', $error->getMessage());
        }
    }

    public function test_efs_temp_size_fence_rejects_redirected_temp_directory(): void
    {
        $other = $this->storageRoot . '/other';
        self::assertTrue(mkdir($other, 0700));
        $path = $other . '/' . str_repeat('a', 32);
        self::assertSame(7, file_put_contents($path, 'payload'));
        self::assertTrue(symlink($other, $this->storageRoot . '/.tmp'));

        try {
            (new LocalEfsTempSizeFence($this->storageRoot, $this->reader()))
                ->assertSize($this->storageRoot . '/.tmp/' . str_repeat('a', 32), 7);
            self::fail('The redirected staging directory was accepted.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('unsafe', $error->getMessage());
        }
        self::assertFileExists($path);
    }

    public function test_guarded_backend_rejects_size_worker_failure_before_publish(): void
    {
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsTempSizeTask::class, $task);
            $completion->error(new InternalErrorException('Filesystem staging file is incomplete.'));
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->putObject('bucket', 'key', new ReadableBuffer('payload'));
            self::fail('The backend published after the size worker failed.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to write object', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertDirectoryDoesNotExist($this->storageRoot . '/bucket');
        self::assertSame([], glob($this->storageRoot . '/.tmp/*') ?: []);
    }

    public function test_guarded_backend_rechecks_evidence_after_size_worker_acknowledgement(): void
    {
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsTempSizeTask::class, $task);
            unlink($this->evidencePath);
            $completion->complete(null);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->putObject('bucket', 'key', new ReadableBuffer('payload'));
            self::fail('The backend published after its size evidence was lost.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to write object', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertDirectoryDoesNotExist($this->storageRoot . '/bucket');
    }

    public function test_guarded_multipart_part_size_worker_failure_does_not_publish_part(): void
    {
        $uploadId = str_repeat('d', 32);
        $partPath = StoragePath::forPlannedPart(
            $this->storageRoot,
            'bucket',
            str_repeat('a', 32),
            $uploadId,
            1,
            str_repeat('f', 32),
        );
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend(
            $this->storageRoot,
            $filesystem,
            $filesystem,
            $this->reader(),
            $this->failingTempSizePool(),
        );

        try {
            $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('part-data'), $partPath);
            self::fail('Multipart part returned after its staging size worker failed.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to write multipart part', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertFileDoesNotExist($partPath);
        self::assertSame([], glob($this->storageRoot . '/.tmp/*') ?: []);
    }

    public function test_guarded_multipart_assembly_lost_size_evidence_does_not_publish(): void
    {
        $uploadId = str_repeat('d', 32);
        $partPath = StoragePath::forPart($this->storageRoot, $uploadId, 1);
        mkdir(dirname($partPath), 0700, true);
        file_put_contents($partPath, 'part-data');
        $paths = StoragePath::forMultipartCompletion(
            $this->storageRoot,
            'bucket',
            'key',
            str_repeat('a', 32),
            str_repeat('f', 32),
        );
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend(
            $this->storageRoot,
            $filesystem,
            $filesystem,
            $this->reader(),
            $this->failingTempSizePool(loseEvidence: true),
        );

        try {
            $backend->assembleMultipartUploadAtPath('bucket', 'key', $uploadId, [[
                'partNumber' => 1,
                'etag' => md5('part-data'),
                'storagePath' => $partPath,
            ]], $paths['assembly']);
            self::fail('Multipart assembly returned after staging evidence was lost.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to assemble multipart upload', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertFileDoesNotExist($paths['assembly']);
        self::assertFileDoesNotExist($paths['final']);
        self::assertSame('part-data', file_get_contents($partPath));
    }

    public function test_guarded_copy_size_worker_failure_does_not_publish_destination(): void
    {
        $sourcePath = $this->storedSource('source-data');
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend(
            $this->storageRoot,
            $filesystem,
            $filesystem,
            $this->reader(),
            $this->failingTempSizePool(),
        );

        try {
            $backend->copyObject($sourcePath, 'bucket', 'copy');
            self::fail('Copy returned after its staging size worker failed.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to copy object', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertDirectoryDoesNotExist(StoragePath::directoryForObject($this->storageRoot, 'bucket', 'copy'));
        self::assertSame('source-data', file_get_contents($sourcePath));
        self::assertSame([], glob($this->storageRoot . '/.tmp/*') ?: []);
    }

    public function test_guarded_bounded_copy_size_worker_failure_does_not_publish_destination(): void
    {
        // CopyObjectHandler uses this bounded path, which stages via putObject().
        $sourcePath = $this->storedSource('source-data');
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend(
            $this->storageRoot,
            $filesystem,
            $filesystem,
            $this->reader(),
            $this->failingTempSizePool(),
        );

        try {
            $backend->copyObjectBounded($sourcePath, 'bucket', 'copy', 11);
            self::fail('Bounded copy returned after its staging size worker failed.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('Failed to write object', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
        self::assertDirectoryDoesNotExist(StoragePath::directoryForObject($this->storageRoot, 'bucket', 'copy'));
        self::assertSame('source-data', file_get_contents($sourcePath));
        self::assertSame([], glob($this->storageRoot . '/.tmp/*') ?: []);
    }

    private function failingTempSizePool(bool $loseEvidence = false): WorkerPool
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::atLeastOnce())->method('submit')->willReturnCallback(function ($task) use ($loseEvidence): Execution {
            $completion = new DeferredFuture();
            if ($task instanceof LocalEfsStoredPathCheckTask) {
                $completion->complete(true);
            } else {
                self::assertInstanceOf(LocalEfsTempSizeTask::class, $task);
                if ($loseEvidence) {
                    unlink($this->evidencePath);
                    $completion->complete(null);
                } else {
                    $completion->error(new InternalErrorException('Filesystem staging file is incomplete.'));
                }
            }
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        return $pool;
    }

    private function successfulPathCheckPool(): WorkerPool
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(function ($task): Execution {
            self::assertInstanceOf(LocalEfsStoredPathCheckTask::class, $task);
            $completion = new DeferredFuture();
            $completion->complete(true);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        return $pool;
    }

    public function test_efs_stored_path_check_fails_closed_for_missing_path_escape_and_dangling_link(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $existing = $this->storageRoot . '/bucket/object';
        self::assertSame(7, file_put_contents($existing, 'payload'));
        $check = new LocalEfsStoredPathCheck($this->storageRoot, $this->reader());
        self::assertTrue($check->isConfined($existing));
        try {
            $check->isConfined($this->storageRoot . '/bucket/missing');
            self::fail('A missing EFS path was treated as safely absent.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('cannot be resolved', $error->getMessage());
        }
        self::assertInstanceOf(LocalEfsStoredPathCheckTask::class, unserialize(serialize(
            new LocalEfsStoredPathCheckTask($this->reader()->workerScope(), $existing),
        )));

        $outside = $this->base . '/outside';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        $link = $this->storageRoot . '/bucket/link';
        self::assertTrue(symlink($outside, $link));
        try {
            $check->isConfined($link);
            self::fail('An EFS stored path escaped its storage root.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('escapes', $error->getMessage());
        } finally {
            unlink($outside);
        }

        try {
            $check->isConfined($link);
            self::fail('A dangling EFS stored-path link was accepted as absent.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('cannot be resolved', $error->getMessage());
        }
    }

    public function test_efs_missing_stored_path_fails_closed_in_large_parent_directory(): void
    {
        $directory = $this->storageRoot . '/bucket';
        self::assertTrue(mkdir($directory, 0700));
        for ($i = 0; $i < 4097; $i++) {
            if (! touch($directory . '/' . sprintf('%04d', $i))) {
                self::fail('Could not create the large EFS parent-directory fixture.');
            }
        }

        try {
            (new LocalEfsStoredPathCheck($this->storageRoot, $this->reader()))
                ->isConfined($directory . '/missing');
            self::fail('A missing EFS path was accepted after a large directory scan.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('cannot be resolved', $error->getMessage());
        }
    }

    public function test_guarded_backend_path_worker_failure_prevents_physical_read(): void
    {
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsStoredPathCheckTask::class, $task);
            $completion->error(new \RuntimeException('Injected worker failure.'));
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->getObjectByPath($this->storageRoot . '/bucket/object');
            self::fail('Backend opened a stored path after the worker failed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected worker failure.', $error->getMessage());
        } finally {
            $backend->shutdown();
        }
    }

    public function test_guarded_backend_path_check_rechecks_evidence_after_worker_acknowledgement(): void
    {
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsStoredPathCheckTask::class, $task);
            unlink($this->evidencePath);
            $completion->complete(true);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->getObjectByPath($this->storageRoot . '/bucket/object');
            self::fail('Backend opened a stored path after the host heartbeat disappeared.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_guarded_backend_delete_rechecks_evidence_after_worker_acknowledgement(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'payload');
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion, $path): Execution {
            self::assertInstanceOf(LocalEfsDeletePathTask::class, $task);
            (new LocalEfsDeleteFence($this->storageRoot, $this->reader()))->deletePath($path, 'bucket');
            unlink($this->evidencePath);
            $completion->complete(null);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->deleteObjectByPath($path, 'bucket');
            self::fail('Backend accepted worker success after host evidence was lost.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertFileDoesNotExist($path);
    }

    public function test_guarded_backend_abort_rejects_ambiguous_worker_result(): void
    {
        $uploadId = str_repeat('f', 32);
        $partDirectory = $this->storageRoot . '/.parts/' . $uploadId;
        self::assertTrue(mkdir($partDirectory, 0700, true));
        $part = $partDirectory . '/1-' . str_repeat('a', 32);
        file_put_contents($part, 'part payload');
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsAbortMultipartTask::class, $task);
            $completion->error(new \RuntimeException('ambiguous worker result'));
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        $this->expectExceptionMessage('ambiguous worker result');
        try {
            $backend->abortMultipartUpload('bucket', 'key', $uploadId);
        } finally {
            self::assertSame('part payload', file_get_contents($part));
        }
    }

    public function test_guarded_backend_delete_rejects_ambiguous_worker_result(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'retained payload');
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion): Execution {
            self::assertInstanceOf(LocalEfsDeletePathTask::class, $task);
            $completion->error(new \RuntimeException('ambiguous delete result'));
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        $this->expectExceptionMessage('ambiguous delete result');
        try {
            $backend->deleteObjectByPath($path, 'bucket');
        } finally {
            self::assertSame('retained payload', file_get_contents($path));
        }
    }

    public function test_guarded_backend_abort_rechecks_evidence_after_worker_acknowledgement(): void
    {
        $uploadId = str_repeat('f', 32);
        $partDirectory = $this->storageRoot . '/.parts/' . $uploadId;
        self::assertTrue(mkdir($partDirectory, 0700, true));
        $part = $partDirectory . '/1-' . str_repeat('a', 32);
        file_put_contents($part, 'part payload');
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion, $uploadId): Execution {
            self::assertInstanceOf(LocalEfsAbortMultipartTask::class, $task);
            (new LocalEfsDeleteFence($this->storageRoot, $this->reader()))->abortMultipart($uploadId);
            unlink($this->evidencePath);
            $completion->complete(null);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        try {
            $backend->abortMultipartUpload('bucket', 'key', $uploadId);
            self::fail('Backend accepted abort success after host evidence was lost.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertFileDoesNotExist($part);
    }

    public function test_inline_delete_keeps_queued_path_when_evidence_trips_after_backend_success(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'old payload');
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $path);
        $postDeleteChecks = 0;
        $reader = new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            function () use ($path, &$postDeleteChecks): string {
                if (! is_file($path) && ++$postDeleteChecks === 2) {
                    unlink($this->evidencePath);
                }
                return $this->mountInfo;
            },
        );
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion, $path): Execution {
            self::assertInstanceOf(LocalEfsDeletePathTask::class, $task);
            (new LocalEfsDeleteFence($this->storageRoot, $this->reader()))->deletePath($path, 'bucket');
            $completion->complete(null);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $reader, $pool);
        $observed = new ObservedStorageBackend($backend, new MetricsCollector(), 'filesystem');

        try {
            StorageGarbageDiscard::afterDelete($metadata, $observed, 'bucket', 'STANDARD', $path);
            self::fail('Inline cleanup discarded its queued path after an EFS watcher trip.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
        self::assertSame(2, $postDeleteChecks);
        self::assertFileDoesNotExist($path);
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $result = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $result);
        self::assertSame(['status' => 'pending', 'attempts' => 0], $result->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_inline_delete_discards_queued_path_with_fresh_guarded_backend(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'old payload');
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $path);
        $completion = new DeferredFuture();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($completion, $path): Execution {
            self::assertInstanceOf(LocalEfsDeletePathTask::class, $task);
            (new LocalEfsDeleteFence($this->storageRoot, $this->reader()))->deletePath($path, 'bucket');
            $completion->complete(null);
            return new Execution($task, $this->createMock(Channel::class), $completion->getFuture());
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        StorageGarbageDiscard::afterDelete($metadata, $backend, 'bucket', 'STANDARD', $path);
        self::assertFileDoesNotExist($path);
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_guarded_backend_delete_rejects_evidence_loss_in_real_amp_worker(): void
    {
        self::assertTrue(mkdir($this->storageRoot . '/bucket', 0700));
        $path = $this->storageRoot . '/bucket/object';
        file_put_contents($path, 'retained payload');
        $realPool = new ContextWorkerPool(1);
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(function ($task) use ($realPool): Execution {
            self::assertInstanceOf(LocalEfsDeletePathTask::class, $task);
            unlink($this->evidencePath);
            return $realPool->submit($task);
        });
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $pool);

        $rejected = false;
        try {
            $backend->deleteObjectByPath($path, 'bucket');
        } catch (\Throwable $error) {
            $rejected = true;
            self::assertStringNotContainsString('retained payload', $error->getMessage());
            self::assertSame('retained payload', file_get_contents($path));
        } finally {
            $realPool->shutdown();
        }
        self::assertTrue($rejected, 'Real Amp worker accepted a delete after evidence disappeared.');
    }

    public function test_filesystem_read_rechecks_evidence_between_lazy_chunks(): void
    {
        $firstChunk = str_repeat('a', 1_048_576);
        $path = $this->storedSource($firstChunk . 'b');
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $this->successfulPathCheckPool());
        $stream = $backend->getObjectByPath($path);
        self::assertInstanceOf(EfsGuardedReadableStream::class, $stream);
        self::assertSame($firstChunk, $stream->read());

        unlink($this->evidencePath);
        try {
            $stream->read();
            self::fail('A deferred EFS read continued after host evidence loss.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS read evidence is unavailable.', $error->getMessage());
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_ranged_filesystem_read_rejects_trip_before_first_chunk(): void
    {
        $path = $this->storedSource('ranged-source');
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $backend = new FilesystemBackend($this->storageRoot, $filesystem, $filesystem, $this->reader(), $this->successfulPathCheckPool());
        $stream = $backend->getObjectByPath($path, 1, 5);
        unlink($this->evidencePath);

        try {
            $stream->read();
            self::fail('A ranged EFS read escaped after host evidence loss.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS read evidence is unavailable.', $error->getMessage());
        }
    }

    public function test_ranged_read_rejects_evidence_loss_during_open_before_seek(): void
    {
        $path = $this->storedSource('ranged-source');
        $file = $this->createMock(File::class);
        $file->expects(self::never())->method('seek');
        $file->expects(self::never())->method('close');
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(function () use ($file): File {
            unlink($this->evidencePath);
            return $file;
        });
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem(new BlockingFilesystemDriver()),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );

        try {
            $backend->getObjectByPath($path, 1, 5);
            self::fail('A range seek continued after host loss during open.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
    }

    public function test_ranged_read_rejects_evidence_loss_during_seek_before_stream_return(): void
    {
        $path = $this->storedSource('ranged-source');
        $file = $this->createMock(File::class);
        $file->method('seek')->willReturnCallback(function (): int {
            unlink($this->evidencePath);
            return 1;
        });
        $file->expects(self::never())->method('close');
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturn($file);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem(new BlockingFilesystemDriver()),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );

        try {
            $backend->getObjectByPath($path, 1, 5);
            self::fail('A ranged stream escaped after host loss during seek.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
    }

    public function test_filesystem_read_discards_a_chunk_completed_after_host_trip(): void
    {
        $path = $this->storedSource('source');
        $reads = 0;
        $file = $this->createMock(File::class);
        $file->method('read')->willReturnCallback(function () use (&$reads): string {
            $reads++;
            unlink($this->evidencePath);
            return 'must-not-escape';
        });
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturn($file);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem(new BlockingFilesystemDriver()),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );
        $stream = $backend->getObjectByPath($path);

        try {
            $stream->read();
            self::fail('A delayed source chunk escaped after the watcher trip.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS read evidence is unavailable.', $error->getMessage());
            self::assertSame(1, $reads);
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_direct_copy_does_not_publish_after_source_read_loses_evidence(): void
    {
        $this->assertCopyReadTripDoesNotPublish(false);
    }

    public function test_bounded_copy_does_not_publish_after_source_read_loses_evidence(): void
    {
        $this->assertCopyReadTripDoesNotPublish(true);
    }

    public function test_multipart_part_read_trip_between_chunks_retains_armed_sql_intent(): void
    {
        $databasePath = $this->storageRoot . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = str_repeat('d', 32);
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $firstChunk = str_repeat('a', 1_048_576);
        $payload = $firstChunk . 'b';
        $partPath = StoragePath::forPart($this->storageRoot, $uploadId, 1);
        mkdir(dirname($partPath), 0700, true);
        file_put_contents($partPath, $payload);
        $etag = md5($payload);
        $metadata->putPart($uploadId, 1, $etag, strlen($payload), $partPath);

        $reads = 0;
        $partFile = $this->createMock(File::class);
        $partFile->method('read')->willReturnCallback(function () use (&$reads, $firstChunk): string {
            $reads++;
            if ($reads === 1) {
                return $firstChunk;
            }
            unlink($this->evidencePath);
            return 'b';
        });
        $blocking = new BlockingFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(static function (string $path, string $mode) use ($partPath, $partFile, $blocking): File {
            return $path === $partPath && $mode === 'r' ? $partFile : $blocking->openFile($path, $mode);
        });
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem($blocking),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );

        try {
            (new CompleteMultipartUploadHandler(
                $metadata,
                $backend,
                // This injected Amp driver exercises the legacy guarded read
                // path. The strict profile sends assembly to a process worker
                // whose real Linux mountinfo is unavailable on macOS.
                requireLocalFencedCompletion: false,
            ))->handleRequest($this->completionRequest($uploadId, $etag));
            self::fail('A delayed part chunk completed after EFS host evidence loss.');
        } catch (InternalErrorException) {
            self::assertSame(2, $reads);
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
        }

        $pdo = new \PDO('sqlite:' . $databasePath);
        $row = $pdo->query('SELECT state, dispatch_id, assembly_path, final_path FROM s3_multipart_write_intents LIMIT 1');
        self::assertInstanceOf(\PDOStatement::class, $row);
        $intent = $row->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($intent);
        self::assertSame('abandoned', $intent['state']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $intent['dispatch_id']);
        self::assertFileDoesNotExist($intent['assembly_path']);
        self::assertFileDoesNotExist($intent['final_path']);
        self::assertFileExists($partPath);
    }

    public function test_assembly_worker_io_loses_host_evidence_before_publication(): void
    {
        $uploadId = str_repeat('d', 32);
        $partPath = StoragePath::forPart($this->storageRoot, $uploadId, 1);
        self::assertTrue(mkdir(dirname($partPath), 0700, true));
        self::assertSame(5, file_put_contents($partPath, 'first'));
        self::assertTrue(chmod($partPath, 0600));
        $target = StoragePath::forMultipartCompletion(
            $this->storageRoot,
            'bucket',
            'key',
            str_repeat('a', 32),
            str_repeat('f', 32),
        )['assembly'];
        $io = new class ($this->evidencePath) implements LocalObjectWriteIo {
            private readonly NativeLocalObjectWriteIo $inner;

            public function __construct(private readonly string $evidencePath)
            {
                $this->inner = new NativeLocalObjectWriteIo();
            }

            public function syncFile($file): bool
            {
                $synced = $this->inner->syncFile($file);
                unlink($this->evidencePath);

                return $synced;
            }

            public function syncDirectory(string $path): bool
            {
                return $this->inner->syncDirectory($path);
            }

            public function link(string $source, string $target): bool
            {
                return $this->inner->link($source, $target);
            }

            public function rename(string $source, string $target): bool
            {
                return $this->inner->rename($source, $target);
            }
        };

        try {
            (new LocalMultipartAssemblyFence($this->storageRoot, $io, $this->reader()))->assemble(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('f', 32),
                $uploadId,
                [[
                    'partNumber' => 1,
                    'etag' => md5('first'),
                    'size' => 5,
                    'storagePath' => $partPath,
                ]],
                $target,
            );
            self::fail('Assembly published after the host evidence disappeared.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertFileDoesNotExist($target);
            self::assertSame('first', file_get_contents($partPath));
        }
    }

    public function test_guarded_efs_encrypted_completion_rejects_before_arming_intent(): void
    {
        $databasePath = $this->storageRoot . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = str_repeat('d', 32);
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner', userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__mpu-part-format' => \OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec::FORMAT,
        ]);
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader());
        try {
            (new CompleteMultipartUploadHandler($metadata, $backend, new \OpsFour\S3Server\Encryption\EncryptionService(), requireLocalFencedCompletion: true))
                ->handleRequest($this->completionRequest($uploadId, md5('unused')));
            self::fail('Guarded EFS SSE completion entered its API-fiber read path.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('worker-compatible Config keyring', $error->getMessage());
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            $query = (new \PDO('sqlite:' . $databasePath))->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());
            self::assertDirectoryDoesNotExist($this->storageRoot . '/.assemblies');
        } finally {
            $backend->shutdown();
        }
    }

    public function test_guarded_sse_s3_rejects_incompatible_injected_keyring_before_intent(): void
    {
        $databasePath = $this->storageRoot . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = str_repeat('d', 32);
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner', userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__mpu-part-format' => \OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec::FORMAT,
        ]);
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader());
        $oldKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        $oldKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        try {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
            putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
            $incompatible = new \OpsFour\S3Server\Encryption\EncryptionService(
                new \OpsFour\S3Server\Encryption\ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
            );
            try {
                (new CompleteMultipartUploadHandler($metadata, $backend, $incompatible, requireLocalFencedCompletion: true))
                    ->handleRequest($this->completionRequest($uploadId, md5('unused')));
                self::fail('An injected keyring different from the worker environment was admitted.');
            } catch (InternalErrorException $error) {
                self::assertStringContainsString('worker-compatible Config keyring', $error->getMessage());
                self::assertNotNull($metadata->getMultipartUpload($uploadId));
                $query = (new \PDO('sqlite:' . $databasePath))->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
                self::assertInstanceOf(\PDOStatement::class, $query);
                self::assertSame(0, (int) $query->fetchColumn());
                self::assertDirectoryDoesNotExist($this->storageRoot . '/.assemblies');
            }
        } finally {
            putenv($oldKey === false ? 'S3_ENCRYPTION_MASTER_KEY' : 'S3_ENCRYPTION_MASTER_KEY=' . $oldKey);
            putenv($oldKeys === false ? 'S3_ENCRYPTION_MASTER_KEYS' : 'S3_ENCRYPTION_MASTER_KEYS=' . $oldKeys);
            $backend->shutdown();
        }
    }

    public function test_guarded_sse_s3_worker_failure_retains_armed_intent_and_ciphertext_part(): void
    {
        $databasePath = $this->storageRoot . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = str_repeat('d', 32);
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner', userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__mpu-part-format' => \OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec::FORMAT,
        ]);
        $oldKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        $oldKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader());
        try {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
            putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
            $encryption = new \OpsFour\S3Server\Encryption\EncryptionService(new \OpsFour\S3Server\Encryption\ConfigMasterKeyProvider());
            $plaintext = 'sensitive encrypted part';
            $encoded = \OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec::encode(
                $encryption,
                'AES256',
                $plaintext,
                null,
                $bucket->generation,
                'bucket',
                $uploadId,
                1,
            );
            $partPath = StoragePath::forPart($this->storageRoot, $uploadId, 1);
            self::assertTrue(mkdir(dirname($partPath), 0700, true));
            self::assertSame(strlen($encoded), file_put_contents($partPath, $encoded));
            self::assertTrue(chmod($partPath, 0600));
            $metadata->putPart($uploadId, 1, md5($plaintext), strlen($plaintext), $partPath);

            try {
                (new CompleteMultipartUploadHandler($metadata, $backend, $encryption, requireLocalFencedCompletion: true))
                    ->handleRequest($this->completionRequest($uploadId, md5($plaintext)));
                self::fail('A worker without native host evidence completed the upload.');
            } catch (\Amp\Parallel\Worker\TaskFailureException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
                self::assertNotNull($metadata->getMultipartUpload($uploadId));
                self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
                self::assertSame($encoded, file_get_contents($partPath));
                $query = (new \PDO('sqlite:' . $databasePath))->query(
                    'SELECT state, dispatch_id, assembly_path, final_path FROM s3_multipart_write_intents LIMIT 1',
                );
                self::assertInstanceOf(\PDOStatement::class, $query);
                $intent = $query->fetch(\PDO::FETCH_ASSOC);
                self::assertIsArray($intent);
                self::assertSame('abandoned', $intent['state']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $intent['dispatch_id']);
                self::assertFileDoesNotExist($intent['assembly_path']);
                self::assertFileDoesNotExist($intent['final_path']);
            }
        } finally {
            putenv($oldKey === false ? 'S3_ENCRYPTION_MASTER_KEY' : 'S3_ENCRYPTION_MASTER_KEY=' . $oldKey);
            putenv($oldKeys === false ? 'S3_ENCRYPTION_MASTER_KEYS' : 'S3_ENCRYPTION_MASTER_KEYS=' . $oldKeys);
            $backend->shutdown();
        }
    }

    public function test_encrypted_completion_rejects_wrong_final_path_and_keyring_before_worker_admission(): void
    {
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader());
        $generation = str_repeat('a', 32);
        $attemptId = str_repeat('b', 32);
        $planned = StoragePath::forMultipartCompletion($this->storageRoot, 'bucket', 'key', $generation, $attemptId);
        try {
            foreach ([
                [$planned['assembly'], str_repeat('c', 64)],
                [$planned['final'], 'wrong-fingerprint'],
            ] as [$path, $fingerprint]) {
                try {
                    $backend->completeEncryptedMultipartUploadFencedAtPath(
                        'bucket',
                        'key',
                        $generation,
                        $attemptId,
                        str_repeat('d', 32),
                        [],
                        $path,
                        'AES256',
                        1024,
                        null,
                        $fingerprint,
                    );
                    self::fail('Invalid encrypted completion reached its worker.');
                } catch (\InvalidArgumentException) {
                    self::assertNull((new \ReflectionProperty($backend, 'multipartFencePool'))->getValue($backend));
                    self::assertSame(0, (new \ReflectionProperty($backend, 'multipartFenceAdmissions'))->getValue($backend));
                    self::assertDirectoryDoesNotExist($this->storageRoot . '/.assemblies');
                }
            }
        } finally {
            $backend->shutdown();
        }
    }

    public function test_multipart_assembly_rejects_trip_during_second_part_open(): void
    {
        $uploadId = str_repeat('d', 32);
        $firstPart = StoragePath::forPart($this->storageRoot, $uploadId, 1);
        $secondPart = StoragePath::forPart($this->storageRoot, $uploadId, 2);
        mkdir(dirname($firstPart), 0700, true);
        file_put_contents($firstPart, 'first');
        file_put_contents($secondPart, 'second');
        $paths = StoragePath::forMultipartCompletion(
            $this->storageRoot,
            'bucket',
            'key',
            str_repeat('a', 32),
            str_repeat('f', 32),
        );
        $firstOpened = 0;
        $secondOpened = 0;
        $secondFile = $this->createMock(File::class);
        $secondFile->expects(self::never())->method('read');
        $blocking = new BlockingFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(function (string $path, string $mode) use (
            $firstPart,
            $secondPart,
            &$firstOpened,
            &$secondOpened,
            $secondFile,
            $blocking,
        ): File {
            if ($path === $firstPart && $mode === 'r') {
                $firstOpened++;
            }
            if ($path === $secondPart && $mode === 'r') {
                $secondOpened++;
                unlink($this->evidencePath);
                return $secondFile;
            }
            return $blocking->openFile($path, $mode);
        });
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem($blocking),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );

        try {
            $backend->assembleMultipartUploadAtPath('bucket', 'key', $uploadId, [
                ['partNumber' => 1, 'etag' => md5('first'), 'storagePath' => $firstPart],
                ['partNumber' => 2, 'etag' => md5('second'), 'storagePath' => $secondPart],
            ], $paths['assembly']);
            self::fail('Multipart assembly continued after loss between parts.');
        } catch (InternalErrorException) {
            self::assertSame(1, $firstOpened);
            self::assertSame(1, $secondOpened);
            self::assertFileDoesNotExist($paths['assembly']);
            self::assertFileExists($firstPart);
            self::assertFileExists($secondPart);
        }
    }

    private function completionRequest(string $uploadId, string $etag): Request
    {
        $request = new Request(
            new class implements Client {
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
            },
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploadId=' . $uploadId),
            [],
            '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
                . $etag . '</ETag></Part></CompleteMultipartUpload>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::CompleteMultipartUpload);
        return $request;
    }

    private function assertCopyReadTripDoesNotPublish(bool $bounded): void
    {
        $path = $this->storedSource('source');
        $reads = 0;
        $file = $this->createMock(File::class);
        $file->method('read')->willReturnCallback(function () use (&$reads): string {
            $reads++;
            unlink($this->evidencePath);
            return 'must-not-publish';
        });
        $blocking = new BlockingFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(static function (string $openedPath, string $mode) use ($path, $file, $blocking): File {
            return $mode === 'r' && $openedPath === $path ? $file : $blocking->openFile($openedPath, $mode);
        });
        $backend = new FilesystemBackend(
            $this->storageRoot,
            new Filesystem($driver),
            new Filesystem($blocking),
            $this->reader(),
            $this->successfulPathCheckPool(),
        );

        try {
            if ($bounded) {
                $backend->copyObjectBounded($path, 'bucket', 'copy', 100);
            } else {
                $backend->copyObject($path, 'bucket', 'copy');
            }
            self::fail('Copy published bytes after its source evidence was lost.');
        } catch (InternalErrorException $error) {
            self::assertSame(1, $reads);
            self::assertStringContainsString('filesystem backend', $error->getMessage());
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $this->storageRoot . '/bucket',
            \FilesystemIterator::SKIP_DOTS,
        ));
        $paths = [];
        foreach ($files as $entry) {
            if ($entry->isFile()) {
                $paths[] = $entry->getPathname();
            }
        }
        self::assertSame([$path], $paths);
    }

    private function storedSource(string $contents): string
    {
        $path = $this->storageRoot . '/bucket/source';
        mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
        return $path;
    }

    private function armedIntent(SqliteMetadataStore $metadata): ObjectWriteIntent
    {
        $attempt = str_repeat('f', 32);
        $generation = str_repeat('a', 32);
        $reserved = $metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            StoragePath::forPlannedObject($this->storageRoot, 'bucket', 'key', $generation, $attempt),
            'put_object',
        ), 60.0);
        return $metadata->armObjectWriteIntent($attempt, $reserved->leaseUntil, str_repeat('1', 32), 60.0)
            ?? throw new \RuntimeException('Fixture intent was not armed.');
    }

    public function test_guarded_copy_handler_wiring_does_not_resolve_the_efs_root_again(): void
    {
        $evidence = $this->reader();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $evidence);
        $writer = new LocalFencedObjectWriter($this->storageRoot, $scratch, 'STANDARD', efsEvidence: $evidence);
        $movedRoot = $this->base . '/storage-moved';

        try {
            self::assertTrue(rename($this->storageRoot, $movedRoot));
            $handler = new CopyObjectHandler(
                new SqliteMetadataStore($this->base . '/copy.sqlite'),
                $backend,
                localFencedWriter: $writer,
                localLeaseFactory: $this->createMock(LocalObjectWriteLeaseGuardFactory::class),
            );
            self::assertInstanceOf(CopyObjectHandler::class, $handler);
        } finally {
            if (is_dir($movedRoot)) {
                self::assertTrue(rename($movedRoot, $this->storageRoot));
            }
            $writer->shutdown();
            $backend->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    self::assertTrue(rmdir($entry->getPathname()));
                } else {
                    self::assertTrue(unlink($entry->getPathname()));
                }
            }
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    self::assertTrue(rmdir($entry->getPathname()));
                } else {
                    self::assertTrue(unlink($entry->getPathname()));
                }
            }
            self::assertTrue(rmdir($scratch));
        }
    }

    public function test_guarded_put_access_log_and_background_wiring_do_not_resolve_the_efs_root_again(): void
    {
        $evidence = $this->reader();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $evidence);
        $writer = new LocalFencedObjectWriter($this->storageRoot, $scratch, 'STANDARD', efsEvidence: $evidence);
        $metadata = new SqliteMetadataStore($this->base . '/wiring.sqlite');
        $leases = $this->createMock(LocalObjectWriteLeaseGuardFactory::class);
        $movedRoot = $this->base . '/storage-moved';

        try {
            self::assertTrue(rename($this->storageRoot, $movedRoot));
            self::assertFalse(realpath($this->storageRoot));

            self::assertInstanceOf(PutObjectHandler::class, new PutObjectHandler(
                $metadata,
                $backend,
                localFencedWriter: $writer,
                localLeaseFactory: $leases,
            ));
            self::assertInstanceOf(AccessLogWriter::class, new AccessLogWriter(
                $metadata,
                $backend,
                localFencedWriter: $writer,
                localLeaseFactory: $leases,
            ));

            // Reaching SQL reservation proves the background target check used
            // the pinned root without consulting the unavailable EFS path.
            $leases->expects(self::once())->method('reserve')
                ->willThrowException(new \RuntimeException('reservation reached'));
            try {
                (new LocalFencedBackgroundObjectWriter($writer, $leases))->publish(
                    new ObjectInfo('bucket', 'key', 0, '""'),
                    new BucketInfo('bucket', 'owner', generation: str_repeat('a', 32)),
                    $backend,
                    'STANDARD',
                    new ReadableBuffer('body'),
                    'test',
                );
                self::fail('The background writer did not reach reservation.');
            } catch (\RuntimeException $error) {
                self::assertSame('reservation reached', $error->getMessage());
            }
        } finally {
            if (is_dir($movedRoot)) {
                self::assertTrue(rename($movedRoot, $this->storageRoot));
            }
            $writer->shutdown();
            $backend->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    self::assertTrue(rmdir($entry->getPathname()));
                } else {
                    self::assertTrue(unlink($entry->getPathname()));
                }
            }
            self::assertTrue(rmdir($scratch));
        }
    }

    public function test_guarded_access_log_keeps_buffer_and_avoids_sql_after_watcher_trip(): void
    {
        $evidence = $this->reader();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $evidence);
        $writer = new LocalFencedObjectWriter($this->storageRoot, $scratch, 'STANDARD', efsEvidence: $evidence);
        $leases = $this->createMock(LocalObjectWriteLeaseGuardFactory::class);
        /** @var MetadataStore&ObjectWriteIntentStore&\PHPUnit\Framework\MockObject\MockObject $metadata */
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectWriteIntentStore::class]);
        $bucketReads = 0;
        $metadata->expects(self::exactly(3))->method('getBucket')->willReturnCallback(
            function (string $bucket) use (&$bucketReads): BucketInfo {
                if (++$bucketReads === 3) {
                    self::assertTrue(unlink($this->evidencePath));
                }

                return new BucketInfo($bucket, 'owner', generation: str_repeat('a', 32));
            },
        );
        $metadata->expects(self::once())->method('getBucketLogging')->willReturn([
            'targetBucket' => 'log-target',
            'targetPrefix' => 'access/',
        ]);

        try {
            $notifications = new NotificationDispatcher($metadata, efsEvidence: $evidence);
            $logs = new AccessLogWriter(
                $metadata,
                $backend,
                notifications: $notifications,
                localFencedWriter: $writer,
                localLeaseFactory: $leases,
                efsEvidence: $evidence,
            );
            $logs->assertBoundTo($metadata, $backend, 'STANDARD', $writer, $leases, $evidence, $notifications, null, null);
            try {
                $logs->assertBoundTo($this->createMock(MetadataStore::class), $backend, 'STANDARD', $writer, $leases, $evidence, $notifications, null, null);
                self::fail('Access log accepted a different handler metadata instance.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('differs from the qualified EFS resources', $error->getMessage());
            }
            try {
                $logs->setNotificationDispatcher(new NotificationDispatcher($metadata, efsEvidence: $evidence));
                self::fail('Qualified access log accepted a second notification dispatcher.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('cannot be changed', $error->getMessage());
            }
            $logs->log('source', 'key', 'GetObject', 200, 1);
            self::assertSame(1, $logs->getBufferCount());
            $logs->log('source', 'later', 'GetObject', 200, 1);
            self::assertSame(1, $logs->getBufferCount());
            $logs->log('source', 'after-trip', 'GetObject', 200, 1);
            self::assertSame(1, $logs->getBufferCount());
            try {
                $logs->flush();
                self::fail('Access log flush passed a tripped watcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('EFS host evidence', $error->getMessage());
            }
            self::assertSame(1, $logs->getBufferCount());
        } finally {
            $writer->shutdown();
            $backend->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    self::assertTrue(rmdir($entry->getPathname()));
                } else {
                    self::assertTrue(unlink($entry->getPathname()));
                }
            }
            self::assertTrue(rmdir($scratch));
        }
    }

    public function test_guarded_access_log_requeues_when_watcher_trips_during_flush_query(): void
    {
        $evidence = $this->reader();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $backend = new FilesystemBackend($this->storageRoot, efsEvidence: $evidence);
        $writer = new LocalFencedObjectWriter($this->storageRoot, $scratch, 'STANDARD', efsEvidence: $evidence);
        $leases = $this->createMock(LocalObjectWriteLeaseGuardFactory::class);
        /** @var MetadataStore&ObjectWriteIntentStore&\PHPUnit\Framework\MockObject\MockObject $metadata */
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectWriteIntentStore::class]);
        $bucketReads = 0;
        $metadata->expects(self::exactly(3))->method('getBucket')->willReturnCallback(
            function (string $bucket) use (&$bucketReads): BucketInfo {
                if (++$bucketReads === 3) {
                    self::assertTrue(unlink($this->evidencePath));
                }

                return new BucketInfo($bucket, 'owner', generation: str_repeat('a', 32));
            },
        );
        $metadata->expects(self::once())->method('getBucketLogging')->willReturn([
            'targetBucket' => 'log-target',
            'targetPrefix' => 'access/',
        ]);
        $metadata->expects(self::never())->method('getObjectLockConfig');

        try {
            $logs = new AccessLogWriter(
                $metadata,
                $backend,
                localFencedWriter: $writer,
                localLeaseFactory: $leases,
                efsEvidence: $evidence,
            );
            $logs->log('source', 'key', 'GetObject', 200, 1);
            self::assertSame(1, $logs->getBufferCount());
            $logs->flush();
            self::assertSame(1, $logs->getBufferCount());
        } finally {
            $writer->shutdown();
            $backend->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir()) {
                    self::assertTrue(rmdir($entry->getPathname()));
                } else {
                    self::assertTrue(unlink($entry->getPathname()));
                }
            }
            self::assertTrue(rmdir($scratch));
        }
    }

    public function test_readiness_evidence_must_match_the_exact_filesystem_backend_reader(): void
    {
        $reader = $this->reader();
        $otherReader = $this->reader();
        $guarded = new FilesystemBackend($this->storageRoot, efsEvidence: $reader);
        $unguarded = new FilesystemBackend($this->storageRoot);

        try {
            self::assertInstanceOf(HealthCheckHandler::class, new HealthCheckHandler(
                storageTiers: StorageTierRegistry::single($guarded),
                efsEvidence: $reader,
            ));

            foreach ([
                [$guarded, $otherReader],
                [$unguarded, $reader],
                [$guarded, new class implements EfsHostEvidence {
                    public function assertFresh(): void {}
                }],
            ] as [$backend, $evidence]) {
                try {
                    new HealthCheckHandler(
                        storageTiers: StorageTierRegistry::single($backend),
                        efsEvidence: $evidence,
                    );
                    self::fail('Readiness accepted evidence from a different filesystem graph.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('does not match', $error->getMessage());
                }
            }
        } finally {
            $guarded->shutdown();
            $unguarded->shutdown();
        }
    }

    private function localTiers(EfsHostEvidenceReader $evidence): StorageTierRegistry
    {
        return new StorageTierRegistry([
            new StorageTier('STANDARD', new FilesystemBackend($this->storageRoot, efsEvidence: $evidence), defaultWriteTier: true),
        ]);
    }

    private function reader(?\Closure $mountInfoReader = null): EfsHostEvidenceReader
    {
        return new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            'postgres',
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            $mountInfoReader ?? fn(): string => $this->mountInfo,
        );
    }

    /** @return array<array-key, int>|false */
    private function rootOwnedLstat(string $path): array|false
    {
        $entry = @lstat($path);
        if (is_array($entry) && $this->fakeRootOwner) {
            $entry['uid'] = 0;
        }
        return $entry;
    }

    /** @param resource $handle
     *  @return array<array-key, int>|false
     */
    private function rootOwnedFstat($handle): array|false
    {
        $entry = fstat($handle);
        if (is_array($entry) && $this->fakeRootOwner) {
            $entry['uid'] = 0;
        }
        return $entry;
    }

    /** @return array<string, int|string> */
    private function record(): array
    {
        $storage = stat($this->storageRoot);
        self::assertIsArray($storage);

        return [
            'schemaVersion' => 1,
            'runId' => self::RUN_ID,
            'efsId' => 'fs-12345678',
            'sourceSha256' => self::SOURCE_SHA,
            'node' => 'api-one',
            'driver' => 'postgres',
            'containerId' => str_repeat('c', 64),
            'containerPid' => 1234,
            'relayPort' => 20049,
            'relayPid' => 5432,
            'hostMountId' => '39',
            'storageDev' => $storage['dev'],
            'storageIno' => $storage['ino'],
            'bootId' => self::BOOT_ID,
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => $this->nowNs,
            'expiresMonotonicNs' => $this->nowNs + 20_000_000_000,
        ];
    }

    /** @param array<string, int|string> $record */
    private function writeRecord(array $record): void
    {
        file_put_contents($this->evidencePath, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
        chmod($this->evidencePath, 0644);
        clearstatcache(true, $this->evidencePath);
    }

    private function mountInfo(string $case = 'valid'): string
    {
        $options = $case === 'rw' ? 'rw' : 'ro';
        $type = $case === 'nfs' ? 'nfs4' : 'tmpfs';
        $mountInfo = "1 0 8:1 / / rw - ext4 /dev/root rw\n";
        $mountInfo .= "2 1 0:42 / {$this->evidenceDirectory} {$options} - {$type} tmpfs rw\n";
        if ($case === 'nested') {
            $mountInfo .= "3 2 0:43 / {$this->evidencePath} rw - tmpfs tmpfs rw\n";
        }

        return $mountInfo;
    }

    private function assertRejected(EfsHostEvidenceReader $reader): void
    {
        try {
            $reader->assertFresh();
            self::fail('Invalid evidence was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('credential-value', $exception->getMessage());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }
}

/** @implements \IteratorAggregate<int, string> */
final class EvidenceDroppingReadableStream implements ReadableStream, \IteratorAggregate
{
    private int $read = 0;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onClose = [];

    public function __construct(private readonly string $evidencePath) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        $cancellation?->throwIfRequested();
        if ($this->closed) {
            return null;
        }
        if ($this->read++ === 0) {
            return 'first-chunk';
        }
        if ($this->read === 2) {
            if (! @unlink($this->evidencePath)) {
                throw new \RuntimeException('Test evidence could not be removed.');
            }

            return 'must-not-be-written';
        }
        $this->close();

        return null;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->onClose as $callback) {
            $callback();
        }
        $this->onClose = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
            return;
        }
        $this->onClose[] = $onClose;
    }

    public function getIterator(): \Traversable
    {
        while (($chunk = $this->read()) !== null) {
            yield $chunk;
        }
    }
}

final class EvidenceDropBeforeLinkIo implements EfsAwareLocalObjectWriteIo
{
    public int $linkCalls = 0;

    public int $renameCalls = 0;

    private NativeLocalObjectWriteIo $inner;

    public function __construct(
        private readonly string $evidencePath,
        private readonly bool $dropAfterRename = false,
    ) {
        $this->inner = new NativeLocalObjectWriteIo();
    }

    public function syncFile($file): bool
    {
        $result = $this->inner->syncFile($file);
        if (! $this->dropAfterRename) {
            unlink($this->evidencePath);
        }

        return $result;
    }

    public function syncDirectory(string $path, ?\Closure $afterSyscall = null): bool
    {
        return $this->inner->syncDirectoryWithCallback($path, $afterSyscall);
    }

    public function syncDirectoryWithEvidence(
        string $path,
        EfsHostEvidenceReader|\OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence $evidence,
    ): bool {
        return $this->inner->syncDirectoryWithEvidence($path, $evidence);
    }

    public function link(string $source, string $target): bool
    {
        $this->linkCalls++;
        return $this->inner->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        $this->renameCalls++;
        $renamed = $this->inner->rename($source, $target);
        if ($this->dropAfterRename) {
            unlink($this->evidencePath);
        }
        return $renamed;
    }
}

final class EvidenceDropDeleteIo implements LocalEfsDeleteIo
{
    public int $unlinkCalls = 0;

    public int $listCalls = 0;

    private NativeLocalEfsDeleteIo $inner;

    public function __construct(
        private readonly string $evidencePath,
        private readonly bool $dropAfterList = false,
        private readonly bool $dropAfterUnlink = false,
        private readonly bool $reportFailureAfterUnlink = false,
        private readonly bool $failListing = false,
    ) {
        $this->inner = new NativeLocalEfsDeleteIo();
    }

    public function listDirectory(string $path): array|false
    {
        $this->listCalls++;
        $entries = $this->failListing ? false : $this->inner->listDirectory($path);
        if ($this->dropAfterList) {
            unlink($this->evidencePath);
        }
        return $entries;
    }

    public function isDirectory(string $path): bool
    {
        return $this->inner->isDirectory($path);
    }

    public function isFile(string $path): bool
    {
        return $this->inner->isFile($path);
    }

    public function isLink(string $path): bool
    {
        return $this->inner->isLink($path);
    }

    public function realPath(string $path): string|false
    {
        return $this->inner->realPath($path);
    }

    public function unlinkFile(string $path): bool
    {
        $this->unlinkCalls++;
        $result = $this->inner->unlinkFile($path);
        if ($this->dropAfterUnlink) {
            unlink($this->evidencePath);
        }
        return $this->reportFailureAfterUnlink ? false : $result;
    }

    public function syncDirectory(string $path, ?\Closure $afterSyscall = null): bool
    {
        return $this->inner->syncDirectory($path, $afterSyscall);
    }
}
