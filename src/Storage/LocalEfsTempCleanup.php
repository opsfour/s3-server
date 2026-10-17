<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;

/** Blocking stale-temp sweep confined to an optional EFS process worker. */
final readonly class LocalEfsTempCleanup
{
    public function __construct(
        private string $root,
        private EfsHostEvidenceReader $evidence,
    ) {
        if ($root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Guarded temp cleanup root differs from EFS evidence.');
        }
    }

    public function run(int $maxAgeSeconds): int
    {
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('Temporary cleanup age must be non-negative.');
        }

        $this->evidence->assertFresh();
        $directory = $this->root . '/.tmp';
        if (is_link($directory)) {
            throw new \RuntimeException('EFS temporary directory is unsafe.');
        }
        if (! is_dir($directory)) {
            // A failed is_dir() can be an NFS I/O error. Only a successful
            // listing of the root proves the directory is absent.
            $this->evidence->assertFresh();
            $rootEntries = @scandir($this->root);
            if ($rootEntries === false || in_array('.tmp', $rootEntries, true)) {
                throw new \RuntimeException('EFS temporary directory is unavailable.');
            }
            $this->evidence->assertFresh();
            return 0;
        }
        $this->evidence->assertFresh();
        if (realpath($directory) !== $directory) {
            throw new \RuntimeException('EFS temporary directory is unsafe.');
        }
        $this->evidence->assertFresh();
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('EFS temporary directory is unavailable.');
        }

        $cleaned = 0;
        $cutoff = time() - $maxAgeSeconds;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->evidence->assertFresh();
            // Multipart stages use one token; the guarded Object writer uses
            // its planned attempt ID plus an independent random token.
            if (preg_match('/^[a-f0-9]{32}(?:-[a-f0-9]{32})?$/D', $entry) !== 1) {
                throw new \RuntimeException('Unexpected EFS temporary path blocks cleanup.');
            }
            $path = $directory . '/' . $entry;
            $before = @lstat($path);
            if ($before === false) {
                throw new \RuntimeException('EFS temporary file identity is unavailable.');
            }
            if (! is_int($before['mode'] ?? null) || ($before['mode'] & 0o170000) !== 0o100000) {
                throw new \RuntimeException('EFS temporary path is not a regular file.');
            }
            $this->evidence->assertFresh();
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('EFS temporary file cannot be opened.');
            }
            try {
                $this->evidence->assertFresh();
                // An active writer holds a shared lease until publication.
                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $this->evidence->assertFresh();
                $opened = fstat($handle);
                if ($opened === false || ! self::sameFile($before, $opened)) {
                    throw new \RuntimeException('EFS temporary file identity changed.');
                }
                if (! function_exists('posix_geteuid') || ($opened['mode'] & 0o7777) !== 0o600
                    || $opened['uid'] !== posix_geteuid()) {
                    throw new \RuntimeException('EFS temporary file must be private and process-owned.');
                }
                if (($opened['mtime'] ?? 0) >= $cutoff) {
                    continue;
                }
                $this->evidence->assertFresh();
                $current = @lstat($path);
                if ($current === false || ! self::sameFile($opened, $current)) {
                    throw new \RuntimeException('EFS temporary file identity changed before deletion.');
                }
                $this->evidence->assertFresh();
                if (! @unlink($path)) {
                    throw new \RuntimeException('EFS temporary file deletion is uncertain.');
                }
                $this->evidence->assertFresh();
                $cleaned++;
            } finally {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        $this->evidence->assertFresh();
        return $cleaned;
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    private static function sameFile(array $left, array $right): bool
    {
        return is_int($left['dev'] ?? null) && is_int($left['ino'] ?? null)
            && ($left['dev'] === ($right['dev'] ?? null))
            && ($left['ino'] === ($right['ino'] ?? null));
    }
}
