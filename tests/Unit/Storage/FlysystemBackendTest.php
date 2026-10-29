<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Flysystem\Filesystem;
use League\Flysystem\Config;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToReadFile;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await as awaitFutures;

final class FlysystemBackendTest extends TestCase
{
    public function test_bucket_operations_reject_path_segments_that_normalize_into_other_namespaces(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        foreach (['safe/../other', '../other', '.parts', ''] as $bucket) {
            foreach ([
                static fn() => $backend->createBucket($bucket),
                static fn() => $backend->bucketExists($bucket),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Invalid bucket path segment must be rejected.');
                } catch (\InvalidArgumentException) {
                }
            }
        }

        self::assertFalse($backend->bucketExists('__health_check__'));
        self::assertFalse($filesystem->directoryExists('other'));
    }

    public function test_exact_delete_accepts_safe_internal_bucket_path_segment(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $filesystem->write('__health_check__/path', 'keep');

        (new FlysystemBackend($filesystem, $this->tempDir))
            ->deleteObjectByPath('__health_check__/path', '__health_check__');

        self::assertFalse($filesystem->fileExists('__health_check__/path'));
    }

    public function test_object_write_copy_and_assembly_destinations_reject_bucket_traversal(): void
    {
        $adapter = new InMemoryFlysystemAdapter();
        $backend = new FlysystemBackend(new Filesystem($adapter), $this->tempDir);
        $source = $backend->putObject('source', 'key', new ReadableBuffer('source'));

        foreach ([
            static fn() => $backend->putObject('safe/../other', 'key', new ReadableBuffer('bad')),
            static fn() => $backend->copyObject($source->path, 'safe/../other', 'key'),
            static fn() => $backend->assembleMultipartUpload('safe/../other', 'key', 'upload', []),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid destination bucket must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame([$source->path], $adapter->paths());
            }
        }
    }

    public function test_planned_multipart_assembly_rejects_foreign_bucket_or_key_scope(): void
    {
        $adapter = new InMemoryFlysystemAdapter();
        $backend = new FlysystemBackend(new Filesystem($adapter), $this->tempDir);
        $paths = $backend->planMultipartCompletionPaths('bucket', 'key', bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        foreach ([['other', 'key'], ['bucket', 'other-key']] as [$bucket, $key]) {
            try {
                $backend->assembleMultipartUploadAtPath($bucket, $key, 'upload', [], $paths['final']);
                self::fail('Foreign planned assembly path must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $adapter->paths());
            }
        }
    }

    public function test_read_rejects_normalized_traversal_before_adapter_access(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $filesystem->write('other/path', 'keep');
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        foreach (['bucket/../other/path', 'bucket\\..\\other\\path', '/other/path'] as $path) {
            try {
                $backend->getObjectByPath($path);
                self::fail('Malformed stored path must not be read.');
            } catch (\InvalidArgumentException) {
                self::assertSame('keep', $filesystem->read('other/path'));
            }
        }
    }

    public function test_delete_rejects_foreign_bucket_and_normalized_traversal(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $filesystem->write('other/path', 'keep');
        $filesystem->write('.assemblies/other/path', 'keep assembly');
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        foreach (['other/path', 'bucket/../other/path', 'bucket\\..\\other\\path', '.assemblies/other/path'] as $path) {
            try {
                $backend->deleteObjectByPath($path, 'bucket');
                self::fail('Foreign or malformed stored path must not be deleted.');
            } catch (\InvalidArgumentException) {
                self::assertSame('keep', $filesystem->read('other/path'));
                self::assertSame('keep assembly', $filesystem->read('.assemblies/other/path'));
            }
        }
    }

    public function test_copy_source_rejects_path_that_normalizes_into_another_bucket(): void
    {
        $adapter = new InMemoryFlysystemAdapter();
        $filesystem = new Filesystem($adapter);
        $filesystem->write('other/path', 'keep');
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $backend->copyObject('bucket/../other/path', 'bucket', 'copy');
        } finally {
            self::assertSame(['other/path'], $adapter->paths());
        }
    }

    public function test_multipart_assembly_rejects_part_path_that_normalizes_into_another_bucket(): void
    {
        $adapter = new InMemoryFlysystemAdapter();
        $filesystem = new Filesystem($adapter);
        $filesystem->write('other/part', 'part');
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $backend->assembleMultipartUpload('bucket', 'key', 'upload', [
                ['partNumber' => 1, 'etag' => md5('part'), 'storagePath' => 'bucket/../other/part'],
            ]);
        } finally {
            self::assertSame(['other/part'], $adapter->paths());
        }
    }

