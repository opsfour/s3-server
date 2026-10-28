<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\InternalErrorException;

/** Sequential, integrity-checking stream over exact immutable AWS multipart parts. */
/** @implements \IteratorAggregate<int, string> */
final class AwsS3MultipartPartsStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private int $index = 0;

    private ?ReadableStream $current = null;

    private ?\HashContext $md5 = null;

    private int $bytes = 0;

    private bool $closed = false;

    private bool $renewUndispatched = true;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function __construct(
        private readonly AwsS3ForegroundObjectWriter $writer,
        private readonly AwsS3ForegroundObjectReservation $reservation,
        private readonly BucketInfo $bucket,
        private readonly string $key,
        private readonly string $uploadId,
        private readonly array $parts,
    ) {
        if ($parts === []) {
            throw new \InvalidArgumentException('AWS multipart concatenation requires at least one part.');
        }
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }

        try {
            while (true) {
                if ($this->current === null) {
                    if ($this->index >= count($this->parts)) {
                        $this->close();

                        return null;
                    }
                    $part = $this->parts[$this->index];
                    $stream = $this->writer->readMultipartPart(
                        $this->bucket,
                        $this->key,
                        $this->uploadId,
                        $part['partNumber'],
                        $part['storagePath'],
                        $part['size'],
                        $part['size'],
                    );
                    $this->current = $this->renewUndispatched
                        ? $this->writer->renewingStream($this->reservation, $stream)
                        : $stream;
                    $this->md5 = hash_init('md5');
                    $this->bytes = 0;
                }

                $chunk = $this->current->read($cancellation);
                if ($chunk !== null) {
                    $this->bytes += strlen($chunk);
                    $md5 = $this->md5 ?? throw new \LogicException('AWS multipart checksum context is unavailable.');
                    hash_update($md5, $chunk);

                    return $chunk;
                }

                $part = $this->parts[$this->index];
                $md5 = $this->md5 ?? throw new \LogicException('AWS multipart checksum context is unavailable.');
                $actualMd5 = hash_final($md5);
                $this->md5 = null;
                $this->current->close();
                $this->current = null;
                if ($this->bytes !== $part['size']
                    || ! hash_equals(strtolower(trim($part['etag'], '"')), $actualMd5)) {
                    throw new InternalErrorException('AWS multipart part does not match its committed metadata.');
                }
                ++$this->index;
            }
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
        return ! $this->closed;
    }

    public function stopUndispatchedRenewal(): void
    {
        if ($this->current !== null) {
            throw new \LogicException('AWS multipart provider dispatch began after source reading started.');
        }
        $this->renewUndispatched = false;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $failure = null;
        try {
            $this->current?->close();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $this->current = null;
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
