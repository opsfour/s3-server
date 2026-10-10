<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Internal ordering gate for a future EFS serve profile; it does not authorize
 * EFS serving. EfsHostEvidenceReader does not implement the local startup
 * capability because its constructor already touches the NFS root.
 */
final class EfsStorageStartupPreflight
{
    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    /** @param (Closure(): (string|false))|null $mountInfoReader */
    public function __construct(
        private readonly EfsLocalStartupEvidence $evidence,
        ?Closure $mountInfoReader = null,
    ) {
        $this->mountInfoReader = $mountInfoReader ?? self::nativeMountInfo(...);
    }

    /** Require an existing canonical NFSv4 root without ever creating it. */
    public function assertExistingRoot(string $storagePath): string
    {
        // This must precede realpath, stat, is_dir and even mountinfo lookup.
        $this->evidence->assertFresh();

        if (! str_starts_with($storagePath, '/') || $storagePath === '/') {
            throw new \RuntimeException('EFS storage root must be an existing canonical absolute directory.');
        }

        $before = $this->readMountInfo();
        // The local watcher may trip while mountinfo is being read. Do not
        // begin a potentially blocking hard-NFS root syscall after that trip.
        $this->evidence->assertFresh();
        clearstatcache(true, $storagePath);
        $canonical = realpath($storagePath);
        if ($canonical === false || $canonical !== $storagePath || ! is_dir($canonical)) {
            throw new \RuntimeException('EFS storage root must be an existing canonical absolute directory.');
        }

        $after = $this->readMountInfo();
        if ($before !== $after || LinuxMountInfo::filesystemTypeForPath($canonical, $after) !== 'nfs4') {
            throw new \RuntimeException('EFS storage mount changed or is not NFSv4.');
        }

        $this->evidence->assertFresh();
        clearstatcache(true, $canonical);
        $this->evidence->assertStorageIdentity($canonical);
        if ($after !== $this->readMountInfo()) {
            throw new \RuntimeException('EFS storage mount changed during identity validation.');
        }
        $this->evidence->assertFresh();

        return $canonical;
    }

    private function readMountInfo(): string
    {
        $mountInfo = ($this->mountInfoReader)();
        if (! is_string($mountInfo) || $mountInfo === '' || strlen($mountInfo) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('EFS storage mount information is unavailable.');
        }

        return $mountInfo;
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
}
