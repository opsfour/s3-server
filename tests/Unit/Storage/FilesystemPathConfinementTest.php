<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class FilesystemPathConfinementTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->root = sys_get_temp_dir() . '/s3-path-boundary-' . $suffix;
        $this->outside = sys_get_temp_dir() . '/s3-path-outside-' . $suffix;
        mkdir($this->root . '/bucket', 0700, true);
        file_put_contents($this->outside, 'outside-canary');
    }

    protected function tearDown(): void
    {
        if (is_file($this->outside)) {
            unlink($this->outside);
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function test_stored_path_cannot_read_outside_the_storage_root(): void
    {
        $backend = $this->backend();
        symlink($this->outside, $this->root . '/bucket/link');

        foreach ([$this->outside, $this->root . '/bucket/../../' . basename($this->outside), $this->root . '/bucket/link'] as $path) {
            try {
                $stream = $backend->getObjectByPath($path);
                $stream->close();
                self::fail('An out-of-root stored path must be rejected before opening the file.');
            } catch (\InvalidArgumentException) {
                self::assertSame('outside-canary', file_get_contents($this->outside));
            }
        }
    }

    public function test_stored_path_cannot_delete_outside_the_storage_root(): void
    {
        $backend = $this->backend();
        symlink($this->outside, $this->root . '/bucket/link');

        foreach ([$this->outside, $this->root . '/bucket/../../' . basename($this->outside), $this->root . '/bucket/link'] as $path) {
            try {
                $backend->deleteObjectByPath($path, 'bucket');
                self::fail('An out-of-root stored path must be rejected before deleting the file.');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame('outside-canary', file_get_contents($this->outside));
        }
    }

    public function test_normal_generated_object_path_remains_readable(): void
    {
        $backend = $this->backend();
        $result = $backend->putObject('bucket', 'key', new ReadableBuffer('inside'));

        self::assertSame('inside', buffer($backend->getObjectByPath($result->path)));
        $backend->deleteObjectByPath($result->path, 'bucket');
        self::assertFileDoesNotExist($result->path);
    }

    public function test_trailing_slash_root_writes_canonical_paths_and_reads_both_historical_spellings(): void
    {
        foreach ([$this->root, $this->root . '/'] as $configuredRoot) {
            $backend = $this->backend($configuredRoot);
            self::assertSame($this->root, $backend->getBasePath());

            $ordinary = $backend->putObject('bucket', 'ordinary', new ReadableBuffer('ordinary-data'));
            $plannedPath = $backend->planObjectWritePath('bucket', 'planned', str_repeat('a', 32), bin2hex(random_bytes(16)));
            $planned = $backend->putObjectAtPath('bucket', 'planned', new ReadableBuffer('planned-data'), $plannedPath);

            foreach ([[$ordinary->path, 'ordinary-data'], [$planned->path, 'planned-data']] as [$path, $expected]) {
                self::assertStringStartsWith($this->root . '/bucket/', $path);
                self::assertStringNotContainsString($this->root . '//', $path);
                $legacy = $this->root . '/' . substr($path, strlen($this->root));
                self::assertSame($expected, buffer($backend->getObjectByPath($legacy)));
                $backend->deleteObjectByPath($legacy, 'bucket');
                self::assertFileDoesNotExist($path);
            }
        }
    }

    public function test_legacy_root_separator_does_not_weaken_delete_or_read_scope(): void
    {
        $backend = $this->backend($this->root . '/');
        $backend->createBucket('other-bucket');
        $other = $backend->putObject('other-bucket', 'private', new ReadableBuffer('private-data'));
        $legacyOther = $this->root . '/' . substr($other->path, strlen($this->root));
        symlink($this->outside, $this->root . '/bucket/link');

        foreach ([
            $legacyOther,
            $this->root . '//bucket/../other-bucket/' . basename($other->path),
            $this->root . '///bucket/' . basename($other->path),
            $this->root . '//bucket/inside//file',
            $this->root . '//bucket/link',
        ] as $unsafe) {
            try {
                $backend->deleteObjectByPath($unsafe, 'bucket');
                self::fail('Unsafe historical spelling must not allow deletion.');
            } catch (\InvalidArgumentException) {
                self::assertSame('private-data', file_get_contents($other->path));
                self::assertSame('outside-canary', file_get_contents($this->outside));
            }
        }
        foreach ([$this->root . '//bucket/../other-bucket/' . basename($other->path), $this->root . '//bucket/link'] as $unsafe) {
            try {
                $backend->getObjectByPath($unsafe);
                self::fail('Unsafe historical spelling must not allow reading.');
            } catch (\InvalidArgumentException) {
                self::assertSame('outside-canary', file_get_contents($this->outside));
            }
        }
    }

    public function test_legacy_part_and_copy_source_can_be_read_after_root_normalization(): void
    {
        $backend = $this->backend($this->root . '/');
        $part = $backend->putPart('bucket', 'assembled', str_repeat('a', 32), 1, new ReadableBuffer('part-data'));
        $legacyPart = $this->root . '/' . substr($part->path, strlen($this->root));
        $completion = $backend->planMultipartCompletionPaths('bucket', 'assembled', str_repeat('b', 32), str_repeat('c', 32));
        $assembled = $backend->assembleMultipartUploadAtPath('bucket', 'assembled', str_repeat('a', 32), [[
            'partNumber' => 1,
            'etag' => $part->md5Hex,
            'storagePath' => $legacyPart,
        ]], $completion['assembly']);
        self::assertSame('part-data', buffer($backend->getObjectByPath($assembled->path)));

        $legacySource = $this->root . '/' . substr($assembled->path, strlen($this->root));
        $copy = $backend->copyObject($legacySource, 'bucket', 'copy');
        self::assertSame('part-data', buffer($backend->getObjectByPath($copy->path)));
    }

    public function test_multiple_legacy_root_separators_fail_closed_before_fenced_completion_dispatch(): void
    {
        try {
            $this->backend($this->root . '//');
            self::fail('A root with multiple trailing separators must require offline migration.');
        } catch (\InvalidArgumentException) {
        }

        $backend = $this->backend($this->root . '/');
        $completion = $backend->planMultipartCompletionPaths('bucket', 'key', str_repeat('a', 32), str_repeat('b', 32));
        $this->expectException(\InvalidArgumentException::class);
        $backend->assembleMultipartUploadFencedAtPath('bucket', 'key', str_repeat('a', 32), str_repeat('b', 32), str_repeat('c', 32), [[
            'partNumber' => 1,
            'etag' => md5('part'),
            'size' => 4,
            'storagePath' => $this->root . '///.parts/' . str_repeat('c', 32) . '/1-' . str_repeat('d', 32),
        ]], $completion['assembly']);
    }

    public function test_legacy_path_garbage_is_acknowledged_only_after_safe_physical_delete(): void
    {
        $backend = $this->backend($this->root . '/');
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend));
        $object = $backend->putObject('bucket', 'garbage', new ReadableBuffer('garbage-data'));
        $legacy = $this->root . '/' . substr($object->path, strlen($this->root));
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $legacy);

        self::assertSame(1, $collector->collect(1));
        self::assertFileDoesNotExist($object->path);
        self::assertSame([], $metadata->dequeueStorageGarbage(1));

        $backend->createBucket('other-bucket');
        $other = $backend->putObject('other-bucket', 'retained', new ReadableBuffer('retained-data'));
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $this->root . '/' . substr($other->path, strlen($this->root)));
        self::assertSame(0, $collector->collect(1));
        self::assertSame('retained-data', file_get_contents($other->path));
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $rows = $connection->query('SELECT status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $rows);
        self::assertSame(['status' => 'pending', 'attempts' => 1], $rows->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_direct_copy_cannot_read_a_source_outside_the_storage_root(): void
    {
        $backend = $this->backend();
        symlink($this->outside, $this->root . '/bucket/copy-link');

        foreach ([$this->outside, $this->root . '/bucket/copy-link'] as $path) {
            try {
                $backend->copyObject($path, 'bucket', 'copy');
                self::fail('An out-of-root copy source must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame('outside-canary', file_get_contents($this->outside));
            }
        }
    }

    public function test_multipart_assembly_cannot_read_a_part_outside_the_storage_root(): void
    {
        $backend = $this->backend();

        try {
            $backend->assembleMultipartUpload('bucket', 'assembled', str_repeat('a', 32), [[
                'partNumber' => 1,
                'etag' => md5('outside-canary'),
                'storagePath' => $this->outside,
            ]]);
            self::fail('An out-of-root multipart part must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame('outside-canary', file_get_contents($this->outside));
        }
    }

    public function test_planned_assembly_cannot_write_to_another_bucket_scope(): void
    {
        $backend = $this->backend();
        $paths = $backend->planMultipartCompletionPaths('other-bucket', 'key', str_repeat('a', 32), str_repeat('b', 32));

        $this->expectException(\InvalidArgumentException::class);
        $backend->assembleMultipartUploadAtPath('bucket', 'key', str_repeat('c', 32), [], $paths['assembly']);
    }

    public function test_multipart_assembly_cannot_read_a_part_from_another_upload(): void
    {
        $backend = $this->backend();
        $part = $backend->putPart('bucket', 'key', str_repeat('a', 32), 1, new ReadableBuffer('other-upload'));

        $this->expectException(\InvalidArgumentException::class);
        $backend->assembleMultipartUpload('bucket', 'key', str_repeat('b', 32), [[
            'partNumber' => 1,
            'etag' => $part->md5Hex,
            'storagePath' => $part->path,
        ]]);
    }

    public function test_delete_cannot_remove_another_buckets_object_within_the_storage_root(): void
    {
        $backend = $this->backend();
        $backend->createBucket('other-bucket');
        $object = $backend->putObject('other-bucket', 'private', new ReadableBuffer('private-data'));

        try {
            $backend->deleteObjectByPath($object->path, 'bucket');
            self::fail('A path in another bucket must be rejected before deletion.');
        } catch (\InvalidArgumentException) {
            self::assertSame('private-data', file_get_contents($object->path));
        }
    }

    public function test_direct_bucket_names_cannot_traverse_into_another_namespace(): void
    {
        $backend = $this->backend();
        $malformed = 'bucket/../other-bucket';
        self::assertFalse($backend->bucketExists('__health_check__'));

        try {
            $backend->createBucket($malformed);
            self::fail('A direct bucket create must reject a path segment.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($this->root . '/other-bucket');
        }

        try {
            $backend->putObject($malformed, 'key', new ReadableBuffer('secret'));
            self::fail('A direct object write must reject a bucket path segment.');
        } catch (\InvalidArgumentException) {
            self::assertDirectoryDoesNotExist($this->root . '/other-bucket');
        }
    }

    private function backend(?string $root = null): FilesystemBackend
    {
        $filesystem = new Filesystem(new BlockingFilesystemDriver());

        return new FilesystemBackend($root ?? $this->root, $filesystem, $filesystem);
    }
}
