<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Wraps a ReadableStream and limits the total bytes returned.
 *
 * Used for S3 range-GET requests where the underlying file has been
 * seeked to the correct offset and we need to cap the number of
 * bytes delivered to the client.
 *
 * Once the byte limit is reached, read() returns null (signalling
 * end-of-stream) and the inner stream is closed.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class LimitedReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private int $remaining;

    private bool $closed = false;

    /** @var list<\Closure():void> */
    private array $onCloseCallbacks = [];

    /**
     * @param  ReadableStream  $inner  The underlying stream to read from.
     * @param  int  $limit  Maximum number of bytes to return.
     */
    public function __construct(
        private readonly ReadableStream $inner,
        int $limit,
    ) {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be non-negative, got ' . $limit);
        }

        $this->remaining = $limit;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        if ($this->remaining <= 0) {
            $this->close();

            return null;
        }

        $chunk = $this->inner->read($cancellation);

        if ($chunk === null) {
            $this->close();

            return null;
        }

        $chunkLen = strlen($chunk);

        if ($chunkLen > $this->remaining) {
            $chunk = substr($chunk, 0, $this->remaining);
            $this->remaining = 0;
            $this->close();

            return $chunk;
        }

        $this->remaining -= $chunkLen;
        if ($this->remaining === 0) {
            $this->close();
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        if ($this->closed || $this->remaining <= 0) {
            return false;
        }

        return $this->inner->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->inner->close();

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

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }
}
