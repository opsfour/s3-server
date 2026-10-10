<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** @internal */
final class StorageTempReservation
{
    /** @param resource $handle */
    public function __construct(
        public readonly string $leasePath,
        public readonly string $tempPath,
        private $handle,
    ) {}

    public function close(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
