<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await as awaitFutures;

final class FlysystemBackendTest extends TestCase
{
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
            rmdir($this->tempDir);
        }
    }

    public function test_put_get_delete_round_trip_uses_flysystem_streams(): void
    {
        $this->backend->createBucket('bucket');

        $result = $this->backend->putObject('bucket', 'path/object.txt', new ReadableBuffer('hello remote'));

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

    public function test_multipart_assembly_uses_configured_temp_dir_and_cleans_up_parts(): void
    {
        $this->backend->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));

        $part1 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 1, new ReadableBuffer('abc'));
        $part2 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 2, new ReadableBuffer('def'));

        $before = scandir($this->tempDir);
        $result = $this->backend->assembleMultipartUpload('bucket', 'large.bin', $uploadId, [
            ['partNumber' => 1, 'etag' => $part1->md5Hex],
            ['partNumber' => 2, 'etag' => $part2->md5Hex],
        ]);
        $after = scandir($this->tempDir);

        self::assertSame($before, $after);
        self::assertSame('abcdef', self::readAll($this->backend->getObjectByPath($result->path)));
        self::assertSame(6, $result->size);
        self::assertStringEndsWith('-2', $result->md5Hex);
        self::assertSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, ".parts/{$uploadId}/"),
        )));
    }

    public function test_copy_object_reads_and_writes_through_flysystem(): void
    {
        $this->backend->createBucket('bucket');
        $source = $this->backend->putObject('bucket', 'source.txt', new ReadableBuffer('copy me'));

        $copy = $this->backend->copyObject($source->path, 'bucket', 'copy.txt');

        self::assertSame('copy me', self::readAll($this->backend->getObjectByPath($copy->path)));
        self::assertSame(2, $this->adapter->writeStreamCalls);
    }

    public function test_remote_write_failure_is_reported_and_does_not_leave_partial_file(): void
    {
        $this->adapter->failNextWriteStreamReason = 'simulated remote outage';

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('simulated remote outage');

        try {
            $this->backend->putObject('bucket', 'broken.bin', new ReadableBuffer('payload'));
        } finally {
            self::assertSame([], $this->adapter->paths());
        }
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

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
