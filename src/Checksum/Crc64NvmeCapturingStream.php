<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;

/**
 * Tee a logical plaintext stream into CRC64NVME and require a proven EOF.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class Crc64NvmeCapturingStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private readonly Crc64NvmeDigest $digest;

    private bool $eof = false;

    private bool $closed = false;

    public function __construct(private readonly ReadableStream $source)
    {
        $this->digest = new Crc64NvmeDigest();
    }

    public function source(): ReadableStream
    {
        return $this->source;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        $chunk = $this->source->read($cancellation);
        if ($chunk === null) {
            $this->eof = true;

            return null;
        }
        CooperativeCrc64Nvme::update($this->digest, $chunk);

        return $chunk;
    }

    public function finalizeBase64(): string
    {
        if (! $this->eof) {
            throw new \LogicException('Cannot finalize CRC64NVME before the logical stream reaches EOF.');
        }

        return $this->digest->finalizeBase64();
    }

    public function isReadable(): bool
    {
        return ! $this->closed && ! $this->eof && $this->source->isReadable();
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->source->close();
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }
}
