<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/** @internal */
final class PostObjectSpoolReservation
{
    private int $bytes = 0;

    /** @param resource $handle */
    public function __construct(
        public readonly string $leasePath,
        public readonly string $tempPath,
        private $handle,
    ) {}

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function setBytes(int $bytes): void
    {
        $this->bytes = $bytes;
    }

    public function isOpen(): bool
    {
        return is_resource($this->handle);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
