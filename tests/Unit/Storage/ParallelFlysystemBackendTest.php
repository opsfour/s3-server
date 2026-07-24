<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
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

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @return list<string> */
    private static function stagingFiles(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);

        return array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
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