    public function test_delete_failure_is_reported_when_remote_file_still_exists(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('delete')
            ->willThrowException(UnableToDeleteFile::atLocation('bucket/path'));
        $filesystem->expects(self::once())->method('fileExists')
            ->with('bucket/path')->willReturn(true);

        $this->expectException(InternalErrorException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->deleteObjectByPath('bucket/path', 'bucket');
    }

    public function test_delete_failure_is_idempotent_when_remote_file_is_gone(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('delete')
            ->willThrowException(UnableToDeleteFile::atLocation('bucket/path'));
        $filesystem->expects(self::once())->method('fileExists')
            ->with('bucket/path')->willReturn(false);

        (new FlysystemBackend($filesystem, $this->tempDir))->deleteObjectByPath('bucket/path', 'bucket');
    }

    public function test_multipart_listing_failure_keeps_cleanup_retryable(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('listContents')
            ->with('.parts/upload', false)
            ->willThrowException(UnableToListContents::atLocation(
                '.parts/upload',
                false,
                new \RuntimeException('listing failed'),
            ));
        $filesystem->expects(self::once())->method('directoryExists')
            ->with('.parts/upload')->willReturn(true);

        $this->expectException(InternalErrorException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->abortMultipartUpload('bucket', 'key', 'upload');
    }

    public function test_legacy_abort_preserves_generation_bound_part_paths(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $backend = new FlysystemBackend($filesystem, $this->tempDir);
        $uploadId = bin2hex(random_bytes(16));
        $legacy = $backend->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer('legacy'));
        $plannedPath = $backend->planMultipartPartPath(
            'bucket',
            bin2hex(random_bytes(16)),
            $uploadId,
            2,
            bin2hex(random_bytes(16)),
        );
        $planned = $backend->putPartAtPath('bucket', 'key', $uploadId, 2, new ReadableBuffer('generation-bound'), $plannedPath);

        $backend->abortMultipartUpload('bucket', 'key', $uploadId);

        self::assertFalse($filesystem->fileExists($legacy->path));
        self::assertTrue($filesystem->fileExists($planned->path));
        self::assertSame('generation-bound', self::readAll($backend->getObjectByPath($planned->path)));
    }

    public function test_legacy_abort_blocks_out_of_range_and_malformed_part_names(): void
    {
        $token = str_repeat('a', 32);
        foreach ([
            '0', '10001', '01',
            '0-' . $token, '10001-' . $token, '01-' . $token,
            '0-' . $token . '-' . $token,
            '10001-' . $token . '-' . $token,
            '1-' . $token . '-broken',
            '1-' . str_repeat('a', 31),
        ] as $name) {
            $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
            $path = '.parts/upload/' . $name;
            $filesystem->write($path, 'keep');
            $backend = new FlysystemBackend($filesystem, $this->tempDir);

            try {
                $backend->abortMultipartUpload('bucket', 'key', 'upload');
                self::fail("Unknown multipart name {$name} must block cleanup.");
            } catch (InternalErrorException) {
                self::assertTrue($filesystem->fileExists($path), $name);
            }
        }
    }

    public function test_legacy_abort_accepts_canonical_maximum_part_number(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $token = str_repeat('a', 32);
        $generationBound = '.parts/upload/10000-' . $token . '-' . str_repeat('b', 32);
        foreach (['.parts/upload/10000', '.parts/upload/10000-' . $token, $generationBound] as $path) {
            $filesystem->write($path, 'part');
        }

        (new FlysystemBackend($filesystem, $this->tempDir))->abortMultipartUpload('bucket', 'key', 'upload');

        self::assertFalse($filesystem->fileExists('.parts/upload/10000'));
        self::assertFalse($filesystem->fileExists('.parts/upload/10000-' . $token));
        self::assertTrue($filesystem->fileExists($generationBound));
    }

    public function test_malformed_upload_id_cannot_turn_part_abort_into_root_deletion(): void
    {
        $filesystem = new Filesystem(new InMemoryFlysystemAdapter());
        $filesystem->write('unrelated-bucket/object', 'keep');
        $backend = new FlysystemBackend($filesystem, $this->tempDir);

        foreach (['..', '../unrelated-bucket', 'safe/../..', 'safe\\..', ''] as $uploadId) {
            try {
                $backend->abortMultipartUpload('bucket', 'key', $uploadId);
                self::fail('Malformed upload ID must fail before directory deletion.');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame('keep', self::readFile($filesystem, 'unrelated-bucket/object'));

            try {
                $backend->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer('bad'));
                self::fail('Malformed upload ID must fail before part placement.');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame('keep', self::readFile($filesystem, 'unrelated-bucket/object'));
        }
    }

    public function test_name_only_bucket_deletion_never_touches_an_existing_namespace(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('deleteDirectory');
        $filesystem->expects(self::never())->method('directoryExists');

        $this->expectException(\LogicException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->deleteBucket('bucket');
    }

    public function test_name_only_bucket_deletion_does_not_probe_an_absent_namespace(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::never())->method('deleteDirectory');
        $filesystem->expects(self::never())->method('directoryExists');

        $this->expectException(\LogicException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->deleteBucket('bucket');
    }

    public function test_remote_read_failure_is_not_reported_as_missing_object(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('readStream')
            ->willThrowException(UnableToReadFile::fromLocation('bucket/path'));
        $filesystem->expects(self::once())->method('fileExists')
            ->with('bucket/path')->willReturn(true);

        $this->expectException(InternalErrorException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path');
    }

    public function test_cancelled_remote_read_does_not_probe_existence_or_become_missing_object(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('readStream')
            ->willThrowException(new \Amp\CancelledException());
        $filesystem->expects(self::never())->method('fileExists');

        $this->expectException(\Amp\CancelledException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path');
    }

    public function test_cancelled_existence_probe_after_read_failure_propagates_cancellation(): void
    {
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('readStream')
            ->willThrowException(UnableToReadFile::fromLocation('bucket/path'));
        $filesystem->expects(self::once())->method('fileExists')
            ->willThrowException(new \Amp\CancelledException());

        $this->expectException(\Amp\CancelledException::class);
        (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path');
    }

    public function test_nonseekable_range_waits_for_transient_empty_read_before_skipping_offset(): void
    {
        $scheme = 's3transientrange';
        self::assertTrue(stream_wrapper_register($scheme, FlysystemTransientEmptyStream::class));
        try {
            $resource = fopen($scheme . '://data', 'r');
            self::assertIsResource($resource);
            $filesystem = $this->createMock(FilesystemOperator::class);
            $filesystem->method('readStream')->willReturn($resource);

            $range = (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path', 3, 3);
            self::assertSame('def', self::readAll($range));
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_nonseekable_range_rejects_eof_before_requested_offset(): void
    {
        $scheme = 's3shortrange';
        self::assertTrue(stream_wrapper_register($scheme, FlysystemTransientEmptyStream::class));
        try {
            $resource = fopen($scheme . '://data', 'r');
            self::assertIsResource($resource);
            $filesystem = $this->createMock(FilesystemOperator::class);
            $filesystem->method('readStream')->willReturn($resource);

            $this->expectException(InternalErrorException::class);
            (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path', 8, 1);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_seekable_file_range_rejects_offset_beyond_physical_eof(): void
    {
        $path = $this->tempDir . '/short-object';
        file_put_contents($path, 'abcdef');
        $resource = fopen($path, 'rb');
        self::assertIsResource($resource);
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn($resource);

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path', 8, 1);
            self::fail('A physical object shorter than the range offset must be rejected.');
        } catch (InternalErrorException) {
            self::assertFalse(is_resource($resource), 'The rejected stream must be closed.');
        }
    }

    public function test_seekable_file_range_rejects_positive_length_starting_at_physical_eof(): void
    {
        $path = $this->tempDir . '/short-object';
        file_put_contents($path, 'abcdef');
        $resource = fopen($path, 'rb');
        self::assertIsResource($resource);
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn($resource);

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path', 6, 1);
            self::fail('A nonempty range starting at physical EOF must be rejected.');
        } catch (InternalErrorException) {
            self::assertFalse(is_resource($resource), 'The rejected stream must be closed.');
        }
    }

    public function test_seekable_file_allows_zero_length_slice_at_physical_eof(): void
    {
        $path = $this->tempDir . '/short-object';
        file_put_contents($path, 'abcdef');
        $resource = fopen($path, 'rb');
        self::assertIsResource($resource);
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('readStream')->willReturn($resource);

        $slice = (new FlysystemBackend($filesystem, $this->tempDir))->getObjectByPath('bucket/path', 6, 0);
        self::assertSame('', self::readAll($slice));
    }
    private InMemoryFlysystemAdapter $adapter;

    private FlysystemBackend $backend;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryFlysystemAdapter();
        $this->tempDir = sys_get_temp_dir() . '/s3server-flysystem-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
        $this->backend = new FlysystemBackend(new Filesystem($this->adapter), $this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function test_put_get_delete_round_trip_uses_flysystem_streams(): void
    {
        $this->backend->createBucket('bucket');

        $result = $this->backend->putObject('bucket', 'path/object.txt', new ReadableBuffer('hello remote'));

        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame(1, $this->adapter->writeStreamCalls);
        self::assertSame(0, $this->adapter->writeCalls);
        self::assertSame(12, $result->size);
        self::assertSame('hello remote', $this->adapter->contents($result->path));

        $stream = $this->backend->getObjectByPath($result->path);
        self::assertSame('hello remote', self::readAll($stream));

        $range = $this->backend->getObjectByPath($result->path, 6, 6);
        self::assertSame('remote', self::readAll($range));

        $this->backend->deleteObjectByPath($result->path, 'bucket');
        $this->expectException(NoSuchKeyException::class);
        $this->backend->getObjectByPath($result->path);
    }

    public function test_planned_part_uses_exact_adapter_path_and_can_be_reconciled_after_late_write(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $path = $this->backend->planMultipartPartPath('bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
        $result = $this->backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('ciphertext'), $path);
        self::assertSame($path, $result->path);
        self::assertSame('ciphertext', $this->adapter->contents($path));
        self::assertSame([], self::temporaryFiles($this->tempDir));

        $this->backend->deleteObjectByPath($path, 'bucket');
        self::assertFalse($this->adapter->fileExists($path));
        $this->backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('late ciphertext'), $path);
        self::assertSame('late ciphertext', $this->adapter->contents($path));
        $this->backend->deleteObjectByPath($path, 'bucket');
        self::assertFalse($this->adapter->fileExists($path));
    }

    public function test_planned_object_write_uses_exact_adapter_path_and_rejects_other_scope(): void
    {
        $generation = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $path = $this->backend->planObjectWritePath('bucket', 'key', $generation, $attempt);

        foreach ([['other-bucket', 'key', $path], ['bucket', 'other-key', $path], ['bucket', 'key', 'bucket/../escape']] as [$bucket, $key, $invalid]) {
            try {
                $this->backend->putObjectAtPath($bucket, $key, new ReadableBuffer('wrong'), $invalid);
                self::fail('Invalid planned object path must be rejected before adapter I/O.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->adapter->paths());

        $write = $this->backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('planned'), $path);
        self::assertSame($path, $write->path);
        self::assertSame('planned', $this->adapter->contents($path));
        self::assertSame(1, $this->adapter->writeStreamCalls);
    }

    public function test_completion_object_write_rejects_foreign_bucket_and_key_hash(): void
    {
        $generation = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $foreignBucket = $this->backend->planMultipartCompletionPaths('foreign', 'key', $generation, $attempt);
        $foreignKey = $this->backend->planMultipartCompletionPaths('bucket', 'other-key', $generation, $attempt);

        foreach ([$foreignBucket['assembly'], $foreignBucket['final'], $foreignKey['assembly'], $foreignKey['final']] as $path) {
            try {
                $this->backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('wrong'), $path);
                self::fail('Foreign multipart completion path must be rejected before adapter I/O.');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $this->adapter->paths());

        $valid = $this->backend->planMultipartCompletionPaths('bucket', 'key', $generation, $attempt);
        self::assertSame($valid['final'], $this->backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('final'), $valid['final'])->path);
    }

    public function test_forged_planned_part_upload_id_cannot_escape_part_directory(): void
    {
        $path = '.parts/../1-' . bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(16));

        $this->expectException(\InvalidArgumentException::class);
        $this->backend->putPartAtPath('bucket', 'key', '..', 1, new ReadableBuffer('forged'), $path);
    }

    public function test_missing_configured_temp_directory_rejects_object_before_read_or_remote_write(): void
    {
        $missingDirectory = $this->tempDir . '/missing';
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::never())->method('read');
        $systemTempBefore = glob(sys_get_temp_dir() . '/s3put_*') ?: [];

        try {
            (new FlysystemBackend(new Filesystem($this->adapter), $missingDirectory))
                ->putObject('bucket', 'object', $body);
            self::fail('A missing configured staging directory must reject the upload.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to create Flysystem temporary file.', $error->getMessage());
        }

        self::assertSame($systemTempBefore, glob(sys_get_temp_dir() . '/s3put_*') ?: []);
        self::assertSame(0, $this->adapter->writeStreamCalls);
        self::assertSame([], $this->adapter->paths());
    }

    public function test_non_directory_temp_path_rejects_part_before_read_or_remote_write(): void
    {
        $notDirectory = $this->tempDir . '/not-a-directory';
        file_put_contents($notDirectory, 'sentinel');
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::never())->method('read');
        $systemTempBefore = glob(sys_get_temp_dir() . '/s3part_*') ?: [];

        try {
            (new FlysystemBackend(new Filesystem($this->adapter), $notDirectory))
                ->putPart('bucket', 'object', 'upload', 1, $body);
            self::fail('An invalid configured staging directory must reject the part.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to create Flysystem temporary file.', $error->getMessage());
        }

        self::assertSame('sentinel', file_get_contents($notDirectory));
        self::assertSame($systemTempBefore, glob(sys_get_temp_dir() . '/s3part_*') ?: []);
        self::assertSame(0, $this->adapter->writeStreamCalls);
        self::assertSame([], $this->adapter->paths());
    }

    public function test_valid_configured_temp_directory_stages_privately_and_writes_object(): void
    {
        $readCount = 0;
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::exactly(2))->method('read')->willReturnCallback(
            function () use (&$readCount): ?string {
                if ($readCount++ > 0) {
                    return null;
                }

                $paths = glob($this->tempDir . '/s3put_*') ?: [];
                self::assertCount(1, $paths);
                self::assertSame(0600, fileperms($paths[0]) & 0777);

                return 'private payload';
            },
        );

        $result = $this->backend->putObject('bucket', 'object', $body);

        self::assertSame('private payload', $this->adapter->contents($result->path));
        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame(1, $this->adapter->writeStreamCalls);
    }

    public function test_known_size_object_and_part_writes_reserve_before_reading_source(): void
    {
        $budget = new StorageTempBudgetCoordinator($this->tempDir, 4, 0);
        $backend = new FlysystemBackend(new Filesystem($this->adapter), $this->tempDir, $budget);
        $generation = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $uploadId = bin2hex(random_bytes(16));
        $partAttempt = bin2hex(random_bytes(16));
        $plannedObject = $backend->planObjectWritePath('bucket', 'key', $generation, $attempt);
        $plannedPart = $backend->planMultipartPartPath('bucket', $generation, $uploadId, 1, $partAttempt);
        $writes = [
            static fn(ReadableStream $body) => $backend->putObjectKnownSize('bucket', 'key', $body, 5),
            static fn(ReadableStream $body) => $backend->putObjectAtPathKnownSize('bucket', 'key', $body, $plannedObject, 5),
            static fn(ReadableStream $body) => $backend->putPartKnownSize('bucket', 'key', $uploadId, 1, $body, 5),
            static fn(ReadableStream $body) => $backend->putPartAtPathKnownSize('bucket', 'key', $uploadId, 1, $body, $plannedPart, 5),
        ];

        try {
            foreach ($writes as $write) {
                $body = $this->createMock(ReadableStream::class);
                $body->expects(self::never())->method('read');
                try {
                    $write($body);
                    self::fail('Known-size Flysystem staging exceeded the node budget.');
                } catch (SlowDownException) {
                    self::assertSame([], $this->adapter->paths());
                    self::assertSame([], self::temporaryFiles($this->tempDir));
                    self::assertSame(0, $budget->snapshot()->reservedBytes);
                }
            }
        } finally {
            $budget->close();
            foreach (glob($this->tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir . '/.opsfour-s3-storage-budget');
        }
    }

    public function test_known_size_writes_account_for_longer_streams_and_return_shorter_actual_sizes(): void
    {
        $budget = new StorageTempBudgetCoordinator($this->tempDir, 16, 0);
        $backend = new FlysystemBackend(new Filesystem($this->adapter), $this->tempDir, $budget);

        try {
            self::assertSame(4, $backend->putObjectKnownSize('bucket', 'long', new ReadableBuffer('data'), 3)->size);
            self::assertSame(4, $backend->putPartKnownSize(
                'bucket',
                'short',
                bin2hex(random_bytes(16)),
                1,
                new ReadableBuffer('part'),
                5,
            )->size);
            self::assertSame(0, $budget->snapshot()->reservedBytes);
        } finally {
            $backend->shutdown();
            $budget->close();
            foreach (glob($this->tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir . '/.opsfour-s3-storage-budget');
        }
    }

    public function test_cancelled_write_is_not_replaced_by_temp_budget_release_failure(): void
    {
        $budget = new StorageTempBudgetCoordinator($this->tempDir, 8);
        $backend = new FlysystemBackend(new Filesystem($this->adapter), $this->tempDir, $budget);
        $cancellation = new \Amp\CancelledException();
        $body = $this->createMock(ReadableStream::class);
        $body->method('read')->willReturnCallback(function () use ($cancellation): never {
            $states = glob($this->tempDir . '/.opsfour-s3-storage-budget/state-*') ?: [];
            self::assertCount(1, $states);
            self::assertNotFalse(file_put_contents($states[0], '{corrupt'));

            throw $cancellation;
        });

        try {
            $backend->putObject('bucket', 'cancelled', $body);
            self::fail('Temp-budget cleanup failure must not replace cancellation.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], self::temporaryFiles($this->tempDir));
        } finally {
            foreach (glob($this->tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir . '/.opsfour-s3-storage-budget');
        }
    }

    public function test_cancelled_adapter_object_write_is_not_wrapped_or_replaced_by_cleanup_cancellation(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('writeStream')->willThrowException($cancellation);
        $filesystem->expects(self::once())->method('delete')->willThrowException(new CancelledException());

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->putObject('bucket', 'object', new ReadableBuffer('payload'));
            self::fail('Adapter cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], self::temporaryFiles($this->tempDir));
        }
    }

    public function test_cancelled_adapter_part_write_is_not_wrapped_or_replaced_by_cleanup_cancellation(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('writeStream')->willThrowException($cancellation);
        $filesystem->expects(self::once())->method('delete')->willThrowException(new CancelledException());

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->putPart('bucket', 'object', 'upload', 1, new ReadableBuffer('payload'));
            self::fail('Adapter cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], self::temporaryFiles($this->tempDir));
        }
    }

    public function test_cancelled_multipart_part_read_is_not_wrapped(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('readStream')->with('.parts/upload/part')
            ->willThrowException($cancellation);
        $filesystem->expects(self::never())->method('writeStream');

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))->assembleMultipartUpload(
                'bucket',
                'object',
                'upload',
                [['partNumber' => 1, 'etag' => md5('payload'), 'size' => 7, 'storagePath' => '.parts/upload/part']],
            );
            self::fail('Multipart part read cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], self::temporaryFiles($this->tempDir));
        }
    }

    public function test_cancelled_multipart_assembly_write_is_not_wrapped_or_replaced_by_cleanup_cancellation(): void
    {
        $partStream = fopen('php://temp', 'w+b');
        self::assertIsResource($partStream);
        self::assertSame(strlen('payload'), fwrite($partStream, 'payload'));
        rewind($partStream);

        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('readStream')->willReturn($partStream);
        $filesystem->expects(self::once())->method('writeStream')->willThrowException($cancellation);
        $filesystem->expects(self::once())->method('delete')->willThrowException(new CancelledException());

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))->assembleMultipartUpload(
                'bucket',
                'object',
                'upload',
                [['partNumber' => 1, 'etag' => md5('payload'), 'size' => 7, 'storagePath' => '.parts/upload/part']],
            );
            self::fail('Multipart assembly cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], self::temporaryFiles($this->tempDir));
        }
    }

    public function test_cancelled_copy_source_probe_is_not_wrapped(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willThrowException($cancellation);
        $filesystem->expects(self::never())->method('copy');

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->copyObject('bucket/source', 'bucket', 'copy');
            self::fail('Copy source probe cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_cancelled_adapter_copy_is_not_wrapped_or_replaced_by_cleanup_cancellation(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willReturn(true);
        $filesystem->expects(self::once())->method('copy')->willThrowException($cancellation);
        $filesystem->expects(self::once())->method('delete')->willThrowException(new CancelledException());

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->copyObject('bucket/source', 'bucket', 'copy');
            self::fail('Copy cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_cancelled_copied_object_read_is_not_wrapped_or_replaced_by_cleanup_cancellation(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willReturn(true);
        $filesystem->expects(self::once())->method('copy');
        $filesystem->expects(self::once())->method('readStream')->willThrowException($cancellation);
        $filesystem->expects(self::once())->method('delete')->willThrowException(new CancelledException());

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->copyObject('bucket/source', 'bucket', 'copy');
            self::fail('Copied object read cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_copy_cleanup_cancellation_is_not_suppressed_after_adapter_failure(): void
    {
        $cancellation = new CancelledException();
        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->expects(self::once())->method('fileExists')->willReturn(true);
        $filesystem->expects(self::once())->method('copy')->willThrowException(new \RuntimeException('copy failed'));
        $filesystem->expects(self::once())->method('delete')->willThrowException($cancellation);

        try {
            (new FlysystemBackend($filesystem, $this->tempDir))
                ->copyObject('bucket/source', 'bucket', 'copy');
            self::fail('Cleanup cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_multipart_assembly_uses_configured_temp_dir_and_cleans_up_parts(): void
    {
        $this->backend->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));

        $part1 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 1, new ReadableBuffer('abc'));
        $part2 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 2, new ReadableBuffer('def'));

        $before = scandir($this->tempDir);
        $result = $this->backend->assembleMultipartUpload('bucket', 'large.bin', $uploadId, [
            ['partNumber' => 1, 'etag' => $part1->md5Hex, 'storagePath' => $part1->path],
            ['partNumber' => 2, 'etag' => $part2->md5Hex, 'storagePath' => $part2->path],
        ]);
        $after = scandir($this->tempDir);

        self::assertSame($before, $after);
        self::assertSame('abcdef', self::readAll($this->backend->getObjectByPath($result->path)));
        self::assertSame(6, $result->size);
        self::assertStringEndsWith('-2', $result->md5Hex);
        self::assertNotSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, ".parts/{$uploadId}/"),
        )));

        $this->backend->abortMultipartUpload('bucket', 'large.bin', $uploadId);
        self::assertSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, ".parts/{$uploadId}/"),
        )));
    }

    public function test_multipart_assembly_rejects_part_bytes_changed_after_upload(): void
    {
        $uploadId = bin2hex(random_bytes(8));
        $part = $this->backend->putPart('bucket', 'object.bin', $uploadId, 1, new ReadableBuffer('original'));
        $this->adapter->write($part->path, 'tampered', new Config());

        try {
            $this->backend->assembleMultipartUpload('bucket', 'object.bin', $uploadId, [[
                'partNumber' => 1,
                'etag' => $part->md5Hex,
                'storagePath' => $part->path,
            ]]);
            self::fail('Changed part bytes were accepted.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('checksum verification', $error->getMessage());
        }

        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, 'bucket/'),
        )));
    }

    public function test_multipart_assembly_never_writes_bytes_beyond_the_admitted_part_size(): void
    {
        $uploadId = bin2hex(random_bytes(8));
        $part = $this->backend->putPart('bucket', 'object.bin', $uploadId, 1, new ReadableBuffer('original'));
        $this->adapter->write($part->path, 'original-plus-one', new Config());
        $writesBeforeAssembly = $this->adapter->writeStreamCalls;

        try {
            $this->backend->assembleMultipartUpload('bucket', 'object.bin', $uploadId, [[
                'partNumber' => 1,
                'etag' => md5('original-plus-one'),
                'size' => $part->size,
                'storagePath' => $part->path,
            ]]);
            self::fail('A remotely enlarged part exceeded its admitted temporary bytes.');
        } catch (InternalErrorException $error) {
            self::assertStringContainsString('exceeded its admitted size', $error->getMessage());
        }

        self::assertSame($writesBeforeAssembly, $this->adapter->writeStreamCalls);
        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, 'bucket/'),
        )));
    }

    public function test_failed_part_replacement_can_delete_new_attempt_without_losing_previous_part(): void
    {
        $uploadId = 'upload-' . bin2hex(random_bytes(4));
        $previous = $this->backend->putPart(
            'bucket',
            'object.bin',
            $uploadId,
            1,
            new ReadableBuffer('previous'),
        );
        $replacement = $this->backend->putPart(
            'bucket',
            'object.bin',
            $uploadId,
            1,
            new ReadableBuffer('replacement'),
        );

        self::assertNotSame($previous->path, $replacement->path);
        $this->backend->deleteObjectByPath($replacement->path, 'bucket');

        $assembled = $this->backend->assembleMultipartUpload('bucket', 'object.bin', $uploadId, [[
            'partNumber' => 1,
            'etag' => $previous->md5Hex,
            'storagePath' => $previous->path,
        ]]);

        self::assertSame('previous', self::readAll($this->backend->getObjectByPath($assembled->path)));
    }

    public function test_copy_object_reads_and_writes_through_flysystem(): void
    {
        $this->backend->createBucket('bucket');
        $source = $this->backend->putObject('bucket', 'source.txt', new ReadableBuffer('copy me'));

        $copy = $this->backend->copyObject($source->path, 'bucket', 'copy.txt');

        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame('copy me', self::readAll($this->backend->getObjectByPath($copy->path)));
        self::assertSame(1, $this->adapter->writeStreamCalls);
        self::assertSame(1, $this->adapter->copyCalls);
        self::assertSame(7, $copy->size);
        self::assertSame(hash('md5', 'copy me'), $copy->md5Hex);
    }

    public function test_bounded_copy_streams_only_the_limit_plus_detection_byte(): void
    {
        $this->backend->createBucket('bucket');
        $source = $this->backend->putObject('bucket', 'source.txt', new ReadableBuffer(str_repeat('x', 1_000_000)));

        $copy = $this->backend->copyObjectBounded($source->path, 'bucket', 'copy.txt', 4);

        self::assertSame(4, $copy->size);
        self::assertSame('xxxx', self::readAll($this->backend->getObjectByPath($copy->path)));
        self::assertSame(0, $this->adapter->copyCalls);
        self::assertSame([], self::temporaryFiles($this->tempDir));
    }

    public function test_bounded_copy_does_not_treat_its_upper_bound_as_the_exact_source_size(): void
    {
        $this->backend->createBucket('bucket');
        $source = $this->backend->putObject('bucket', 'short.txt', new ReadableBuffer('abc'));

        $copy = $this->backend->copyObjectBounded($source->path, 'bucket', 'short-copy.txt', 4);

        self::assertSame(3, $copy->size);
        self::assertSame('abc', self::readAll($this->backend->getObjectByPath($copy->path)));
        self::assertSame([], self::temporaryFiles($this->tempDir));
    }

    public function test_remote_write_failure_is_reported_and_does_not_leave_partial_file(): void
    {
        $sentinel = 'storage-token=secret-sentinel';
        $this->adapter->failNextWriteStreamReason = $sentinel;

        try {
            $this->backend->putObject('bucket', 'broken.bin', new ReadableBuffer('payload'));
            self::fail('The adapter write failure must be reported.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to write object to Flysystem backend.', $error->getMessage());
            self::assertStringNotContainsString($sentinel, $error->getMessage());
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString($sentinel, $error->getPrevious()->getMessage());
        } finally {
            self::assertSame([], $this->adapter->paths());
        }
    }

    public function test_failed_object_write_removes_bytes_left_by_the_adapter(): void
    {
        $this->adapter->failNextWriteStreamAfterWriteReason = 'failure after object bytes';

        try {
            $this->backend->putObject('bucket', 'broken.bin', new ReadableBuffer('partial object'));
            self::fail('The adapter failure must be reported.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to write object to Flysystem backend.', $error->getMessage());
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString('failure after object bytes', $error->getPrevious()->getMessage());
        }

        self::assertSame([], $this->adapter->paths());
        self::assertSame([], self::temporaryFiles($this->tempDir));
    }

    public function test_failed_part_write_removes_bytes_left_by_the_adapter(): void
    {
        $this->adapter->failNextWriteStreamAfterWriteReason = 'failure after part bytes';

        try {
            $this->backend->putPart('bucket', 'broken.bin', 'abcdef123456', 1, new ReadableBuffer('partial part'));
            self::fail('The adapter failure must be reported.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to write multipart part to Flysystem backend.', $error->getMessage());
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString('failure after part bytes', $error->getPrevious()->getMessage());
        }

        self::assertSame([], $this->adapter->paths());
        self::assertSame([], self::temporaryFiles($this->tempDir));
    }

    public function test_failed_multipart_assembly_write_removes_only_its_partial_object(): void
    {
        $part = $this->backend->putPart('bucket', 'broken.bin', 'abcdef123456', 1, new ReadableBuffer('committed part'));
        $this->adapter->failNextWriteStreamAfterWriteReason = 'failure after assembled bytes';

        try {
            $this->backend->assembleMultipartUpload('bucket', 'broken.bin', 'abcdef123456', [[
                'partNumber' => 1,
                'etag' => $part->md5Hex,
                'storagePath' => $part->path,
            ]]);
            self::fail('The adapter failure must be reported.');
        } catch (InternalErrorException $error) {
            self::assertSame('Failed to write assembled multipart object to Flysystem backend.', $error->getMessage());
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString('failure after assembled bytes', $error->getPrevious()->getMessage());
        }

        self::assertSame([$part->path], $this->adapter->paths());
        self::assertSame('committed part', $this->adapter->contents($part->path));
        self::assertSame([], self::temporaryFiles($this->tempDir));
    }

    /** @return list<string> */
    private static function temporaryFiles(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);

        return array_values(array_filter(
            $entries,
            static fn(string $entry): bool => str_starts_with($entry, 's3put_')
                || str_starts_with($entry, 's3part_')
                || str_starts_with($entry, 's3copy_')
                || str_starts_with($entry, 's3mpu_'),
        ));
    }

    public function test_concurrent_stream_uploads_stay_isolated(): void
    {
        $this->backend->createBucket('bucket');

        $futures = [];
        for ($i = 0; $i < 50; $i++) {
            $futures[] = async(function () use ($i) {
                return $this->backend->putObject(
                    'bucket',
                    "concurrent/{$i}.bin",
                    new ReadableBuffer(str_repeat(chr(65 + ($i % 26)), 4096)),
                );
            });
        }

        $results = awaitFutures($futures);

        self::assertCount(50, $results);
        self::assertSame(50, $this->adapter->writeStreamCalls);
        foreach ($results as $result) {
            self::assertSame(4096, $result->size);
            self::assertContains($result->path, $this->adapter->paths());
        }
    }

    public function test_sse_s3_round_trip_uses_opaque_flysystem_paths(): void
    {
        $databasePath = $this->tempDir . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->backend->createBucket('bucket');
        $encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
        $plaintext = str_repeat('remote encrypted payload ', 128);
        $putRequest = new Request(
            new FlysystemBackendTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/encrypted.bin'),
            ['x-amz-server-side-encryption' => 'AES256'],
            $plaintext,
        );
        $putRequest->setAttribute('s3.bucket', 'bucket');
        $putRequest->setAttribute('s3.key', 'encrypted.bin');
        $putRequest->setAttribute('s3.operation', S3Operation::PutObject);
        $putRequest->setAttribute('ownerId', 'owner');

        $putResponse = (new PutObjectHandler($metadata, $this->backend, $encryption))
            ->handleRequest($putRequest);

        self::assertSame(200, $putResponse->getStatus());
        $object = $metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($object);
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        self::assertIsString($storagePath);
        self::assertNotSame($plaintext, $this->adapter->contents($storagePath));

        $getRequest = new Request(
            new FlysystemBackendTestClient(),
            'GET',
            Http::new('http://127.0.0.1/bucket/encrypted.bin'),
        );
        $getRequest->setAttribute('s3.bucket', 'bucket');
        $getRequest->setAttribute('s3.key', 'encrypted.bin');
        $getRequest->setAttribute('ownerId', 'owner');
        $getResponse = (new GetObjectHandler($metadata, $this->backend, $encryption))
            ->handleRequest($getRequest);

        self::assertSame(200, $getResponse->getStatus());
        self::assertSame($plaintext, self::readAll($getResponse->getBody()));
        self::assertSame([], self::temporaryFiles($this->tempDir));
        @unlink($databasePath);
        @unlink($databasePath . '-wal');
        @unlink($databasePath . '-shm');
    }

    /** @phpstan-impure */
    private static function readFile(Filesystem $filesystem, string $path): string
    {
        return $filesystem->read($path);
    }

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }
}

final class FlysystemBackendTestClient implements Client
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

/** Nonseekable adapter stream with one would-block read before its bytes arrive. */
final class FlysystemTransientEmptyStream
{
    public mixed $context;

    private int $position = 0;

    private bool $firstRead = true;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->firstRead) {
            $this->firstRead = false;

            return '';
        }

        $chunk = substr('abcdef', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 6;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
