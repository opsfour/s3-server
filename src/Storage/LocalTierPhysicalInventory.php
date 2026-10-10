<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;
use DirectoryIterator;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\LinuxMountInfo;

/**
 * Sequential, read-only physical inventory of a stopped local tier.
 *
 * This is an inventory slice, not a release gate. PHP pathname operations
 * cannot exclude an adversarial concurrent rename or a hard-NFS syscall stall.
 */
final class LocalTierPhysicalInventory
{
    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    private const int HEADER_BYTES = 160;

    private const int MAX_DEPTH = 32;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    /** @var array{point: string, identity: string, type: string} */
    private readonly array $rootMount;

    /** @var array<string, int> */
    private readonly array $rootStat;

    /** @var list<array{path: string, relative: string, iterator: DirectoryIterator, stat: array<string, int>}> */
    private array $stack = [];

    private bool $started = false;

    private bool $complete = false;

    private bool $revoked = false;

    private ?object $eofReceiptClaim = null;

    /**
     * @param Closure(): (string|false)|null $mountInfoReader Test seam; production reads /proc/self/mountinfo.
     */
    public function __construct(
        private readonly string $storageTier,
        private readonly string $root,
        ?Closure $mountInfoReader = null,
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        if ($storageTier === '' || ! str_starts_with($root, '/') || $root === '/'
            || str_ends_with($root, '/') || str_contains($root, "\0") || str_contains($root, '//')
            || preg_match('~/(?:\.|\.\.)($|/)~', $root) === 1) {
            throw new \InvalidArgumentException('Invalid physical inventory scope.');
        }
        $this->mountInfoReader = $mountInfoReader ?? self::nativeMountInfo(...);
        $mounts = $this->mounts();
        $this->rootMount = self::mountFor($root, $mounts);
        if (in_array($this->rootMount['type'], ['nfs', 'nfs4'], true)) {
            if ($efsEvidence === null || $efsEvidence->storageRoot() !== $root) {
                throw new \RuntimeException('NFS inventory requires exact host evidence.');
            }
            $efsEvidence->assertFresh();
        } elseif ($efsEvidence !== null) {
            throw new \RuntimeException('Host evidence does not match a network storage root.');
        } elseif (! LinuxMountInfo::isQualifiedLocalFilesystem($this->rootMount['type'])) {
            throw new \RuntimeException('Unsupported physical inventory filesystem.');
        }
        if (realpath($root) !== $root) {
            throw new \RuntimeException('Physical inventory root must be canonical and symlink-free.');
        }
        $this->rootStat = $this->safeStat($root, true);
    }

    public function rootPathSha256(): string
    {
        return hash('sha256', $this->root);
    }

    public function rootInodeSha256(): string
    {
        return hash('sha256', $this->rootStat['dev'] . ':' . $this->rootStat['ino']);
    }

    /** Reserve a fresh scanner exactly once before any receipt traversal. */
    public function claimForEofReceipt(): object
    {
        if ($this->started || $this->complete || $this->revoked || $this->eofReceiptClaim !== null) {
            throw new \RuntimeException('Physical inventory is not fresh for an EOF receipt.');
        }
        $claim = new \stdClass();
        $this->eofReceiptClaim = $claim;
        try {
            $this->assertPageBoundary();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('Physical inventory failed closed.');
        }
        return $claim;
    }

    /**
     * @return list<array{storage_tier: string, path_sha256: string, relative_path_sha256: string, multipart_scope_sha256: ?string, type: string, size_bytes: ?int, format: ?string, basename_prefix_class: string}>
     */
    public function nextPage(int $limit = 100): array
    {
        if ($this->eofReceiptClaim !== null) {
            throw new \RuntimeException('Physical inventory is reserved for an EOF receipt.');
        }
        return $this->readPage($limit);
    }

    /**
     * @return list<array{storage_tier: string, path_sha256: string, relative_path_sha256: string, multipart_scope_sha256: ?string, type: string, size_bytes: ?int, format: ?string, basename_prefix_class: string}>
     */
    public function nextReceiptPage(object $claim, int $limit = 100): array
    {
        if ($this->eofReceiptClaim !== $claim || $this->revoked) {
            throw new \RuntimeException('Physical inventory EOF claim is invalid.');
        }
        return $this->readPage($limit);
    }

