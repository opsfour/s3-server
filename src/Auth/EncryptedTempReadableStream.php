<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use OpsFour\S3Server\Exception\InternalErrorException;

/**
 * Reads and authenticates an ephemeral encrypted spool one frame at a time.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class EncryptedTempReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private string $buffer = '';

    private int $readBytes = 0;

    private int $frameIndex = 0;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    public function __construct(
        private readonly ReadableStream $source,
        private readonly string $key,
        private readonly int $expectedPlainBytes,
    ) {
        if (strlen($key) !== 32 || $expectedPlainBytes < 0) {
            throw new \InvalidArgumentException('Invalid encrypted spool reader.');
        }
    }

    /** @throws \Amp\CancelledException */
    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        try {
            if ($this->readBytes === $this->expectedPlainBytes) {
                if ($this->readExact(1, $cancellation, allowEof: true) !== null) {
                    throw new InternalErrorException('Temporary spool has trailing data.');
                }
                $this->close();

                return null;
            }

            $header = $this->readExact(EncryptedTempFrames::HEADER_BYTES, $cancellation);
            if ($header === null) {
                throw new InternalErrorException('Temporary spool ended before its expected size.');
            }
            $unpacked = unpack('Nlength', substr($header, 0, 4));
            if ($unpacked === false) {
                throw new InternalErrorException('Temporary spool frame length is invalid.');
            }
            $length = $unpacked['length'];
            if ($length < 1 || $length > EncryptedTempFrames::MAX_PLAINTEXT_BYTES
                || $length > $this->expectedPlainBytes - $this->readBytes) {
                throw new InternalErrorException('Temporary spool frame length is invalid.');
            }
            $ciphertext = $this->readExact($length, $cancellation);
            if ($ciphertext === null) {
                throw new InternalErrorException('Temporary spool frame is truncated.');
            }
            $plaintext = EncryptedTempFrames::decode($this->key, $this->frameIndex++, $header . $ciphertext);
            $this->readBytes += strlen($plaintext);

            return $plaintext;
        } catch (\Throwable $error) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $error,
                $this->close(...),
            );
            throw $error;
        }
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
        /** @var list<callable(): void> $cleanups */
        $cleanups = [$this->source->close(...)];
        foreach ($this->onCloseCallbacks as $callback) {
            $cleanups[] = $callback;
        }
        $this->onCloseCallbacks = [];
        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(null, ...$cleanups);
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

    private function readExact(int $length, ?Cancellation $cancellation, bool $allowEof = false): ?string
    {
        while (strlen($this->buffer) < $length) {
            $chunk = $this->source->read($cancellation);
            if ($chunk === null) {
                if ($this->buffer === '' && $allowEof) {
                    return null;
                }
                throw new InternalErrorException('Temporary spool frame is truncated.');
            }
            if ($chunk === '') {
                throw new InternalErrorException('Temporary spool reader made no progress.');
            }
            $this->buffer .= $chunk;
        }

        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $result;
    }
}
