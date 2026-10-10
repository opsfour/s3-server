<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/**
 * Internal, node-local scratch prerequisite for a future EFS candidate.
 * This value does not admit an EFS server or authorize physical writes.
 * Every filesystem syscall here targets a path whose mount is first checked
 * using local /proc data; no storage-root syscall is made.
 */
final class EfsNodeLocalScratch
{
    public const int BUDGET_BYTES = 10_737_418_240;

    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    private bool $revoked = false;

    private function __construct(
        private readonly EfsQualificationContext $context,
        private readonly string $path,
        private readonly int $device,
        private readonly int $inode,
        private readonly string $mountFingerprint,
        Closure $mountInfoReader,
    ) {
        $this->mountInfoReader = $mountInfoReader;
    }

    /** @param (Closure(): (string|false))|null $mountInfoReader */
    public static function qualify(
        EfsQualificationContext $context,
        string $path,
        ?Closure $mountInfoReader = null,
    ): self {
        // A concurrent mount replacement can race even the local mountinfo
        // check. Qualification may block in that case, so never run it in an
        // API request fiber; the future factory must do this before serving.
        if (\Fiber::getCurrent() !== null) {
            throw new \RuntimeException('EFS scratch qualification must run before API fibers start.');
        }
        $mountInfoReader ??= self::nativeMountInfo(...);
        $context->assertFresh();
        $scope = $context->workerScope();
        if ($path === $scope->storageRoot || str_starts_with($path, $scope->storageRoot . '/')) {
            throw new \RuntimeException('EFS scratch must be outside the storage root.');
        }

        [$device, $inode, $mountFingerprint] = self::inspect($path, $mountInfoReader);
        if ((string) $device === $scope->storageDev) {
            throw new \RuntimeException('EFS scratch must use a separate node-local device.');
        }

        // The coordinator records one byte limit under this directory and
        // rejects another process that attempts a different active limit.
        new StorageTempBudgetCoordinator($path, self::BUDGET_BYTES);
        $context->assertFresh();
        if (self::inspect($path, $mountInfoReader) !== [$device, $inode, $mountFingerprint]) {
            throw new \RuntimeException('EFS scratch identity changed during qualification.');
        }

        return new self($context, $path, $device, $inode, $mountFingerprint, $mountInfoReader);
    }

    public function assertFresh(): void
    {
        if (\Fiber::getCurrent() !== null) {
            throw new \RuntimeException('EFS scratch inspection must run outside API fibers.');
        }
        if ($this->revoked) {
            throw new \RuntimeException('EFS scratch qualification was revoked.');
        }
        try {
            $this->context->assertFresh();
            if (self::inspect($this->path, $this->mountInfoReader) !== [$this->device, $this->inode, $this->mountFingerprint]) {
                throw new \RuntimeException('EFS scratch identity changed.');
            }
            $this->context->assertFresh();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('EFS scratch qualification is unavailable or invalid.');
        }
    }

    public function path(): string
    {
        $this->assertFresh();

        return $this->path;
    }

    public function budgetBytes(): int
    {
        $this->assertFresh();

        return self::BUDGET_BYTES;
    }

    /** Bind this local scratch to the exact qualification that created it. */
    public function assertBoundTo(EfsQualificationContext $context): void
    {
        if ($this->context !== $context) {
            throw new \RuntimeException('EFS scratch belongs to a different qualification context.');
        }
        $this->assertFresh();
    }

    /** @param Closure(): (string|false) $mountInfoReader
     *  @return array{int, int, string}
     */
    private static function inspect(string $path, Closure $mountInfoReader): array
    {
        if ($path === '/' || ! str_starts_with($path, '/') || str_ends_with($path, '/')
            || preg_match('~//|/(?:\.|\.\.)(?:/|$)|[\x00-\x1f\x7f]~', $path) === 1) {
            throw new \RuntimeException('EFS scratch requires a canonical absolute path.');
        }
        $before = self::readMountInfo($mountInfoReader);
        $current = '';
        foreach (explode('/', substr($path, 1)) as $component) {
            $current .= '/' . $component;
            $type = LinuxMountInfo::filesystemTypeForPath($current, $before);
            if ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type)) {
                throw new \RuntimeException('EFS scratch path is not on a qualified node-local filesystem.');
            }
        }
        // Mount checks precede every lstat, including for ancestors: a path
        // below NFS must never enter a hard-NFS syscall on an API fiber.
        $current = '';
        $components = explode('/', substr($path, 1));
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : false;
        if (! is_int($uid)) {
            throw new \RuntimeException('EFS scratch requires process UID support.');
        }
        $entry = false;
        foreach ($components as $index => $component) {
            $current .= '/' . $component;
            clearstatcache(true, $current);
            $entry = @lstat($current);
            if (! is_array($entry) || ! is_int($entry['mode'] ?? null)
                || ($entry['mode'] & 0o170000) !== 0o040000
                || ! is_int($entry['uid'] ?? null)) {
                throw new \RuntimeException('EFS scratch path must be symlink-free directories.');
            }
            $mode = $entry['mode'] & 0o7777;
            if ($index === array_key_last($components)) {
                if ($entry['uid'] !== $uid || $mode !== 0o700
                    || ! is_int($entry['dev'] ?? null) || ! is_int($entry['ino'] ?? null)) {
                    throw new \RuntimeException('EFS scratch must be private, writable and process-owned.');
                }
            } elseif ($entry['uid'] !== 0 && $entry['uid'] !== $uid) {
                throw new \RuntimeException('EFS scratch parent ownership is unsafe.');
            } elseif (($mode & 0o022) !== 0 && ! ($entry['uid'] === 0 && ($mode & 0o1000) !== 0)) {
                throw new \RuntimeException('EFS scratch parent permissions are unsafe.');
            }
        }
        $after = self::readMountInfo($mountInfoReader);
        if ($before !== $after) {
            throw new \RuntimeException('EFS scratch mount changed during qualification.');
        }

        return [$entry['dev'], $entry['ino'], hash('sha256', $before)];
    }

    /** @param Closure(): (string|false) $reader */
    private static function readMountInfo(Closure $reader): string
    {
        $info = $reader();
        if (! is_string($info) || $info === '' || strlen($info) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('EFS scratch mount information is unavailable.');
        }

        return $info;
    }

    private static function nativeMountInfo(): string|false
    {
        return @file_get_contents('/proc/self/mountinfo', false, null, 0, self::MAX_MOUNTINFO_BYTES + 1);
    }
}
