<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\TeeReadableStream;
use PHPUnit\Framework\TestCase;

final class ReadableStreamLifecycleTest extends TestCase
{
    public function test_limited_stream_closes_source_at_exact_limit(): void
    {
        $source = new RecordingReadableStream(['four']);
        $stream = new LimitedReadableStream($source, 4);

        self::assertSame('four', $stream->read());
        self::assertTrue($stream->isClosed());
        self::assertTrue($source->isClosed());
        self::assertNull($stream->read());
    }

    public function test_zero_length_limited_stream_closes_source_on_first_read(): void
    {
        $source = new RecordingReadableStream(['unused']);
        $stream = new LimitedReadableStream($source, 0);

        self::assertNull($stream->read());
        self::assertTrue($source->isClosed());
    }

    public function test_tee_stream_closes_source_at_eof(): void
    {
        $source = new RecordingReadableStream(['data']);
        $chunks = [];
        $stream = new TeeReadableStream(
            $source,
            static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        self::assertSame('data', $stream->read());
        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
        self::assertTrue($source->isClosed());
        self::assertSame(['data'], $chunks);
    }
}

/**
 * @implements \IteratorAggregate<int, string>
 */
final class RecordingReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param list<string> $chunks */
    public function __construct(private array $chunks) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed || $this->chunks === []) {
            return null;
        }

        return array_shift($this->chunks);
    }

    public function isReadable(): bool
    {
        return ! $this->closed && $this->chunks !== [];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        foreach ($this->onCloseCallbacks as $callback) {
            $callback();
        }
        $this->onCloseCallbacks = [];
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

        $this->onCloseCallbacks[] = $onClose;
    }
}
