<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use PHPUnit\Framework\TestCase;

final class LimitedReadableStreamTest extends TestCase
{
    public function test_large_resource_budget_still_reads_in_small_chunks(): void
    {
        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, str_repeat('x', 200_000));
        rewind($resource);

        $stream = new LimitedReadableStream(new ReadableResourceStream($resource), 100_000);
        $firstChunk = $stream->read();
        self::assertNotNull($firstChunk);
        self::assertSame(ReadableResourceStream::DEFAULT_CHUNK_SIZE, strlen($firstChunk));

        $total = strlen($firstChunk);
        while (($chunk = $stream->read()) !== null) {
            self::assertLessThanOrEqual(ReadableResourceStream::DEFAULT_CHUNK_SIZE, strlen($chunk));
            $total += strlen($chunk);
        }

        self::assertSame(100_000, $total);
        self::assertTrue($stream->isClosed());
    }

    public function test_exact_mode_rejects_early_eof_and_closes_source(): void
    {
        $stream = new LimitedReadableStream(new ReadableBuffer('a'), 2, requireExactLength: true);
        self::assertSame('a', $stream->read());

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored object size does not match metadata.');
        try {
            $stream->read();
        } finally {
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_default_mode_still_accepts_early_eof(): void
    {
        $stream = new LimitedReadableStream(new ReadableBuffer('a'), 2);
        self::assertSame('a', \Amp\ByteStream\buffer($stream));
        self::assertTrue($stream->isClosed());
    }

    public function test_exact_mode_rejects_empty_backend_chunks(): void
    {
        $source = $this->createMock(ReadableStream::class);
        $source->expects(self::once())->method('read')->willReturn('');
        $source->expects(self::once())->method('close');
        $stream = new LimitedReadableStream($source, 1, requireExactLength: true);

        $this->expectException(InternalErrorException::class);
        try {
            $stream->read();
        } finally {
            self::assertTrue($stream->isClosed());
        }
    }
}
