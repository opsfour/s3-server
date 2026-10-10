<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;

/**
 * Isolated filesystem primitive for a planned object path. Publication needs
 * an active SQL intent; sealing needs
 * a committed collector claim. The caller must use the intent's CAS methods.
 * All other writers and cleaners must obey the same create-only/seal contract.
 * These blocking syscalls must run in a worker, never on the API event loop;
 * an adapter and an EFS two-host fault gate are required before runtime use.
 * The storage namespace must be writable only by trusted service processes:
 * PHP path APIs cannot atomically reject a concurrent directory-symlink swap.
 */
final readonly class LocalObjectWriteFence
{
    private string $root;

    public function __construct(
        string $basePath,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        if ($efsEvidence !== null) {
            if ($basePath !== $efsEvidence->storageRoot()) {
                throw new \InvalidArgumentException('Guarded local object root differs from EFS evidence.');
            }
            $efsEvidence->assertFresh();
            $this->root = rtrim($basePath, '/');
            return;
        }
        $resolved = realpath($basePath);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \InvalidArgumentException('Local object storage root must exist.');
        }
        $this->root = rtrim($resolved, '/');
    }

    public function planPath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject($this->root, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function write(ObjectWriteIntent $intent, string $key, ReadableStream $body): StorageWriteResult
    {
        $this->efsEvidence?->assertFresh();
        $this->assertWriteIntentPath($intent, $key);
        if ($intent->state !== 'active') {
            throw new \InvalidArgumentException('Object write intent must be active.');
        }

        $targetDirectory = dirname($intent->storagePath);
        $tempDirectory = $this->root . '/.tmp';
        $this->ensureDirectory($targetDirectory);
        $this->ensureDirectory($tempDirectory);
        $tempPath = $tempDirectory . '/' . $intent->attemptId . '-' . bin2hex(random_bytes(16));
        $file = @fopen($tempPath, 'x+b');
        if ($file === false) {
            throw new \RuntimeException('Failed to create local object staging file.');
        }

        $tempLease = null;
        $calculator = new ChecksumCalculator();
        $size = 0;
        try {
            if (! @chmod($tempPath, 0600) || ! @flock($file, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Failed to protect local object staging file.');
            }
            while (($chunk = $body->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                $this->writeAll($file, $chunk);
            }
            $checksums = $calculator->finalize();
            if (! $this->io->syncFile($file)) {
                throw new \RuntimeException('Failed to synchronize local object data.');
            }
            $this->efsEvidence?->assertFresh();
            $writtenInode = @fstat($file);
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Failed to verify local object staging file.');
            }
            // Keep a second shared lock through close-to-open verification and
            // link, so the stale-temp sweeper cannot claim the old inode.
            $this->efsEvidence?->assertFresh();
            $tempLease = @fopen($tempPath, 'rb');
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev']
                || $leaseInode['ino'] !== $writtenInode['ino']
                || ! @flock($tempLease, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Failed to hold local object staging file.');
            }
            if (! @flock($file, LOCK_UN) || ! @fclose($file)) {
                throw new \RuntimeException('Failed to close local object staging file.');
            }
            $file = null;

            // Reopen the synced inode before its create-only link. Reading the
            // original handle would miss a close-to-open visibility failure.
            $this->efsEvidence?->assertFresh();
            $verified = @fopen($tempPath, 'rb');
            if ($verified === false) {
                throw new \RuntimeException('Failed to verify local object staging file.');
            }
            try {
                $openedInode = @fstat($verified);
                if (! is_array($openedInode)
                    || $openedInode['dev'] !== $writtenInode['dev']
                    || $openedInode['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Local object staging file changed before publication.');
                }
                $physicalCalculator = new ChecksumCalculator();
                while (! feof($verified)) {
                    $this->efsEvidence?->assertFresh();
                    $written = @fread($verified, 65536);
                    if ($written === false || ($written === '' && ! feof($verified))) {
                        throw new \RuntimeException('Failed to verify local object staging file.');
                    }
                    $physicalCalculator->update($written);
                }
                $physical = $physicalCalculator->finalize();
                if ($physical->size !== $checksums->size
                    || ! hash_equals($checksums->sha256Hex, $physical->sha256Hex)) {
                    throw new \RuntimeException('Local object staging file differs from input.');
                }
            } finally {
                @fclose($verified);
            }

            $this->efsEvidence?->assertFresh();
            clearstatcache(true, $tempPath);
            if (@filesize($tempPath) !== $size) {
                throw new \RuntimeException('Local object staging file is incomplete.');
            }

            // A failed link can still have succeeded on NFS. Never overwrite or
            // infer a successful publication from an ambiguous response.
            $this->efsEvidence?->assertFresh();
            if (! $this->io->link($tempPath, $intent->storagePath)) {
                throw new \RuntimeException('Local create-only object publication is uncertain.');
            }
            $this->syncDirectoryChain($targetDirectory);

            if (! @unlink($tempPath)) {
                throw new \RuntimeException('Failed to remove local object staging link.');
            }
            $this->syncDirectoryChain($tempDirectory);

            return new StorageWriteResult(
                path: $intent->storagePath,
                size: $size,
                md5Hex: $checksums->md5Hex,
                crc32Base64: $checksums->crc32Base64(),
                crc32cBase64: $checksums->crc32cBase64(),
                sha1Base64: $checksums->sha1Base64(),
                sha256Base64: $checksums->sha256Base64(),
            );
        } finally {
            if (is_resource($file)) {
                @flock($file, LOCK_UN);
                @fclose($file);
            }
            if (is_resource($tempLease)) {
                @flock($tempLease, LOCK_UN);
                @fclose($tempLease);
            }
            // The final path is intentionally never removed here. A committed
            // collector claim must replace it with a permanent same-path seal.
            if ($this->efsEvidence === null) {
                @unlink($tempPath);
            } else {
                try {
                    $this->efsEvidence->assertFresh();
                    @unlink($tempPath);
                } catch (\Throwable) {
                    // Leave an orphaned temp path for a healthy node rather
                    // than issue another hard-NFS call after host evidence loss.
                }
            }
        }
    }

    /** Replace the exact path after a committed SQL claim; retain the seal. */
    public function seal(ObjectWriteIntent $claimed): void
    {
        $this->efsEvidence?->assertFresh();
        StoragePath::assertPlannedObjectIntentPath(
            $this->root,
            $claimed->storagePath,
            $claimed->bucket,
            $claimed->bucketGeneration,
            $claimed->attemptId,
        );
        if ($claimed->state !== 'reconciling' || $claimed->claimGeneration < 1) {
            throw new \InvalidArgumentException('A committed object write claim is required.');
        }

        $targetDirectory = dirname($claimed->storagePath);
        $this->ensureDirectory($targetDirectory);
        $marker = "OPSFOUR-LOCAL-OBJECT-SEAL-V1\n" . $claimed->attemptId . "\n" . bin2hex(random_bytes(32)) . "\n";
        $sealPath = $targetDirectory . '/.seal-' . $claimed->attemptId . '-' . bin2hex(random_bytes(16));
        $file = @fopen($sealPath, 'x+b');
        if ($file === false) {
            throw new \RuntimeException('Failed to create local object seal.');
        }

        try {
            if (! @chmod($sealPath, 0600)) {
                throw new \RuntimeException('Failed to protect local object seal.');
            }
            $this->writeAll($file, $marker);
            if (! $this->io->syncFile($file) || ! @fclose($file)) {
                throw new \RuntimeException('Failed to synchronize local object seal.');
            }
            $file = null;

            // NFS may report failure after a successful rename. Verify the
            // unique marker even then; retry is safe while SQL retains claim.
            $this->efsEvidence?->assertFresh();
            $renamed = $this->io->rename($sealPath, $claimed->storagePath);
            $this->syncDirectoryChain($targetDirectory);
            if (! $this->hasExactMarker($claimed->storagePath, $marker)) {
                throw new \RuntimeException($renamed
                    ? 'Local object seal was replaced before verification.'
                    : 'Local object seal publication is uncertain.');
            }
        } finally {
            if (is_resource($file)) {
                @fclose($file);
            }
            if ($this->efsEvidence === null) {
                @unlink($sealPath);
            } else {
                try {
                    $this->efsEvidence->assertFresh();
                    @unlink($sealPath);
                } catch (\Throwable) {
                    // A healthy collector can reclaim the orphaned seal temp
                    // after a watcher trip; do not issue another EFS syscall.
                }
            }
        }
    }

    private function assertWriteIntentPath(ObjectWriteIntent $intent, string $key): void
    {
        if ($intent->storagePath !== $this->planPath($intent->bucket, $key, $intent->bucketGeneration, $intent->attemptId)) {
            throw new \InvalidArgumentException('Object write intent does not match its planned local path.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if ($path !== $this->root && ! str_starts_with($path, $this->root . '/')) {
            throw new \RuntimeException('Local object directory escapes its storage root.');
        }
        $current = $this->root;
        $relative = $path === $this->root ? '' : substr($path, strlen($this->root) + 1);
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new \RuntimeException('Local object directory escapes its storage root.');
            }
            if (! is_dir($current) && ! @mkdir($current, 0700) && ! is_dir($current)) {
                throw new \RuntimeException('Failed to create local object directory.');
            }
            clearstatcache(true, $current);
            if (is_link($current) || realpath($current) !== $current) {
                throw new \RuntimeException('Local object directory escapes its storage root.');
            }
            if ($this->efsEvidence !== null) {
                $entry = @lstat($current);
                if (! is_array($entry) || ($entry['mode'] & 0o170000) !== 0o040000
                    || ($entry['mode'] & 0o7777) !== 0o700
                    || ! function_exists('posix_geteuid') || $entry['uid'] !== posix_geteuid()) {
                    throw new \RuntimeException('Local object directory must be private and process-owned.');
                }
            }
        }
    }

    private function syncDirectoryChain(string $path): void
    {
        while (true) {
            if (! $this->io->syncDirectory($path)) {
                throw new \RuntimeException('Failed to synchronize local object directory.');
            }
            if ($path === $this->root) {
                return;
            }
            $path = dirname($path);
        }
    }

    /** @param resource $file */
    private function writeAll($file, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = @fwrite($file, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed to write local object bytes.');
            }
            $offset += $written;
        }
    }

    private function hasExactMarker(string $path, string $marker): bool
    {
        $length = strlen($marker);
        if ($length === 0) {
            return false;
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            return false;
        }
        try {
            $status = fstat($file);
            if ($status === false || $status['size'] !== $length) {
                return false;
            }
            return hash_equals($marker, (string) fread($file, $length));
        } finally {
            fclose($file);
        }
    }
}
