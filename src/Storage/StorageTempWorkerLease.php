<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Holds the parent's staging reservation while a process worker can still
 * read the file. A recovery sweeper needs an exclusive lease lock before it
 * may remove the stage, so an API-process crash cannot free still-open bytes.
 */
final class StorageTempWorkerLease
{
    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function acquire(string $leasePath, string $stagePath): self
    {
        $scratch = dirname($stagePath);
        $leaseDir = $scratch . '/.opsfour-s3-storage-budget';
        if (! str_starts_with($stagePath, '/')
            || preg_match('/^s3fw-(?:put|part|get|mpu)-[a-f0-9]{64}$/D', basename($stagePath)) !== 1
            || dirname($leasePath) !== $leaseDir
            || preg_match('/^lease-[a-f0-9]{32}$/D', basename($leasePath)) !== 1
            || realpath($scratch) !== $scratch
            || realpath($leaseDir) !== $leaseDir) {
            throw new \InvalidArgumentException('Worker stage and reservation are outside one private scratch directory.');
        }

        $leaseInfo = @lstat($leasePath);
        if ($leaseInfo === false || ($leaseInfo['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException('Worker staging reservation is unavailable.');
        }
        $handle = @fopen($leasePath, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Worker staging reservation cannot be opened.');
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $leaseInfo['dev'] || $opened['ino'] !== $leaseInfo['ino']
                || ! @flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Worker staging reservation cannot be held.');
            }
            $statePath = $leaseDir . '/state-' . substr(basename($leasePath), 6);
            $stateInfo = @lstat($statePath);
            if ($stateInfo === false || ($stateInfo['mode'] & 0o170000) !== 0o100000) {
                throw new \RuntimeException('Worker staging reservation state is unavailable.');
            }
            $raw = @file_get_contents($statePath);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($state) || ($state['tempPath'] ?? null) !== $stagePath
                || ! is_int($state['bytes'] ?? null) || $state['bytes'] < 0) {
                throw new \RuntimeException('Worker staging reservation does not match its file.');
            }

            return new self($handle);
        } catch (\Throwable $error) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            throw $error;
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
    }
}
