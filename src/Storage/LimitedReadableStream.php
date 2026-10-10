<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;

/**
 * Wraps a ReadableStream and limits the total bytes returned.
 *
 * Used to cap bytes read from storage or delivered to an S3 client,
 * including range and full-object reads.
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
        private readonly bool $requireExactLength = false,
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

        // Built-in file and Flysystem streams accept a per-read limit. Ask for
        // only the remaining bytes, although a PHP stream adapter may still
        // prefetch a larger chunk internally before returning them.
        if ($this->inner instanceof FileReadableStream) {
            $chunk = $this->inner->read($cancellation, min($this->remaining, 1_048_576));
        } elseif ($this->inner instanceof ReadableResourceStream) {
            $chunk = $this->inner->read($cancellation, min($this->remaining, ReadableResourceStream::DEFAULT_CHUNK_SIZE));
        } else {
            $chunk = $this->inner->read($cancellation);
        }

        if ($chunk === null) {
            $this->close();

            if ($this->requireExactLength) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored object size does not match metadata.');
            }

            return null;
        }

        $chunkLen = strlen($chunk);

        if ($chunkLen === 0 && $this->requireExactLength) {
            $this->close();
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Stored object size does not match metadata.');
        }

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
