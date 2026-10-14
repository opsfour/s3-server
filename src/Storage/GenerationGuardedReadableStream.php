<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Revalidates an authorization generation around every observable read.
 *
 * Small source chunks are coalesced before they become observable. This keeps
 * a byte-at-a-time adapter from causing one metadata query per byte while the
 * post-read check still discards every buffered byte when the generation
 * changes during storage I/O. The source-read cap retains event-loop fairness;
 * with one-byte chunks it permits at most one guard pair per 1,024 bytes.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class GenerationGuardedReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private const int MIN_EMIT_BYTES = 65_536;

    private const int MAX_SOURCE_READS_PER_EMIT = 1_024;

    private bool $closed = false;

    private bool $pending = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param \Closure(): void $assertCurrent */
    public function __construct(
        private readonly ReadableStream $inner,
        private readonly \Closure $assertCurrent,
    ) {}

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
            ($this->assertCurrent)();

            $buffer = '';
            $bufferBytes = 0;
            $sourceReads = 0;
            $eof = false;
            while ($bufferBytes < self::MIN_EMIT_BYTES
                && $sourceReads < self::MAX_SOURCE_READS_PER_EMIT) {
                $cancellation?->throwIfRequested();
                $chunk = $this->inner->read($cancellation);
                ++$sourceReads;
                if ($chunk === null) {
                    $eof = true;
                    break;
                }
                if ($chunk === '') {
                    throw new \RuntimeException('Generation-guarded source returned an empty chunk.');
                }
                $buffer .= $chunk;
                $bufferBytes += strlen($chunk);
            }

            // The source read may yield. Do not expose its result, including a
            // clean EOF, until the originally authorized generation is current.
            $cancellation?->throwIfRequested();
            ($this->assertCurrent)();

            if ($eof) {
                $this->close();
            }

            return $buffer === '' ? null : $buffer;
        } catch (\Throwable $error) {
            $this->closeAfterFailure();
            throw $error;
        } finally {
            $this->pending = false;
        }
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
        try {
            $this->inner->close();
        } finally {
            $this->notifyClosed();
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
            // Destructors cannot report a storage-close failure.
        }
    }

    /** Preserve the generation, read or cancellation failure over cleanup. */
    private function closeAfterFailure(): void
    {
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    private function notifyClosed(): void
    {
        $callbacks = $this->onCloseCallbacks;
        $this->onCloseCallbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
