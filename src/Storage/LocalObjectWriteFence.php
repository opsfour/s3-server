<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

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
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence|null $efsEvidence = null,
    ) {
        if ($efsEvidence !== null) {
            if (! $io instanceof EfsAwareLocalObjectWriteIo) {
                throw new \InvalidArgumentException('Guarded object I/O must support evidence-aware directory synchronization.');
            }
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
        $this->efsEvidence?->assertFresh();
        $file = @fopen($tempPath, 'x+b');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            throw new \RuntimeException('Failed to create local object staging file.');
        }

        $tempLease = null;
        $calculator = new ChecksumCalculator();
        $size = 0;
        try {
            $this->efsEvidence?->assertFresh();
            $protected = @chmod($tempPath, 0600);
            $this->efsEvidence?->assertFresh();
            $locked = $protected && @flock($file, LOCK_SH | LOCK_NB);
            $this->efsEvidence?->assertFresh();
            if (! $protected || ! $locked) {
                throw new \RuntimeException('Failed to protect local object staging file.');
            }
            $this->efsEvidence?->assertFresh();
            while (($chunk = $body->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                $this->writeAll($file, $chunk);
            }
            $checksums = $calculator->finalize();
            $this->efsEvidence?->assertFresh();
            $synced = $this->io->syncFile($file);
            $this->efsEvidence?->assertFresh();
            if (! $synced) {
                throw new \RuntimeException('Failed to synchronize local object data.');
            }
            $writtenInode = @fstat($file);
            $this->efsEvidence?->assertFresh();
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Failed to verify local object staging file.');
            }
            // Keep a second shared lock through close-to-open verification and
            // link, so the stale-temp sweeper cannot claim the old inode.
            $this->efsEvidence?->assertFresh();
            $tempLease = @fopen($tempPath, 'rb');
            $this->efsEvidence?->assertFresh();
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            $this->efsEvidence?->assertFresh();
            $leaseLocked = $tempLease !== false && @flock($tempLease, LOCK_SH | LOCK_NB);
            $this->efsEvidence?->assertFresh();
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev']
                || $leaseInode['ino'] !== $writtenInode['ino'] || ! $leaseLocked) {
                throw new \RuntimeException('Failed to hold local object staging file.');
            }
            $unlocked = @flock($file, LOCK_UN);
            $this->efsEvidence?->assertFresh();
            $closed = $unlocked && @fclose($file);
            $this->efsEvidence?->assertFresh();
            if (! $unlocked || ! $closed) {
                throw new \RuntimeException('Failed to close local object staging file.');
            }
            $file = null;
            $this->efsEvidence?->assertFresh();

            // Reopen the synced inode before its create-only link. Reading the
            // original handle would miss a close-to-open visibility failure.
            $this->efsEvidence?->assertFresh();
            $verified = @fopen($tempPath, 'rb');
            $this->efsEvidence?->assertFresh();
            if ($verified === false) {
                throw new \RuntimeException('Failed to verify local object staging file.');
            }
            try {
                $openedInode = @fstat($verified);
                $this->efsEvidence?->assertFresh();
                if (! is_array($openedInode)
                    || $openedInode['dev'] !== $writtenInode['dev']
                    || $openedInode['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Local object staging file changed before publication.');
                }
                $physicalCalculator = new ChecksumCalculator();
                while (! feof($verified)) {
                    $this->efsEvidence?->assertFresh();
                    $written = @fread($verified, 65536);
                    $this->efsEvidence?->assertFresh();
                    if ($written === false || ($written === '' && ! feof($verified))) {
                        throw new \RuntimeException('Failed to verify local object staging file.');
                    }
                    $physicalCalculator->update($written);
                }
                $this->efsEvidence?->assertFresh();
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
            $actualSize = @filesize($tempPath);
            $this->efsEvidence?->assertFresh();
            if ($actualSize !== $size) {
                throw new \RuntimeException('Local object staging file is incomplete.');
            }
            $this->efsEvidence?->assertFresh();

            // A failed link can still have succeeded on NFS. Never overwrite or
            // infer a successful publication from an ambiguous response.
            $this->efsEvidence?->assertFresh();
            $linked = $this->io->link($tempPath, $intent->storagePath);
            $this->efsEvidence?->assertFresh();
            if (! $linked) {
                throw new \RuntimeException('Local create-only object publication is uncertain.');
            }
            $this->syncDirectoryChain($targetDirectory);

            $this->efsEvidence?->assertFresh();
            $unlinked = @unlink($tempPath);
            $this->efsEvidence?->assertFresh();
            if (! $unlinked) {
                throw new \RuntimeException('Failed to remove local object staging link.');
            }
            $this->efsEvidence?->assertFresh();
            $this->syncDirectoryChain($tempDirectory);
            $this->efsEvidence?->assertFresh();

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
                // Descriptor-only best-effort cleanup after evidence loss.
                @flock($file, LOCK_UN);
                @fclose($file);
            }
            if (is_resource($tempLease)) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @flock($tempLease, LOCK_UN);
                @fclose($tempLease);
            }
            // The final path is intentionally never removed here. A committed
            // collector claim must replace it with a permanent same-path seal.
            if ($this->efsEvidence === null) {
                @unlink($tempPath);
            } else {
                $this->efsEvidence->assertFresh();
                @unlink($tempPath);
                $this->efsEvidence->assertFresh();
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
        $this->efsEvidence?->assertFresh();
        $file = @fopen($sealPath, 'x+b');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            throw new \RuntimeException('Failed to create local object seal.');
        }

        try {
            $this->efsEvidence?->assertFresh();
            $protected = @chmod($sealPath, 0600);
            $this->efsEvidence?->assertFresh();
            if (! $protected) {
                throw new \RuntimeException('Failed to protect local object seal.');
            }
            $this->writeAll($file, $marker);
            $this->efsEvidence?->assertFresh();
            $synced = $this->io->syncFile($file);
            $this->efsEvidence?->assertFresh();
            $closed = $synced && @fclose($file);
            $this->efsEvidence?->assertFresh();
            if (! $synced || ! $closed) {
                throw new \RuntimeException('Failed to synchronize local object seal.');
            }
            $file = null;
            $this->efsEvidence?->assertFresh();

            // NFS may report failure after a successful rename. Verify the
            // unique marker even then; retry is safe while SQL retains claim.
            $this->efsEvidence?->assertFresh();
            $renamed = $this->io->rename($sealPath, $claimed->storagePath);
            $this->efsEvidence?->assertFresh();
            $this->syncDirectoryChain($targetDirectory);
            if (! $this->hasExactMarker($claimed->storagePath, $marker)) {
                throw new \RuntimeException($renamed
                    ? 'Local object seal was replaced before verification.'
                    : 'Local object seal publication is uncertain.');
            }
        } finally {
            if (is_resource($file)) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @fclose($file);
            }
            if ($this->efsEvidence === null) {
                @unlink($sealPath);
            } else {
                $this->efsEvidence->assertFresh();
                @unlink($sealPath);
                $this->efsEvidence->assertFresh();
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
            $this->efsEvidence?->assertFresh();
            clearstatcache(true, $current);
            $linked = is_link($current);
            $this->efsEvidence?->assertFresh();
            if ($linked) {
                throw new \RuntimeException('Local object directory escapes its storage root.');
            }
            $directory = is_dir($current);
            $this->efsEvidence?->assertFresh();
            $created = $directory;
            if (! $directory) {
                $created = @mkdir($current, 0700);
                $this->efsEvidence?->assertFresh();
                if (! $created) {
                    $directory = is_dir($current);
                    $this->efsEvidence?->assertFresh();
                }
            }
            if (! $directory && ! $created) {
                throw new \RuntimeException('Failed to create local object directory.');
            }
            $this->efsEvidence?->assertFresh();
            clearstatcache(true, $current);
            $linked = is_link($current);
            $this->efsEvidence?->assertFresh();
            $resolved = realpath($current);
            $this->efsEvidence?->assertFresh();
            if ($linked || $resolved !== $current) {
                throw new \RuntimeException('Local object directory escapes its storage root.');
            }
            if ($this->efsEvidence !== null) {
                $this->efsEvidence->assertFresh();
                $entry = @lstat($current);
                $this->efsEvidence->assertFresh();
                if (! is_array($entry) || ($entry['mode'] & 0o170000) !== 0o040000
                    || ($entry['mode'] & 0o7777) !== 0o700
                    || ! function_exists('posix_geteuid') || $entry['uid'] !== posix_geteuid()) {
                    throw new \RuntimeException('Local object directory must be private and process-owned.');
                }
                $this->efsEvidence->assertFresh();
            }
        }
    }

    private function syncDirectoryChain(string $path): void
    {
        while (true) {
            $this->efsEvidence?->assertFresh();
            if ($this->efsEvidence !== null && ! $this->io instanceof EfsAwareLocalObjectWriteIo) {
                throw new \LogicException('Guarded object I/O lost evidence-aware directory synchronization.');
            }
            $synced = $this->efsEvidence !== null
                ? $this->io->syncDirectoryWithEvidence($path, $this->efsEvidence)
                : $this->io->syncDirectory($path);
            $this->efsEvidence?->assertFresh();
            if (! $synced) {
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
            $this->efsEvidence?->assertFresh();
            $written = @fwrite($file, substr($bytes, $offset));
            $this->efsEvidence?->assertFresh();
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
        $this->efsEvidence?->assertFresh();
        $file = @fopen($path, 'rb');
        $this->efsEvidence?->assertFresh();
        if ($file === false) {
            return false;
        }
        try {
            $status = @fstat($file);
            $this->efsEvidence?->assertFresh();
            if ($status === false || $status['size'] !== $length) {
                return false;
            }
            $this->efsEvidence?->assertFresh();
            $actual = '';
            while (strlen($actual) < $length) {
                $this->efsEvidence?->assertFresh();
                $remaining = $length - strlen($actual);
                if ($remaining < 1) {
                    break;
                }
                $chunk = @fread($file, $remaining);
                $this->efsEvidence?->assertFresh();
                if ($chunk === false || $chunk === '') {
                    return false;
                }
                $actual .= $chunk;
            }

            return hash_equals($marker, $actual);
        } finally {
            // Descriptor-only best-effort cleanup after evidence loss.
            @fclose($file);
        }
    }
}
