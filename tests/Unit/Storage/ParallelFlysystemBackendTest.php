<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\File\File;
use Amp\File\Filesystem;
use Amp\File\FilesystemDriver;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Tests\Support\SlowLocalFlysystemFactory;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class ParallelFlysystemBackendTest extends TestCase
{
    private string $remoteRoot;

    private string $tempDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/s3-parallel-flysystem-' . bin2hex(random_bytes(6));
        $this->remoteRoot = $base . '/remote';
        $this->tempDir = $base . '/temp';
        mkdir($this->remoteRoot, 0o755, true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory(dirname($this->remoteRoot));
    }

    public function test_worker_rejects_invalid_bucket_and_foreign_assembly_before_submission(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        $paths = $backend->planMultipartCompletionPaths('bucket', 'key', bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));
        try {
            foreach ([
                static fn() => $backend->createBucket('safe/../other'),
                static fn() => $backend->bucketExists('safe/../other'),
                static fn() => $backend->putObject('safe/../other', 'key', new ReadableBuffer('bad')),
                static fn() => $backend->copyObject('source/path', 'safe/../other', 'key'),
                static fn() => $backend->copyObjectBounded('source/path', 'safe/../other', 'key', 10),
                static fn() => $backend->assembleMultipartUpload('safe/../other', 'key', 'upload', []),
                static fn() => $backend->assembleMultipartUploadAtPath('other', 'key', 'upload', [], $paths['final']),
                static fn() => $backend->assembleMultipartUploadAtPath('bucket', 'other-key', 'upload', [], $paths['final']),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Worker must reject invalid path scope before submission.');
                } catch (\InvalidArgumentException) {
                }
            }
            self::assertDirectoryDoesNotExist($this->remoteRoot . '/other');
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_read_and_delete_reject_paths_that_normalize_into_another_bucket(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        $objectPath = 'other/aa/bb/' . bin2hex(random_bytes(16));
        mkdir($this->remoteRoot . '/other/aa/bb', 0o755, true);
        file_put_contents($this->remoteRoot . '/' . $objectPath, 'keep');
        try {
            $traversal = 'bucket/../' . $objectPath;

            try {
                $backend->getObjectByPath($traversal);
                self::fail('Worker read must reject a path crossing bucket scope.');
            } catch (\InvalidArgumentException) {
            }

            foreach ([$objectPath, $traversal] as $path) {
                try {
                    $backend->deleteObjectByPath($path, 'bucket');
                    self::fail('Worker delete must reject a foreign bucket.');
                } catch (\InvalidArgumentException) {
                }
            }

            foreach ([
                static fn() => $backend->copyObject($traversal, 'bucket', 'copy'),
                static fn() => $backend->copyObjectBounded($traversal, 'bucket', 'copy', 10),
            ] as $copy) {
                try {
                    $copy();
                    self::fail('Worker copy must reject a normalized traversal.');
                } catch (\InvalidArgumentException) {
                }
            }

            self::assertSame('keep', file_get_contents($this->remoteRoot . '/' . $objectPath));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_name_only_bucket_deletion_is_rejected_before_worker_submission(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        try {
            $backend->createBucket('bucket');
            $object = $backend->putObject('bucket', 'new-generation', new ReadableBuffer('keep'));
            try {
                $backend->deleteBucket('bucket');
                self::fail('Name-only deletion must be rejected.');
            } catch (\LogicException) {
                self::assertSame('keep', self::readAll($backend->getObjectByPath($object->path)));
                self::assertTrue($backend->bucketExists('bucket'));
            }
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_abort_rejects_traversal_upload_id_before_submission(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        try {
            $backend->createBucket('bucket');
            $object = $backend->putObject('bucket', 'keep', new ReadableBuffer('keep'));
            try {
                $backend->abortMultipartUpload('bucket', 'key', '..');
                self::fail('Traversal upload ID must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('keep', self::readAll($backend->getObjectByPath($object->path)));
            }
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_writes_only_the_preplanned_object_path(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        try {
            $backend->createBucket('bucket');
            $path = $backend->planObjectWritePath('bucket', 'key', bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));
            try {
                $backend->putObjectAtPath('bucket', 'other-key', new ReadableBuffer('wrong'), $path);
                self::fail('Wrong-key planned write must fail before worker submission.');
            } catch (\InvalidArgumentException) {
            }

            $write = $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('worker'), $path);
            self::assertSame($path, $write->path);
            self::assertSame('worker', self::readAll($backend->getObjectByPath($path)));
            self::assertSame('worker', file_get_contents($this->remoteRoot . '/' . $path));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_backend_supports_round_trip_ranges_copy_and_multipart(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            2,
        );

        try {
            $backend->createBucket('bucket');
            self::assertTrue($backend->bucketExists('bucket'));

            $write = $backend->putObject('bucket', 'object.txt', new ReadableBuffer('hello worker storage'));
            self::assertSame('hello worker storage', self::readAll($backend->getObjectByPath($write->path)));
            self::assertSame('worker', self::readAll($backend->getObjectByPath($write->path, 6, 6)));

            $copy = $backend->copyObject($write->path, 'bucket', 'copy.txt');
            self::assertSame('hello worker storage', self::readAll($backend->getObjectByPath($copy->path)));

            $part1 = $backend->putPart('bucket', 'multipart.bin', 'upload', 1, new ReadableBuffer('abc'));
            $part2 = $backend->putPart('bucket', 'multipart.bin', 'upload', 2, new ReadableBuffer('def'));
            $assembled = $backend->assembleMultipartUpload('bucket', 'multipart.bin', 'upload', [
                ['partNumber' => 1, 'etag' => $part1->md5Hex, 'storagePath' => $part1->path],
                ['partNumber' => 2, 'etag' => $part2->md5Hex, 'storagePath' => $part2->path],
            ]);
            self::assertSame('abcdef', self::readAll($backend->getObjectByPath($assembled->path)));
            $backend->abortMultipartUpload('bucket', 'multipart.bin', 'upload');

            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_writes_planned_part_to_exact_adapter_path(): void
    {
        $backend = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        try {
            $backend->createBucket('bucket');
            $uploadId = bin2hex(random_bytes(16));
            $path = $backend->planMultipartPartPath('bucket', bin2hex(random_bytes(16)), $uploadId, 1, bin2hex(random_bytes(16)));
            $result = $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('ciphertext'), $path);
            self::assertSame($path, $result->path);
            self::assertSame('ciphertext', self::readAll($backend->getObjectByPath($path)));
            $backend->deleteObjectByPath($path, 'bucket');
            $this->expectException(NoSuchKeyException::class);
            $backend->getObjectByPath($path)->read();
        } finally {
            $backend->shutdown();
        }
    }

    public function test_observed_bounded_copy_completes_with_one_shared_worker_and_caps_corrupt_source(): void
    {
        $pool = new ContextWorkerPool(1);
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            pool: $pool,
            readPool: $pool,
        );
        $observed = new ObservedStorageBackend($backend, new MetricsCollector(), 'flysystem');

        try {
            $observed->createBucket('bucket');
            $source = $observed->putObject('bucket', 'large', new ReadableBuffer(str_repeat('x', 1_000_000)));
            $copy = $observed->copyObjectBounded($source->path, 'bucket', 'copy', 4);

            self::assertSame(4, $copy->size);
            self::assertSame('xxxx', self::readAll($observed->getObjectByPath($copy->path)));
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
            $pool->shutdown();
        }
    }

    public function test_bounded_copy_obeys_worker_temp_budget_and_releases_failed_reservation(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'source', new ReadableBuffer('abcd'));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, tempBudgetBytes: 3);

        try {
            try {
                $backend->copyObjectBounded($source->path, 'bucket', 'copy', 5);
                self::fail('The worker copy exceeded the shared temporary budget.');
            } catch (SlowDownException) {
                self::assertSame([], self::stagingFiles($this->tempDir));
                self::assertCount(1, iterator_to_array(new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($this->remoteRoot, \FilesystemIterator::SKIP_DOTS),
                )));
            }

            self::assertSame(1, $backend->copyObjectBounded($source->path, 'bucket', 'small-copy', 1)->size);
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_api_and_worker_temp_files_share_one_budget(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            tempBudgetBytes: 7,
        );

        try {
            $backend->createBucket('bucket');
            try {
                $backend->putObject('bucket', 'too-large-together', new ReadableBuffer('abcd'));
                self::fail('Four API staging bytes plus four worker staging bytes exceeded the seven-byte budget.');
            } catch (SlowDownException) {
                self::assertSame([], self::stagingFiles($this->tempDir));
            }

            self::assertSame(3, $backend->putObject('bucket', 'fits-together', new ReadableBuffer('abc'))->size);
            try {
                $backend->putPart('bucket', 'key', 'upload', 1, new ReadableBuffer('abcd'));
                self::fail('Multipart API and worker staging must share the same seven-byte budget.');
            } catch (SlowDownException) {
                self::assertSame([], self::stagingFiles($this->tempDir));
            }
            self::assertSame(3, $backend->putPart('bucket', 'key', 'upload', 1, new ReadableBuffer('abc'))->size);
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_part_spool_stops_at_configured_test_boundary_and_cleans_temp_file(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            tempBudgetBytes: 8,
        );

        try {
            $spool = new \ReflectionMethod(ParallelFlysystemBackend::class, 'spool');
            try {
                $spool->invoke($backend, new ReadableBuffer('abcd'), 's3fw-part-', 3);
                self::fail('Oversized multipart input must fail before the worker receives it.');
            } catch (EntityTooLargeException $error) {
                self::assertSame(400, $error->getHttpStatus());
                self::assertSame([], self::stagingFiles($this->tempDir));
            }
        } finally {
            $backend->shutdown();
        }
    }

    public function test_cancelled_upload_spool_preserves_cancellation_and_releases_temp_budget(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            tempBudgetBytes: 8,
        );
        $source = $this->createMock(ReadableStream::class);
        $source->expects(self::once())->method('read')->willThrowException(new CancelledException());

        try {
            try {
                $backend->putObject('bucket', 'cancelled', $source);
                self::fail('A cancelled source read must propagate cancellation.');
            } catch (CancelledException) {
                self::assertSame([], self::stagingFiles($this->tempDir));
            }

            $backend->createBucket('bucket');
            $written = $backend->putObject('bucket', 'next', new ReadableBuffer('next'));
            self::assertSame('next', self::readAll($backend->getObjectByPath($written->path)));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_short_local_spool_write_rejects_put_and_part_before_worker_submit(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('openFile')->willReturnCallback(function (string $path): File {
            $file = $this->createMock(File::class);
            $file->method('write')->willReturnCallback(static function (string $bytes) use ($path): void {
                file_put_contents($path, substr($bytes, 0, 2), FILE_APPEND);
            });

            return $file;
        });
        $driver->method('getStatus')->willReturnCallback(static function (string $path): ?array {
            clearstatcache(true, $path);

            return @stat($path) ?: null;
        });
        $driver->method('deleteFile')->willReturnCallback(static function (string $path): void {
            unlink($path);
        });
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            pool: $pool,
            readPool: $pool,
            localFilesystem: new Filesystem($driver),
        );

        try {
            foreach (['put', 'part'] as $operation) {
                try {
                    if ($operation === 'put') {
                        $backend->putObject('bucket', 'key', new ReadableBuffer('abcdef'));
                    } else {
                        $backend->putPart('bucket', 'key', 'upload', 1, new ReadableBuffer('abcdef'));
                    }
                    self::fail('A short local spool write must fail before the worker receives it.');
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('staging file is incomplete', $error->getPrevious()?->getMessage() ?? '');
                    self::assertSame([], self::stagingFiles($this->tempDir));
                }
            }
        } finally {
            $backend->shutdown();
        }

        $valid = new ParallelFlysystemBackend(new SlowLocalFlysystemFactory($this->remoteRoot), $this->tempDir, 1);
        try {
            $valid->createBucket('bucket');
            $write = $valid->putObject('bucket', 'retry', new ReadableBuffer('abcdef'));
            self::assertSame('abcdef', self::readAll($valid->getObjectByPath($write->path)));
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $valid->shutdown();
        }
    }

    public function test_worker_uses_preplanned_generation_scoped_completion_paths(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            2,
        );

        try {
            $backend->createBucket('bucket');
            $paths = $backend->planMultipartCompletionPaths('bucket', 'key', str_repeat('a', 32), str_repeat('b', 32));
            $part = $backend->putPart('bucket', 'key', 'abcdef1234567890', 1, new ReadableBuffer('plaintext'));
            $assembly = $backend->assembleMultipartUploadAtPath('bucket', 'key', 'abcdef1234567890', [[
                'partNumber' => 1,
                'etag' => $part->md5Hex,
                'storagePath' => $part->path,
            ]], $paths['assembly']);
            self::assertSame($paths['assembly'], $assembly->path);
            self::assertSame('plaintext', self::readAll($backend->getObjectByPath($assembly->path)));

            $final = $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('ciphertext'), $paths['final']);
            self::assertSame($paths['final'], $final->path);
            self::assertSame('ciphertext', self::readAll($backend->getObjectByPath($final->path)));
            $backend->deleteObjectByPath($assembly->path, 'bucket');
            $backend->deleteObjectByPath($final->path, 'bucket');
        } finally {
            $backend->shutdown();
        }
    }

    public function test_removed_configured_temp_directory_rejects_upload_before_body_or_worker(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::never())->method('read');
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            pool: $pool,
            readPool: $pool,
        );
        rename($this->tempDir, $this->tempDir . '-moved');
        $systemTempBefore = glob(sys_get_temp_dir() . '/s3fw-put-*') ?: [];

        try {
            try {
                $backend->putObject('bucket', 'object', $body);
                self::fail('A removed configured staging directory must reject the upload.');
            } catch (InternalErrorException $error) {
                self::assertSame('Failed to create Flysystem worker temporary file.', $error->getMessage());
            }

            self::assertSame($systemTempBefore, glob(sys_get_temp_dir() . '/s3fw-put-*') ?: []);
            self::assertSame([], self::stagingFiles($this->tempDir . '-moved'));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_valid_worker_temp_directory_stages_privately(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
        );
        $readCount = 0;
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::exactly(2))->method('read')->willReturnCallback(
            function () use (&$readCount): ?string {
                if ($readCount++ > 0) {
                    return null;
                }

                $paths = glob($this->tempDir . '/s3fw-put-*') ?: [];
                self::assertCount(1, $paths);
                self::assertSame(0600, fileperms($paths[0]) & 0777);

                return 'private worker payload';
            },
        );

        try {
            $result = $backend->putObject('bucket', 'object', $body);
            self::assertSame('private worker payload', self::readAll($backend->getObjectByPath($result->path)));
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_slow_adapter_write_does_not_block_unrelated_event_loop_work(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot, 600_000),
            $this->tempDir,
            1,
        );

        try {
            $backend->createBucket('bucket');
            $startedAt = microtime(true);
            $upload = async(
                static fn() => $backend->putObject('bucket', 'slow.bin', new ReadableBuffer('payload')),
            );
            $timer = async(static function (): float {
                delay(0.05);

                return microtime(true);
            });

            $timerCompletedAt = $timer->await();
            self::assertLessThan(0.3, $timerCompletedAt - $startedAt);
            self::assertSame(7, $upload->await()->size);
        } finally {
            $backend->shutdown();
        }
    }

    public function test_blocked_write_worker_has_bounded_waiters_and_releases_rejected_upload_spools(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot, 2_500_000),
            $this->tempDir,
            1,
            writeAdmissionTimeoutSeconds: 1,
        );

        try {
            $backend->createBucket('bucket');
            $activeSlots = new \ReflectionProperty(ParallelFlysystemBackend::class, 'activeWriteSlots');
            $waiters = new \ReflectionProperty(ParallelFlysystemBackend::class, 'writeWaiters');
            $active = async(static fn() => $backend->putObject('bucket', 'active', new ReadableBuffer('active')));
            $this->awaitCondition(static fn(): bool => $activeSlots->getValue($backend) === 1);
            $waiting = async(static fn() => $backend->putObject('bucket', 'waiting', new ReadableBuffer('waiting')));
            $this->awaitCondition(static fn(): bool => count($waiters->getValue($backend)) === 1);

            self::assertSame(1, $activeSlots->getValue($backend));
            self::assertCount(1, $waiters->getValue($backend));

            $rejected = [];
            for ($i = 0; $i < 30; ++$i) {
                $rejected[] = async(static fn() => $backend->putObject('bucket', 'rejected-' . $i, new ReadableBuffer('x')));
            }
            foreach ($rejected as $write) {
                try {
                    $write->await();
                    self::fail('A full write-worker queue must reject an additional upload.');
                } catch (SlowDownException $error) {
                    self::assertSame(503, $error->getHttpStatus());
                    self::assertSame('Storage write workers are busy.', $error->getMessage());
                }
            }
            self::assertCount(1, $waiters->getValue($backend));

            try {
                $waiting->await();
                self::fail('A queued write must leave the admission queue at its deadline.');
            } catch (SlowDownException $error) {
                self::assertSame('Storage write workers are busy.', $error->getMessage());
            }
            self::assertSame([], $waiters->getValue($backend));
            self::assertSame(1, $activeSlots->getValue($backend));

            self::assertSame(6, $active->await()->size);
            self::assertSame(0, $activeSlots->getValue($backend));
            self::assertSame([], self::stagingFiles($this->tempDir));
            self::assertSame('0', file_get_contents($this->tempDir . '/.opsfour-s3-storage-budget/total'));
            self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/lease-*'));
            self::assertSame(9, $backend->putObject('bucket', 'recovered', new ReadableBuffer('recovered'))->size);
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_write_slot_passes_to_waiter_when_active_transfer_finishes_before_deadline(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot, 300_000),
            $this->tempDir,
            1,
            writeAdmissionTimeoutSeconds: 2,
        );

        try {
            $backend->createBucket('bucket');
            $first = async(static fn() => $backend->putObject('bucket', 'first', new ReadableBuffer('first')));
            delay(0.05);
            $second = async(static fn() => $backend->putObject('bucket', 'second', new ReadableBuffer('second')));

            self::assertSame(5, $first->await()->size);
            self::assertSame(6, $second->await()->size);
            $activeSlots = new \ReflectionProperty(ParallelFlysystemBackend::class, 'activeWriteSlots');
            self::assertSame(0, $activeSlots->getValue($backend));
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_open_get_response_uses_no_staging_budget_and_does_not_block_writes(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
            tempBudgetBytes: 6,
        );

        try {
            $backend->createBucket('bucket');
            $source = $backend->putObject('bucket', 'source', new ReadableBuffer('abc'));
            $response = $backend->getObjectByPath($source->path);
            self::assertSame([], self::stagingFiles($this->tempDir));
            self::assertSame(1, $backend->putObject('bucket', 'accepted', new ReadableBuffer('x'))->size);
            self::assertSame('abc', self::readAll($response));
            $response->close();
            self::assertSame([], self::stagingFiles($this->tempDir));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_get_larger_than_staging_budget_streams_without_temp_file(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer('abcd'));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, tempBudgetBytes: 3);

        try {
            self::assertSame('abcd', self::readAll($backend->getObjectByPath($source->path)));
        } finally {
            self::assertSame([], self::stagingFiles($this->tempDir));
            $backend->shutdown();
        }
    }

    public function test_client_close_releases_read_worker_and_does_not_leave_temp_file(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer(str_repeat('a', 200_000)));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, tempBudgetBytes: 3);

        try {
            $response = $backend->getObjectByPath($source->path);
            self::assertSame(65_536, strlen((string) $response->read()));
            $response->close();
            self::assertSame([], self::stagingFiles($this->tempDir));
            self::assertSame(str_repeat('a', 200_000), self::readAll($backend->getObjectByPath($source->path)));
            self::assertSame(str_repeat('a', 150_000), self::readAll($backend->getObjectByPath($source->path, 100, 150_000)));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_missing_object_fails_before_returning_a_response_stream(): void
    {
        $backend = new ParallelFlysystemBackend(
            new SlowLocalFlysystemFactory($this->remoteRoot),
            $this->tempDir,
            1,
        );

        try {
            $this->expectException(NoSuchKeyException::class);
            $backend->getObjectByPath('bucket/missing');
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_crash_during_stream_fails_the_next_read(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer(str_repeat('a', 200_000)));
        $readPool = new ContextWorkerPool(1);
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, readPool: $readPool);

        try {
            $response = $backend->getObjectByPath($source->path);
            self::assertSame(65_536, strlen((string) $response->read()));
            $readPool->kill();
            $this->expectException(InternalErrorException::class);
            $response->read();
        } finally {
            self::assertSame([], self::stagingFiles($this->tempDir));
            $backend->shutdown();
        }
    }

    public function test_shutdown_cancels_open_read_streams(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer(str_repeat('a', 200_000)));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1);
        $response = $backend->getObjectByPath($source->path);

        self::assertSame(65_536, strlen((string) $response->read()));
        $backend->shutdown();
        self::assertTrue($response->isClosed());
        self::assertSame([], self::stagingFiles($this->tempDir));
    }

    public function test_idle_read_expires_and_releases_worker(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer(str_repeat('a', 200_000)));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, readIdleTimeoutSeconds: 1);

        try {
            $response = $backend->getObjectByPath($source->path);
            self::assertSame(65_536, strlen((string) $response->read()));
            delay(1.3);
            try {
                $response->read();
                self::fail('An idle reader must release its worker.');
            } catch (\Throwable $error) {
                self::assertInstanceOf(InternalErrorException::class, $error);
                self::assertTrue($response->isClosed());
            }
            self::assertSame(200_000, strlen(self::readAll($backend->getObjectByPath($source->path))));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_first_byte_deadline_bounds_remote_open_and_wait_for_busy_read_worker(): void
    {
        $direct = new FlysystemBackend((new SlowLocalFlysystemFactory($this->remoteRoot))->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $slow = $direct->putObject('bucket', 'slow-read', new ReadableBuffer('slow'));
        $fast = $direct->putObject('bucket', 'fast', new ReadableBuffer('fast'));
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot, readDelayMicroseconds: 2_500_000, readDelayPath: $slow->path);
        $metrics = new MetricsCollector();
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, metrics: $metrics, firstByteTimeoutSeconds: 1);

        try {
            self::assertSame('fast', self::readAll($backend->getObjectByPath($fast->path)));
            $started = microtime(true);
            $slowRead = async(fn() => $backend->getObjectByPath($slow->path));
            delay(0.1); // The only read worker is now opening the slow object.
            $queuedRead = async(fn() => $backend->getObjectByPath($fast->path));

            foreach ([$slowRead, $queuedRead] as $read) {
                try {
                    $read->await();
                    self::fail('A remote first-byte or pool wait must respect the deadline.');
                } catch (SlowDownException $error) {
                    self::assertSame(503, $error->getHttpStatus());
                }
            }
            self::assertLessThan(2.3, microtime(true) - $started);

            // Cancellation cannot interrupt a synchronous adapter already in
            // readStream(). It must release the worker once that call returns.
            delay(2.0);
            self::assertSame('fast', self::readAll($backend->getObjectByPath($fast->path)));
            $activeSlots = new \ReflectionProperty(ParallelFlysystemBackend::class, 'activeReadSlots');
            self::assertSame(0, $activeSlots->getValue($backend));
            $rendered = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="ready"} 2', $rendered);
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="first_byte_timeout"} 1', $rendered);
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="admission_timeout"} 1', $rendered);
        } finally {
            $backend->shutdown();
        }
    }

    public function test_many_reads_do_not_accumulate_in_the_worker_pool_after_deadline(): void
    {
        $direct = new FlysystemBackend((new SlowLocalFlysystemFactory($this->remoteRoot))->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $slow = $direct->putObject('bucket', 'slow-read', new ReadableBuffer('slow'));
        $fast = $direct->putObject('bucket', 'fast', new ReadableBuffer('fast'));
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot, readDelayMicroseconds: 2_500_000, readDelayPath: $slow->path);
        $metrics = new MetricsCollector();
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1, metrics: $metrics, firstByteTimeoutSeconds: 1);

        try {
            $slowRead = async(fn() => $backend->getObjectByPath($slow->path));
            delay(0.1);
            $queued = [];
            for ($i = 0; $i < 40; ++$i) {
                $queued[] = async(fn() => $backend->getObjectByPath($fast->path));
            }

            delay(0.05);
            $waiters = new \ReflectionProperty(ParallelFlysystemBackend::class, 'readWaiters');
            self::assertCount(1, $waiters->getValue($backend));

            foreach ([$slowRead, ...$queued] as $read) {
                try {
                    $read->await();
                    self::fail('A blocked read worker must not admit another read beyond its deadline.');
                } catch (SlowDownException) {
                    // The active read and its bounded waiter both expire.
                }
            }

            self::assertSame([], $waiters->getValue($backend));

            // The synchronous adapter cannot be interrupted, but no cancelled
            // requests may be submitted after it releases the worker.
            delay(2.0);
            self::assertSame('fast', self::readAll($backend->getObjectByPath($fast->path)));
            $activeSlots = new \ReflectionProperty(ParallelFlysystemBackend::class, 'activeReadSlots');
            self::assertSame(0, $activeSlots->getValue($backend));
            $rendered = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="admission_rejected"} 39', $rendered);
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="admission_timeout"} 1', $rendered);
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="first_byte_timeout"} 1', $rendered);
        } finally {
            $backend->shutdown();
        }
    }

    public function test_cancelled_first_read_releases_the_prefetched_worker_chunk(): void
    {
        $factory = new SlowLocalFlysystemFactory($this->remoteRoot);
        $direct = new FlysystemBackend($factory->createFilesystem(), $this->tempDir);
        $direct->createBucket('bucket');
        $source = $direct->putObject('bucket', 'large', new ReadableBuffer(str_repeat('a', 200_000)));
        $backend = new ParallelFlysystemBackend($factory, $this->tempDir, 1);

        try {
            $response = $backend->getObjectByPath($source->path);
            $cancellation = new DeferredCancellation();
            $cancellation->cancel();
            try {
                $response->read($cancellation->getCancellation());
                self::fail('A cancelled read must not return prefetched data.');
            } catch (CancelledException) {
                self::assertTrue($response->isClosed());
            }
            self::assertSame(200_000, strlen(self::readAll($backend->getObjectByPath($source->path))));
        } finally {
            $backend->shutdown();
        }
    }

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @param callable(): bool $condition */
    private function awaitCondition(callable $condition): void
    {
        $deadline = microtime(true) + 2.0;
        while (! $condition() && microtime(true) < $deadline) {
            delay(0.01);
        }
        self::assertTrue($condition(), 'Timed out while waiting for the worker admission state.');
    }

    /** @return list<string> */
    private static function stagingFiles(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);

        return array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && $entry !== '.opsfour-s3-storage-budget',
        ));
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