    /**
     * @return list<array{storage_tier: string, path_sha256: string, relative_path_sha256: string, multipart_scope_sha256: ?string, type: string, size_bytes: ?int, format: ?string, basename_prefix_class: string}>
     */
    private function readPage(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Physical inventory page limit must be 1..500.');
        }
        if ($this->revoked) {
            throw new \RuntimeException('Physical inventory was revoked.');
        }
        if ($this->complete) {
            try {
                // An explicit EOF page must still bind the current mount,
                // root identity and EFS evidence to the completed scan.
                $this->assertPageBoundary();
            } catch (\Throwable) {
                $this->revoked = true;
                throw new \RuntimeException('Physical inventory failed closed.');
            }
            return [];
        }

        try {
            $mounts = $this->assertPageBoundary();
            if (! $this->started) {
                $this->pushDirectory($this->root, '', $mounts);
                $this->started = true;
            }
            $rows = [];
            while (count($rows) < $limit && $this->stack !== []) {
                $index = count($this->stack) - 1;
                $frame = $this->stack[$index];
                $iterator = $frame['iterator'];
                while ($iterator->valid() && $iterator->isDot()) {
                    $iterator->next();
                }
                if (! $iterator->valid()) {
                    $this->assertDirectoryUnchanged($frame);
                    array_pop($this->stack);
                    continue;
                }
                $name = $iterator->getFilename();
                $iterator->next();
                if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
                    throw new \RuntimeException('Invalid physical inventory entry.');
                }
                $relative = $frame['relative'] === '' ? $name : $frame['relative'] . '/' . $name;
                $path = $frame['path'] . '/' . $name;
                $mount = self::mountFor($path, $mounts);
                if ($mount['identity'] !== $this->rootMount['identity']) {
                    throw new \RuntimeException('Nested mount in physical inventory.');
                }
                $entry = $this->safeStat($path, null);
                if ($entry['dev'] !== $this->rootStat['dev']) {
                    throw new \RuntimeException('Physical inventory crossed a device.');
                }
                $isDirectory = ($entry['mode'] & 0o170000) === 0o040000;
                $format = null;
                $size = null;
                if ($isDirectory) {
                    $this->pushDirectory($path, $relative, $mounts, $entry);
                } else {
                    $size = $entry['size'];
                    $format = $this->inspectFile($path, $entry);
                }
                $rows[] = [
                    'storage_tier' => $this->storageTier,
                    // SQL path_sha256 hashes the stored absolute spelling, not realpath().
                    'path_sha256' => hash('sha256', $path),
                    // In particular, legacy abort_multipart hashes ".parts/<uploadId>".
                    'relative_path_sha256' => hash('sha256', $relative),
                    'multipart_scope_sha256' => self::multipartScopeHash($relative),
                    'type' => $isDirectory ? 'directory' : 'file',
                    'size_bytes' => $size,
                    'format' => $format,
                    'basename_prefix_class' => self::basenamePrefixClass($name),
                ];
            }
            $this->assertPageBoundary();
            if ($this->stack === []) {
                $this->complete = true;
            }
            return $rows;
        } catch (\Throwable) {
            $this->revoked = true;
            $this->stack = [];
            // DirectoryIterator and lower-level readers can include raw paths
            // in their messages. Do not expose their exception chain to a
            // report caller or a generic exception logger.
            throw new \RuntimeException('Physical inventory failed closed.');
        }
    }

    /** @return list<array{point: string, identity: string, type: string}> */
    private function assertPageBoundary(): array
    {
        $this->efsEvidence?->assertFresh();
        $mounts = $this->mounts();
        if (self::mountFor($this->root, $mounts) !== $this->rootMount) {
            throw new \RuntimeException('Physical inventory root mount changed.');
        }
        $this->assertSameStat($this->rootStat, $this->safeStat($this->root, true));
        foreach ($this->stack as $frame) {
            if (self::mountFor($frame['path'], $mounts)['identity'] !== $this->rootMount['identity']) {
                throw new \RuntimeException('Physical inventory directory mount changed.');
            }
            $this->assertDirectoryUnchanged($frame);
        }
        return $mounts;
    }

    /** @param list<array{point: string, identity: string, type: string}> $mounts
     *  @param array<string, int>|null $entry
     */
    private function pushDirectory(string $path, string $relative, array $mounts, ?array $entry = null): void
    {
        if (count($this->stack) >= self::MAX_DEPTH
            || self::mountFor($path, $mounts)['identity'] !== $this->rootMount['identity']) {
            throw new \RuntimeException('Invalid physical inventory directory boundary.');
        }
        $entry ??= $this->safeStat($path, true);
        if (($entry['mode'] & 0o170000) !== 0o040000 || ($entry['mode'] & 0o500) !== 0o500) {
            throw new \RuntimeException('Unreadable physical inventory directory.');
        }
        $iterator = new DirectoryIterator($path);
        $this->assertSameStat($entry, $this->safeStat($path, true));
        $this->stack[] = ['path' => $path, 'relative' => $relative, 'iterator' => $iterator, 'stat' => $entry];
    }

    /** @param array{path: string, relative: string, iterator: DirectoryIterator, stat: array<string, int>} $frame */
    private function assertDirectoryUnchanged(array $frame): void
    {
        $this->assertSameStat($frame['stat'], $this->safeStat($frame['path'], true));
    }

    /** @param array<string, int> $before
     *  @param array<string, int> $after
     */
    private function assertSameStat(array $before, array $after): void
    {
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $field) {
            if ($before[$field] !== $after[$field]) {
                throw new \RuntimeException('Physical inventory entry changed.');
            }
        }
    }

    /** @return array<string, int> */
    private function safeStat(string $path, ?bool $directory): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || ! is_int($stat['mode'] ?? null)
            || ! is_int($stat['dev'] ?? null) || ! is_int($stat['ino'] ?? null)
            || ! is_int($stat['size'] ?? null) || $stat['size'] < 0) {
            throw new \RuntimeException('Physical inventory entry is unavailable.');
        }
        $type = $stat['mode'] & 0o170000;
        if (($directory === true && $type !== 0o040000)
            || ($directory === false && $type !== 0o100000)
            || ($directory === null && ! in_array($type, [0o040000, 0o100000], true))) {
            throw new \RuntimeException('Physical inventory entry is not a regular file or directory.');
        }
        /** @var array<string, int> $stat */
        return $stat;
    }

    /** @param array<string, int> $entry */
    private function inspectFile(string $path, array $entry): string
    {
        if (($entry['mode'] & 0o444) === 0) {
            throw new \RuntimeException('Unreadable physical inventory file.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Physical inventory file cannot be opened.');
        }
        try {
            $opened = @fstat($handle);
            if (! is_array($opened)) {
                throw new \RuntimeException('Physical inventory file identity is unavailable.');
            }
            /** @var array<string, int> $opened */
            $this->assertSameStat($entry, $opened);
            $length = min(self::HEADER_BYTES, $entry['size']);
            $header = '';
            while (strlen($header) < $length) {
                $remaining = $length - strlen($header);
                if ($remaining < 1) {
                    throw new \RuntimeException('Physical inventory header length changed.');
                }
                $chunk = fread($handle, $remaining);
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('Physical inventory file header is incomplete.');
                }
                $header .= $chunk;
            }
            $after = @fstat($handle);
            if (! is_array($after)) {
                throw new \RuntimeException('Physical inventory file identity changed.');
            }
            /** @var array<string, int> $after */
            $this->assertSameStat($entry, $after);
        } finally {
            fclose($handle);
        }
        $this->assertSameStat($entry, $this->safeStat($path, false));
        return self::classifyHeader($header, $entry['size']);
    }

    private static function classifyHeader(string $header, int $size): string
    {
        if (str_starts_with($header, "S3MPU2\0")) {
            if ($size < 70 || strlen($header) < 10) {
                throw new \RuntimeException('Invalid multipart ciphertext header.');
            }
            $mode = ord($header[7]);
            $wrapped = unpack('n', substr($header, 8, 2));
            $keyLength = $wrapped[1] ?? -1;
            if (($mode === 1 && ($keyLength < 1 || $keyLength > 512))
                || ($mode === 2 && $keyLength !== 0)
                || ! in_array($mode, [1, 2], true)
                || $size < 70 + $keyLength) {
                throw new \RuntimeException('Invalid multipart ciphertext envelope.');
            }
            return 'multipart_v2_header_unverified';
        }
        foreach (['OBJECT', 'MULTIPART'] as $kind) {
            $prefix = 'OPSFOUR-LOCAL-' . $kind . "-SEAL-V1\n";
            if (str_starts_with($header, $prefix)) {
                if ($size !== strlen($prefix) + 32 + 1 + 64 + 1
                    || preg_match('/^' . preg_quote($prefix, '/') . '[a-f0-9]{32}\n[a-f0-9]{64}\n$/D', $header) !== 1) {
                    throw new \RuntimeException('Invalid local seal shape.');
                }
                return strtolower($kind) . '_seal_shape_unverified';
            }
        }
        return 'opaque_unclassified';
    }

    private static function basenamePrefixClass(string $name): string
    {
        foreach (['s3fw-', 's3-post-', 's3-md5-', 's3-sha256-'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $prefix;
            }
        }
        return 'unknown';
    }

    /** Hash the validated .parts/<uploadId> namespace for its directory and descendants. */
    private static function multipartScopeHash(string $relative): ?string
    {
        $segments = explode('/', $relative, 3);
        if ($segments[0] !== '.parts' || ! isset($segments[1])) {
            return null;
        }
        try {
            StoragePath::assertSafeUploadIdSegment($segments[1]);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return hash('sha256', '.parts/' . $segments[1]);
    }

    /** @return list<array{point: string, identity: string, type: string}> */
    private function mounts(): array
    {
        $raw = ($this->mountInfoReader)();
        if (! is_string($raw) || $raw === '' || strlen($raw) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Physical inventory mount table is unavailable.');
        }
        $mounts = [];
        foreach (explode("\n", trim($raw, "\n")) as $line) {
            $sections = explode(' - ', $line, 2);
            $before = preg_split('/ +/', $sections[0]);
            $after = isset($sections[1]) ? preg_split('/ +/', $sections[1]) : false;
            if (count($sections) !== 2 || $before === false || $after === false
                || count($before) < 6 || count($after) < 3) {
                throw new \RuntimeException('Physical inventory mount table is malformed.');
            }
            $point = self::decodeMountPath($before[4]);
            if ($point === null || ! str_starts_with($point, '/') || $after[0] === '') {
                throw new \RuntimeException('Physical inventory mount path is malformed.');
            }
            $mounts[] = ['point' => $point, 'identity' => hash('sha256', $line), 'type' => strtolower($after[0])];
        }
        return $mounts;
    }

    /** @param list<array{point: string, identity: string, type: string}> $mounts
     *  @return array{point: string, identity: string, type: string}
     */
    private static function mountFor(string $path, array $mounts): array
    {
        $best = null;
        $length = -1;
        foreach ($mounts as $mount) {
            $point = $mount['point'];
            if ($path !== $point && ! str_starts_with($path, rtrim($point, '/') . '/')) {
                continue;
            }
            if (strlen($point) > $length) {
                $best = $mount;
                $length = strlen($point);
            } elseif (strlen($point) === $length) {
                throw new \RuntimeException('Ambiguous physical inventory mount.');
            }
        }
        if ($best === null) {
            throw new \RuntimeException('Physical inventory mount is unknown.');
        }
        return $best;
    }

    private static function decodeMountPath(string $encoded): ?string
    {
        if (preg_match('/\\\\(?!040|011|012|134)/', $encoded) === 1) {
            return null;
        }
        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $encoded);
    }

    private static function nativeMountInfo(): string|false
    {
        $handle = @fopen('/proc/self/mountinfo', 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $raw = stream_get_contents($handle, self::MAX_MOUNTINFO_BYTES + 1);
            return is_string($raw) ? $raw : false;
        } finally {
            fclose($handle);
        }
    }
}
