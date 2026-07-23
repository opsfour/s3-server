<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Parallel\TemporaryFileTask;

/**
 * Reads a temporary file in stateless chunks so no worker remains reserved.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class SpoolReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private const int CHUNK_SIZE = 65_536;

    private int $offset = 0;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    public function __construct(
        private readonly WorkerPool $pool,
        private readonly string $path,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        $chunk = $this->pool->submit(
            new TemporaryFileTask('read', $this->path, offset: $this->offset, length: self::CHUNK_SIZE),
            $cancellation,
        )->await($cancellation);

        if ($chunk === null) {
            $this->close();

            return null;
        }

        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
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
