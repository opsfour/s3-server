<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Creates the underlying stream only when a consumer performs its first read.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class LazyReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private ?ReadableStream $inner = null;

    private bool $closed = false;

    private bool $pending = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param \Closure(): ReadableStream $factory */
    public function __construct(private readonly \Closure $factory) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        if ($this->pending) {
            throw new PendingReadError();
        }

        $this->pending = true;
        try {
            $cancellation?->throwIfRequested();
            $this->inner ??= ($this->factory)();
            $chunk = $this->inner->read($cancellation);
            if ($chunk === null) {
                $this->close();
            }

            return $chunk;
        } catch (\Throwable $error) {
            try {
                $this->close();
            } catch (\Throwable) {
            }
            throw $error;
        } finally {
            $this->pending = false;
        }
    }

    public function isReadable(): bool
    {
        return ! $this->closed && ($this->inner?->isReadable() ?? true);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        try {
            $this->inner?->close();
        } finally {
            $callbacks = $this->onCloseCallbacks;
            $this->onCloseCallbacks = [];
            foreach ($callbacks as $callback) {
                $callback();
            }
        }
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
