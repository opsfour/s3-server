<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\InternalErrorException;

/** Holds a cross-process lock while a filesystem temporary object is active. */
final class StorageTempFileLease
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    public static function acquire(string $path): self
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new InternalErrorException('Failed to create storage temporary file.');
        }

        if (!@chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);
            throw new InternalErrorException('Failed to protect storage temporary file.');
        }

        if (!flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            @unlink($path);
            throw new InternalErrorException('Failed to lock storage temporary file.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
