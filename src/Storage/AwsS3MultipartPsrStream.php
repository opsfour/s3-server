<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Psr\Http\Message\StreamInterface;

/** Non-seekable PSR-7 view of a bounded sequential multipart source. */
final class AwsS3MultipartPsrStream implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    private bool $eof = false;

    private bool $closed = false;

    private ?\Closure $armedHeartbeat = null;

    private int $heartbeatIntervalNanos = 0;

    private int $nextHeartbeat = 0;

    public function __construct(
        private readonly ReadableStream $source,
        private readonly int $size,
        private readonly string $sha256Hex,
    ) {
        if ($size < 0 || preg_match('/^[a-f0-9]{64}$/D', $sha256Hex) !== 1) {
            throw new \InvalidArgumentException('AWS multipart stream size must be non-negative.');
        }
    }

    public function sha256Hex(): string
    {
        return $this->sha256Hex;
    }

    public function beginArmedDispatch(\Closure $heartbeat, float $intervalSeconds): void
    {
        if ($this->closed || $this->position !== 0 || $this->armedHeartbeat !== null
            || ! is_finite($intervalSeconds) || $intervalSeconds <= 0.0) {
            throw new \LogicException('AWS multipart stream cannot begin armed dispatch.');
        }
        $source = $this->source instanceof \OpsFour\S3Server\Checksum\Crc64NvmeCapturingStream
            ? $this->source->source()
            : $this->source;
        if ($source instanceof AwsS3MultipartPartsStream) {
            $source->stopUndispatchedRenewal();
        }
        $this->armedHeartbeat = $heartbeat;
        $this->heartbeatIntervalNanos = (int) min(PHP_INT_MAX, ceil($intervalSeconds * 1_000_000_000));
        $this->runArmedHeartbeat(force: true);
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->buffer = '';
        $this->source->close();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function tell(): int
    {
        if ($this->closed) {
            throw new \RuntimeException('AWS multipart stream is closed.');
        }

        return $this->position;
    }

    public function eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new \RuntimeException('AWS multipart stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('AWS multipart stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new \RuntimeException('AWS multipart stream is read-only.');
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function read($length): string
    {
        if ($this->closed || ! is_int($length) || $length < 0) {
            throw new \RuntimeException('Invalid AWS multipart stream read.');
        }
        if ($length === 0 || $this->eof()) {
            return '';
        }
        while (strlen($this->buffer) < $length && ! $this->eof) {
            $this->runArmedHeartbeat();
            $chunk = $this->source->read();
            if ($chunk === null) {
                $this->eof = true;
                break;
            }
            $this->buffer .= $chunk;
        }
        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($result));
        $this->position += strlen($result);
        if ($this->position > $this->size) {
            throw new \RuntimeException('AWS multipart stream exceeded its declared size.');
        }

        return $result;
    }

    public function getContents(): string
    {
        $contents = '';
        while (! $this->eof()) {
            $chunk = $this->read(65_536);
            $contents .= $chunk;
        }

        return $contents;
    }

    /** Prove that the provider consumed exactly the declared payload. */
    public function requireExactEnd(): void
    {
        if ($this->closed || $this->position !== $this->size || $this->buffer !== '') {
            throw new \RuntimeException('AWS multipart stream did not consume its declared size exactly.');
        }
        if ($this->read(1) !== '') {
            throw new \RuntimeException('AWS multipart stream exceeded its declared size.');
        }
    }

    public function getMetadata($key = null): mixed
    {
        $metadata = ['seekable' => false, 'timed_out' => false, 'blocked' => false, 'eof' => $this->eof()];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    private function runArmedHeartbeat(bool $force = false): void
    {
        if ($this->armedHeartbeat === null || (! $force && hrtime(true) < $this->nextHeartbeat)) {
            return;
        }
        ($this->armedHeartbeat)();
        $this->nextHeartbeat = hrtime(true) + $this->heartbeatIntervalNanos;
    }
}
