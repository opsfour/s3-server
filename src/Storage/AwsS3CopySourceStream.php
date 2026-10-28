<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use OpsFour\S3Server\Exception\InternalErrorException;
use Psr\Http\Message\StreamInterface;

/** Bounded adapter for the one exact CopyObject provider source stream.
 * @implements \IteratorAggregate<int, string>
 */
final class AwsS3CopySourceStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    public function __construct(private StreamInterface $body) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        try {
            $cancellation?->throwIfRequested();
            $chunk = $this->body->read(65_536);
            $cancellation?->throwIfRequested();
            if ($chunk === '') {
                if ($this->body->eof()) {
                    $this->close();
                    return null;
                }
                throw new \RuntimeException('Empty provider read.');
            }
            return $chunk;
        } catch (\Amp\CancelledException $error) {
            try {
                $this->close();
            } catch (\Throwable) {
            }
            throw $error;
        } catch (\Throwable) {
            try {
                $this->close();
            } catch (\Throwable) {
            }
            throw new InternalErrorException('AWS copy source read failed.');
        }
    }

    public function isReadable(): bool
    {
        return ! $this->closed && $this->body->isReadable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $failure = null;
        try {
            $this->body->close();
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
