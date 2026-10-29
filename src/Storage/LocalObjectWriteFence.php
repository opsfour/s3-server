<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/**
 * Isolated filesystem primitive for a planned object path. Publication needs
 * an active SQL intent; sealing needs
 * a committed collector claim. The caller must use the intent's CAS methods.
 * All other writers and cleaners must obey the same create-only/seal contract.
 * These blocking syscalls must run in a worker, never on the API event loop;
 * an adapter and a filesystem fault gate are required before runtime use.
 * The storage namespace must be writable only by trusted service processes:
 * PHP path APIs cannot atomically reject a concurrent directory-symlink swap.
 */
final readonly class LocalObjectWriteFence
{
    private string $root;

    private string $tempNamespace;

    public function __construct(
        string $basePath,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        $resolved = realpath($basePath);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \InvalidArgumentException('Local object storage root must exist.');
        }
        $this->root = rtrim($resolved, '/');
        $this->tempNamespace = 'filesystem_' . substr(hash('sha256', $this->root), 0, 24);
        $this->tempBudget?->registerNamespace($this->tempNamespace, $this->root . '/.tmp');
    }

    public function planPath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject($this->root, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function write(
        ObjectWriteIntent $intent,
        string $key,
        ReadableStream $body,
        ?int $knownSize = null,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult {
        $this->assertWriteIntentPath($intent, $key);
        if ($intent->state !== 'active') {
            throw new \InvalidArgumentException('Object write intent must be active.');
        }

        $targetDirectory = dirname($intent->storagePath);
        $tempDirectory = $this->root . '/.tmp';
        $this->ensureDirectory($targetDirectory);
        $this->ensureDirectory($tempDirectory);
        $tempPath = $tempDirectory . '/' . $intent->attemptId . '-' . bin2hex(random_bytes(16));
        $file = null;
        $tempLease = null;
        $reservation = null;
        $ownedTemp = false;
        $calculator = new ChecksumCalculator();
        $size = 0;
        try {
            if ($capacity !== null) {
                if ($this->tempBudget === null) {
                    throw new \LogicException('Pre-reserved publication capacity requires the canonical temporary coordinator.');
                }
                $this->tempBudget->retarget($capacity, $tempPath, 'fenced_object');
                $reservation = $capacity;
            } else {
                $reservation = $this->tempBudget?->start($tempPath, 'fenced_object');
            }
            $created = $reservation !== null ? @lstat($tempPath) : null;
            $file = @fopen($tempPath, $reservation !== null ? 'r+b' : 'x+b');
            $ownedTemp = $reservation !== null || is_resource($file);
            $opened = is_resource($file) ? @fstat($file) : false;
            if ($file === false || ($reservation !== null && ($created === false || $opened === false
                || ! isset($created['dev'], $created['ino'], $opened['dev'], $opened['ino'])
                || $opened['dev'] !== $created['dev'] || $opened['ino'] !== $created['ino']))
                || ! @chmod($tempPath, 0600) || ! @flock($file, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Failed to protect local object staging file.');
            }
            if ($knownSize !== null && $capacity === null) {
                if ($knownSize < 0) {
                    throw new \InvalidArgumentException('Known local object size must be non-negative.');
                }
                if ($reservation !== null) {
                    $this->tempBudget?->reserve($reservation, $knownSize);
                }
            }
            $reservedBytes = $knownSize ?? 0;
            while (($chunk = $body->read()) !== null) {
                $nextSize = $size + strlen($chunk);
                if ($reservation !== null && $nextSize > $reservedBytes) {
                    $this->tempBudget?->reserve($reservation, $nextSize - $reservedBytes);
                    $reservedBytes = $nextSize;
                }
                $calculator->update($chunk);
                $size = $nextSize;
                $this->writeAll($file, $chunk);
            }
            $checksums = $calculator->finalize();
            if (! $this->io->syncFile($file)) {
                throw new \RuntimeException('Failed to synchronize local object data.');
            }
            $writtenInode = @fstat($file);
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Failed to verify local object staging file.');
            }
            // Keep a second shared lock through close-to-open verification and
            // link, so the stale-temp sweeper cannot claim the old inode.
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

            clearstatcache(true, $tempPath);
            if (@filesize($tempPath) !== $size) {
                throw new \RuntimeException('Local object staging file is incomplete.');
            }

            // A failed link can still have succeeded on NFS. Never overwrite or
            // infer a successful publication from an ambiguous response.
            if (! $this->io->link($tempPath, $intent->storagePath)) {
                throw new \RuntimeException('Local create-only object publication is uncertain.');
            }
            $this->syncDirectoryChain($targetDirectory);

            $removed = $reservation !== null
                ? ($this->tempBudget?->cleanup($reservation) ?? false)
                : @unlink($tempPath);
            if (! $removed) {
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
            if ($reservation !== null) {
                try {
                    $this->tempBudget?->cleanup($reservation);
                } catch (\Throwable) {
                }
            } elseif ($ownedTemp) {
                @unlink($tempPath);
            }
        }
    }

    /** Replace the exact path after a committed SQL claim; retain the seal. */
    public function seal(ObjectWriteIntent $claimed): void
    {
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
            @unlink($sealPath);
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
            $actual = '';
            while (strlen($actual) < $length) {
                $remaining = $length - strlen($actual);
                if ($remaining < 1) {
                    break;
                }
                $chunk = @fread($file, $remaining);
                if ($chunk === false || $chunk === '') {
                    return false;
                }
                $actual .= $chunk;
            }

            return hash_equals($marker, $actual);
        } finally {
            fclose($file);
        }
    }
}
