<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Read one immutable host pin from an exact root-owned local file bind.
 * This listenerless reader does not inspect EFS, SQL, Docker or a socket.
 */
final class ProductionEfsHostPinFileReader
{
    private const int MAX_RECORD_BYTES = 4096;

    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    /** @var Closure(string): (array<array-key, int>|false) */
    private readonly Closure $lstatReader;

    /** @var Closure(resource): (array<array-key, int>|false) */
    private readonly Closure $fstatReader;

    /**
     * @param (Closure(): (string|false))|null $mountInfoReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     */
    public function __construct(
        private readonly string $path,
        ?Closure $mountInfoReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
    ) {
        self::assertAbsolutePath($path);
        if ($mountInfoReader === null && PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Production EFS host pin requires Linux mount information.');
        }
        $this->mountInfoReader = $mountInfoReader ?? self::nativeMountInfo(...);
        $this->lstatReader = $lstatReader ?? self::nativeLstat(...);
        $this->fstatReader = $fstatReader ?? static fn($handle): array|false => @fstat($handle);
    }

    public function read(): ProductionEfsHostPin
    {
        $beforeMountInfo = $this->readMountInfo();
        if (! LinuxMountInfo::isExactReadOnlyLocalFileBind($this->path, $beforeMountInfo)) {
            throw new \RuntimeException('Production EFS host pin requires an exact read-only local file bind.');
        }
        $ancestors = [];
        $prefix = '/';
        foreach (['', ...array_slice(explode('/', substr($this->path, 1)), 0, -1)] as $segment) {
            if ($segment !== '') {
                $prefix = rtrim($prefix, '/') . '/' . $segment;
            }
            $this->assertLocalMount($prefix, $beforeMountInfo);
            $entry = self::requireSafeEntry(($this->lstatReader)($prefix), true);
            $ancestors[$prefix] = self::entryIdentity($entry);
        }
        $this->assertLocalMount($this->path, $beforeMountInfo);
        $beforeEntry = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        $size = $beforeEntry['size'] ?? null;
        if (! is_int($size) || $size < 1 || $size > self::MAX_RECORD_BYTES
            || ($beforeEntry['nlink'] ?? null) !== 1) {
            throw new \RuntimeException('Production EFS host pin file is invalid.');
        }
        $beforeFile = self::entryIdentity($beforeEntry);

        $file = @fopen($this->path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Production EFS host pin file is unavailable.');
        }
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
            if (self::entryIdentity($opened) !== $beforeFile || ($opened['size'] ?? null) !== $size
                || ($opened['nlink'] ?? null) !== 1) {
                throw new \RuntimeException('Production EFS host pin changed while opening.');
            }
            $json = stream_get_contents($file, self::MAX_RECORD_BYTES + 1);
            if (! is_string($json) || strlen($json) !== $size || ! feof($file)) {
                throw new \RuntimeException('Production EFS host pin changed while reading.');
            }
        } finally {
            fclose($file);
        }

        $this->assertLocalMount($this->path, $beforeMountInfo);
        $afterFile = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        if (self::entryIdentity($afterFile) !== $beforeFile || ($afterFile['size'] ?? null) !== $size
            || ($afterFile['nlink'] ?? null) !== 1) {
            throw new \RuntimeException('Production EFS host pin changed while reading.');
        }
        foreach ($ancestors as $ancestor => $before) {
            $this->assertLocalMount($ancestor, $beforeMountInfo);
            $entry = self::requireSafeEntry(($this->lstatReader)($ancestor), true);
            if (self::entryIdentity($entry) !== $before) {
                throw new \RuntimeException('Production EFS host pin parent changed while reading.');
            }
        }
        if ($this->readMountInfo() !== $beforeMountInfo) {
            throw new \RuntimeException('Production EFS host pin mount information changed.');
        }

        return ProductionEfsHostPin::fromJson($json);
    }

    private static function assertAbsolutePath(string $path): void
    {
        if (! str_starts_with($path, '/') || $path === '/' || str_ends_with($path, '/')
            || preg_match('#^/[A-Za-z0-9._/-]+$#D', $path) !== 1) {
            throw new \InvalidArgumentException('Production EFS host pin file path is invalid.');
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Production EFS host pin file path is invalid.');
            }
        }
    }

    private function readMountInfo(): string
    {
        $value = ($this->mountInfoReader)();
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Production EFS host pin mount information is unavailable.');
        }

        return $value;
    }

    private function assertLocalMount(string $path, string $mountInfo): void
    {
        $type = LinuxMountInfo::filesystemTypeForPath($path, $mountInfo);
        if ($type !== 'tmpfs' && ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type))) {
            throw new \RuntimeException('Production EFS host pin path is not on a qualified local mount.');
        }
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>
     */
    private static function requireSafeEntry(array|false $entry, bool $directory): array
    {
        if (! is_array($entry) || ! is_int($entry['mode'] ?? null)
            || ($entry['mode'] & 0o170000) !== ($directory ? 0o040000 : 0o100000)
            || ($entry['mode'] & 0o022) !== 0 || ($entry['uid'] ?? null) !== 0) {
            throw new \RuntimeException('Production EFS host pin ownership or file type is invalid.');
        }

        return $entry;
    }

    /** @param array<array-key, int> $entry
     *  @return array{int, int}
     */
    private static function entryIdentity(array $entry): array
    {
        $dev = $entry['dev'] ?? null;
        $ino = $entry['ino'] ?? null;
        if (! is_int($dev) || $dev < 1 || ! is_int($ino) || $ino < 1) {
            throw new \RuntimeException('Production EFS host pin inode is invalid.');
        }

        return [$dev, $ino];
    }

    private static function nativeMountInfo(): string|false
    {
        $file = @fopen('/proc/self/mountinfo', 'rb');
        if ($file === false) {
            return false;
        }
        try {
            return stream_get_contents($file, self::MAX_MOUNTINFO_BYTES + 1);
        } finally {
            fclose($file);
        }
    }

    /** @return array<array-key, int>|false */
    private static function nativeLstat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }
}
