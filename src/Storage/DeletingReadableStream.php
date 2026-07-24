<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Deletes a temporary file when its stream is closed or reaches EOF.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class DeletingReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    public function __construct(
        private readonly ReadableStream $inner,
        private readonly string $path,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        $chunk = $this->inner->read($cancellation);
        if ($chunk === null) {
            $this->close();
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        return ! $this->closed && $this->inner->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->inner->close();
        @unlink($this->path);

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
