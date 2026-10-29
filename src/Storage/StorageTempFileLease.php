<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\InternalErrorException;

/** Holds a cross-process lock while a filesystem temporary object is active. */
final class StorageTempFileLease
{
    /** @param resource $handle */
    private function __construct(
        private $handle,
        private readonly int $device,
        private readonly int $inode,
        private readonly int $owner,
    ) {}

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

        $opened = fstat($handle);
        if ($opened === false) {
            fclose($handle);
            @unlink($path);
            throw new InternalErrorException('Failed to identify storage temporary file.');
        }

        return new self($handle, $opened['dev'], $opened['ino'], $opened['uid']);
    }

    public static function acquireExisting(string $path): self
    {
        $before = @lstat($path);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($before === false || ($before['mode'] & 0o170000) !== 0o100000
            || ($before['mode'] & 0o077) !== 0 || ($owner !== null && $before['uid'] !== $owner)) {
            throw new InternalErrorException('Storage temporary file is unsafe.');
        }
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw new InternalErrorException('Failed to open storage temporary file.');
        }
        $opened = @fstat($handle);
        if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
            || ! flock($handle, LOCK_SH | LOCK_NB)) {
            fclose($handle);
            throw new InternalErrorException('Failed to lock storage temporary file.');
        }

        return new self($handle, $opened['dev'], $opened['ino'], $opened['uid']);
    }

    /** @return array{device:int,inode:int,owner:int} */
    public function identity(): array
    {
        return [
            'device' => $this->device,
            'inode' => $this->inode,
            'owner' => $this->owner,
        ];
    }

    public function assertPath(string $path): void
    {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if ($entry === false || ($entry['mode'] & 0o170000) !== 0o100000
            || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== $this->owner
            || $entry['dev'] !== $this->device || $entry['ino'] !== $this->inode) {
            throw new InternalErrorException('Storage temporary file changed while opening.');
        }
    }

    /** @param array{device:int,inode:int,owner:int} $identity */
    public function assertIdentity(array $identity): void
    {
        if ($identity['device'] !== $this->device
            || $identity['inode'] !== $this->inode
            || $identity['owner'] !== $this->owner) {
            throw new InternalErrorException('Storage temporary file changed before opening.');
        }
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
