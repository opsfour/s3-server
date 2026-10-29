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
    private const int REGULAR_FILE_MODE = 0o100000;

    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public static function acquire(string $coordinatorRoot, string $leasePath, string $stagePath, string $nonce): self
    {
        $canonicalCoordinatorRoot = realpath($coordinatorRoot);
        $leaseDir = is_string($canonicalCoordinatorRoot)
            ? $canonicalCoordinatorRoot . '/.opsfour-s3-storage-budget'
            : '';
        if ($canonicalCoordinatorRoot === false || $canonicalCoordinatorRoot !== rtrim($coordinatorRoot, DIRECTORY_SEPARATOR)
            || ! str_starts_with($stagePath, '/')
            || dirname($leasePath) !== $leaseDir
            || preg_match('/^lease-[a-f0-9]{32}$/D', basename($leasePath)) !== 1
            || realpath($leaseDir) !== $leaseDir) {
            throw new \InvalidArgumentException('Worker reservation is outside the canonical coordinator root.');
        }

        $handle = self::openPrivateRegularFile($leasePath, 'Worker staging reservation', 'r+b');
        try {
            if (! @flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Worker staging reservation cannot be held.');
            }
            $registry = self::readPrivateJson($leaseDir . '/namespaces', 'Worker namespace registry');
            $statePath = $leaseDir . '/state-' . substr(basename($leasePath), 6);
            $state = self::readPrivateJson($statePath, 'Worker staging reservation state');
            $namespace = $state['namespace'] ?? null;
            $entry = is_string($namespace) ? ($registry[$namespace] ?? null) : null;
            $root = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            $rootIdentity = is_array($entry) ? $entry : null;
            $canonicalStageDirectory = realpath(dirname($stagePath));
            if (! is_string($namespace) || ! is_string($root) || $canonicalStageDirectory !== $root
                || $stagePath !== $root . DIRECTORY_SEPARATOR . basename($stagePath)
                || ($state['relativePath'] ?? null) !== basename($stagePath)
                || ! is_string($state['nonce'] ?? null) || ! hash_equals($state['nonce'], $nonce)
                || ! is_int($state['bytes'] ?? null) || $state['bytes'] < 0
                || ! is_int($state['device'] ?? null) || ! is_int($state['inode'] ?? null)
                || ! is_int($state['owner'] ?? null)) {
                throw new \RuntimeException('Worker staging reservation does not match its file.');
            }
            $rootInfo = @lstat($root);
            if ($rootInfo === false || ($rootInfo['mode'] & 0o170000) !== 0o040000
                || ($rootInfo['mode'] & 0o077) !== 0 && $namespace !== 'node'
                || ($rootIdentity !== null && (
                    ($rootIdentity['device'] ?? null) !== $rootInfo['dev']
                    || ($rootIdentity['inode'] ?? null) !== $rootInfo['ino']
                    || ($rootIdentity['owner'] ?? null) !== $rootInfo['uid']
                    || ($rootIdentity['mode'] ?? null) !== $rootInfo['mode']
                ))) {
                throw new \RuntimeException('Worker staging namespace identity changed.');
            }
            $stageInfo = @lstat($stagePath);
            if ($stageInfo === false || ($stageInfo['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
                || $stageInfo['dev'] !== $state['device'] || $stageInfo['ino'] !== $state['inode']
                || $stageInfo['uid'] !== $state['owner'] || $stageInfo['size'] > $state['bytes']) {
                throw new \RuntimeException('Worker staging reservation identity changed.');
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

    /** @return resource */
    private static function openPrivateRegularFile(string $path, string $purpose, string $mode)
    {
        $before = @lstat($path);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($before === false || ($before['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
            || ($before['mode'] & 0o077) !== 0 || ($owner !== null && $before['uid'] !== $owner)) {
            throw new \RuntimeException("{$purpose} is unavailable.");
        }
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("{$purpose} cannot be opened.");
        }
        $opened = @fstat($handle);
        if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']
            || ($opened['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
            || ($opened['mode'] & 0o077) !== 0 || ($owner !== null && $opened['uid'] !== $owner)) {
            @fclose($handle);
            throw new \RuntimeException("{$purpose} cannot be opened safely.");
        }

        return $handle;
    }

    /** @return array<string,mixed> */
    private static function readPrivateJson(string $path, string $purpose): array
    {
        $handle = self::openPrivateRegularFile($path, $purpose, 'rb');
        try {
            $raw = stream_get_contents($handle);
        } finally {
            @fclose($handle);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            throw new \RuntimeException("{$purpose} is invalid.");
        }

        return $decoded;
    }
}
