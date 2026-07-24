<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\File\File;

/**
 * Reads Amp files in transfer-sized chunks instead of the 8 KiB default.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class FileReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    public function __construct(
        private readonly File $file,
        private readonly int $chunkSize = 1_048_576,
    ) {
        if ($this->chunkSize < 1) {
            throw new \InvalidArgumentException('File stream chunk size must be positive.');
        }
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $chunk = $this->file->read($cancellation, $this->chunkSize);
        if ($chunk === null) {
            $this->file->close();
        }

        return $chunk;
    }

    public function isReadable(): bool
    {
        return $this->file->isReadable();
    }

    public function close(): void
    {
        $this->file->close();
    }

    public function isClosed(): bool
    {
        return $this->file->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->file->onClose($onClose);
    }

    public function __destruct()
    {
        try {
            $this->file->close();
        } catch (\Throwable) {
        }
    }
}
