<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Renews one exact undispatched reservation around every source read.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class AwsS3ReservationReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param \Closure(): void $heartbeat */
    public function __construct(
        private readonly ReadableStream $source,
        private readonly \Closure $heartbeat,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        try {
            ($this->heartbeat)();
            $chunk = $this->source->read($cancellation);
            ($this->heartbeat)();
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
        }
    }

    public function isReadable(): bool
    {
        return ! $this->closed && $this->source->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $failure = null;
        try {
            $this->source->close();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $callbacks = $this->onCloseCallbacks;
            $this->onCloseCallbacks = [];
            foreach ($callbacks as $callback) {
                try {
                    $callback();
                } catch (\Throwable $error) {
                    $failure ??= $error;
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
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
}
