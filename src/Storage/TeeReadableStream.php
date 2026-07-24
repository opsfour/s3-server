<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Wraps a ReadableStream and forks each chunk to a callback.
 *
 * Typical use: compute checksums while simultaneously writing data to disk.
 *
 *     $calculator = new ChecksumCalculator();
 *     $tee = new TeeReadableStream($body, $calculator->update(...));
 *     // Reading from $tee feeds data into both the consumer and the calculator.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class TeeReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure():void> */
    private array $onCloseCallbacks = [];

    /**
     * @param  ReadableStream  $source  The underlying data stream.
     * @param  \Closure(string):void  $onChunk  Callback invoked with each chunk before it is returned.
     */
    public function __construct(
        private readonly ReadableStream $source,
        private readonly \Closure $onChunk,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        $chunk = $this->source->read($cancellation);

        if ($chunk === null) {
            $this->close();

            return null;
        }
        ($this->onChunk)($chunk);

        return $chunk;
    }

    public function isReadable(): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->source->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->source->close();

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
