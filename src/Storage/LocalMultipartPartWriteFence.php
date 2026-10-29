<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;

/** Blocking, worker-only transfer from private node scratch to one planned part path. */
final readonly class LocalMultipartPartWriteFence
{
    private LocalDurableFilePublisher $durability;

    public function __construct(
        private string $root,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        LocalDurableFileIo $durableIo = new NativeLocalObjectWriteIo(),
        private ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        $this->durability = new LocalDurableFilePublisher($root, $durableIo);
        $canonical = realpath($root);
        if ($canonical !== false) {
            $namespace = 'filesystem_' . substr(hash('sha256', rtrim($canonical, '/')), 0, 24);
            $this->tempBudget?->registerNamespace($namespace, rtrim($canonical, '/') . '/.tmp');
        }
    }

    public function publish(
        string $source,
        string $target,
        string $bucket,
        string $generation,
        string $uploadId,
        int $partNumber,
        string $attemptId,
        int $expectedSize,
        string $expectedSha256,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult {
        $internalScratchPrefix = rtrim($this->root, '/') . '/.tmp/object-writes/';
        if ($target !== StoragePath::forPlannedPart($this->root, $bucket, $generation, $uploadId, $partNumber, $attemptId)
            || $expectedSize < 0 || strlen($expectedSha256) !== 32
            || ! str_starts_with($source, '/')
            || (str_starts_with($source, rtrim($this->root, '/') . '/') && ! str_starts_with($source, $internalScratchPrefix))
            || preg_match('~/s3fw-part-' . $attemptId . '[a-f0-9]{32}$~D', $source) !== 1) {
            throw new \InvalidArgumentException('Multipart worker request does not match its planned path and stage.');
        }
        $stageDirectory = dirname($source);
        $directoryInfo = @lstat($stageDirectory);
        if (! function_exists('posix_geteuid') || $directoryInfo === false || ($directoryInfo['mode'] & 0o170000) !== 0o040000
            || ($directoryInfo['mode'] & 0o077) !== 0
            || $directoryInfo['uid'] !== posix_geteuid()
            || realpath($stageDirectory) !== $stageDirectory) {
            throw new \RuntimeException('Multipart node-local stage directory is unsafe.');
        }
        $sourceInfo = @lstat($source);
        if ($sourceInfo === false || ($sourceInfo['mode'] & 0o170000) !== 0o100000
            || ($sourceInfo['mode'] & 0o077) !== 0 || $sourceInfo['size'] !== $expectedSize
            || ! function_exists('posix_geteuid') || $sourceInfo['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Multipart node-local stage changed before worker read.');
        }
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new \RuntimeException('Multipart node-local stage is unavailable.');
        }
        $temp = null;
        $tempLease = null;
        $tempPath = '';
        $reservation = null;
        $ownedTemp = false;
        try {
            $opened = fstat($input);
            if ($opened === false || $opened['dev'] !== $sourceInfo['dev'] || $opened['ino'] !== $sourceInfo['ino']
                || $opened['size'] !== $expectedSize) {
                throw new \RuntimeException('Multipart node-local stage changed while opening.');
            }
            $this->ensureDirectory($this->root . '/.tmp');
            $this->ensureDirectory(dirname($target));
            $tempPath = $this->root . '/.tmp/' . bin2hex(random_bytes(16));
            if ($capacity !== null) {
                if ($this->tempBudget === null) {
                    throw new \LogicException('Pre-reserved publication capacity requires the canonical temporary coordinator.');
                }
                $this->tempBudget->retarget($capacity, $tempPath, 'fenced_part');
                $reservation = $capacity;
            } else {
                $reservation = $this->tempBudget?->start($tempPath, 'fenced_part');
            }
            $created = $reservation !== null ? @lstat($tempPath) : null;
            $temp = @fopen($tempPath, $reservation !== null ? 'r+b' : 'x+b');
            $ownedTemp = $reservation !== null || is_resource($temp);
            $openedTemp = is_resource($temp) ? @fstat($temp) : false;
            if ($temp === false || ($reservation !== null && ($created === false || $openedTemp === false
                || ! isset($created['dev'], $created['ino'], $openedTemp['dev'], $openedTemp['ino'])
                || $openedTemp['dev'] !== $created['dev'] || $openedTemp['ino'] !== $created['ino']))
                || ! @chmod($tempPath, 0600) || ! @flock($temp, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not protect multipart staging inode.');
            }
            if ($reservation !== null && $capacity === null) {
                $this->tempBudget?->reserve($reservation, $expectedSize);
            }
            $calculator = new ChecksumCalculator();
            while (! feof($input)) {
                $chunk = fread($input, 65_536);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new \RuntimeException('Could not read complete multipart node-local stage.');
                }
                if ($chunk === '') {
                    break;
                }
                $calculator->update($chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = @fwrite($temp, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Could not write multipart staging inode.');
                    }
                    $offset += $written;
                }
            }
            $checksums = $calculator->finalize();
            $sha256 = hex2bin($checksums->sha256Hex);
            if ($checksums->size !== $expectedSize || $sha256 === false
                || ! hash_equals($expectedSha256, $sha256)) {
                throw new \RuntimeException('Multipart node-local stage failed integrity verification.');
            }
            if (! $this->io->syncFile($temp)) {
                throw new \RuntimeException('Could not synchronize multipart staging inode.');
            }
            $writtenInode = @fstat($temp);
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Could not identify synchronized multipart staging inode.');
            }
            // Keep a second shared lock while closing the writer descriptor.
            // Keep the stale-temp sweeper from taking the inode during the
            // verification gap.
            $tempLease = @fopen($tempPath, 'rb');
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev'] || $leaseInode['ino'] !== $writtenInode['ino']
                || ! @flock($tempLease, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not hold synchronized multipart staging inode.');
            }
            if (! @flock($temp, LOCK_UN) || ! @fclose($temp)) {
                throw new \RuntimeException('Could not close multipart staging inode before verification.');
            }
            $temp = null;

            // Hash the physical inode after close/reopen, not the scratch
            // stream or the still-open writer descriptor.
            $verified = @fopen($tempPath, 'rb');
            if ($verified === false) {
                throw new \RuntimeException('Could not reopen multipart staging inode for verification.');
            }
            try {
                $openedInode = @fstat($verified);
                if (! is_array($openedInode)
                    || $openedInode['dev'] !== $writtenInode['dev'] || $openedInode['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Multipart staging inode changed before verification.');
                }
                $physicalCalculator = new ChecksumCalculator();
                while (! feof($verified)) {
                    $chunk = @fread($verified, 65_536);
                    if ($chunk === false || ($chunk === '' && ! feof($verified))) {
                        throw new \RuntimeException('Could not verify multipart staging inode.');
                    }
                    if ($chunk !== '') {
                        $physicalCalculator->update($chunk);
                    }
                }
            } finally {
                @fclose($verified);
            }
            $physicalChecksums = $physicalCalculator->finalize();
            $physicalSha256 = hex2bin($physicalChecksums->sha256Hex);
            if ($physicalChecksums->size !== $expectedSize || $physicalSha256 === false
                || ! hash_equals($expectedSha256, $physicalSha256)) {
                throw new \RuntimeException('Multipart staging inode failed physical integrity verification.');
            }
            (new LocalMultipartPathFence($this->root, $this->io))->publishVerifiedTemp(
                $tempPath,
                $target,
                $writtenInode['dev'],
                $writtenInode['ino'],
            );
            if ($reservation !== null) {
                if (! ($this->tempBudget?->cleanup($reservation) ?? false)
                    || ! $this->io->syncDirectory(dirname($tempPath))) {
                    throw new \RuntimeException('Could not durably remove multipart staging inode.');
                }
            } else {
                $this->durability->delete($tempPath);
            }
            $tempPath = '';

            return new StorageWriteResult(
                $target,
                $physicalChecksums->size,
                $physicalChecksums->md5Hex,
                $physicalChecksums->crc32Base64(),
                $physicalChecksums->crc32cBase64(),
                $physicalChecksums->sha1Base64(),
                $physicalChecksums->sha256Base64(),
            );
        } finally {
            if (is_resource($temp)) {
                @flock($temp, LOCK_UN);
                @fclose($temp);
            }
            if (is_resource($tempLease)) {
                @flock($tempLease, LOCK_UN);
                @fclose($tempLease);
            }
            @fclose($input);
            if ($tempPath !== '') {
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
    }

    private function ensureDirectory(string $path): void
    {
        if (! str_starts_with($path, rtrim($this->root, '/') . '/')) {
            throw new \InvalidArgumentException('Multipart worker directory escapes its root.');
        }
        $current = rtrim($this->root, '/');
        foreach (explode('/', substr($path, strlen($current) + 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid multipart worker directory.');
            }
            $current .= '/' . $segment;
            clearstatcache(true, $current);
            if (is_link($current) || (! is_dir($current) && ! @mkdir($current, 0700) && ! is_dir($current))
                || realpath($current) !== $current) {
                throw new \RuntimeException('Multipart worker directory is unsafe or unavailable.');
            }
            $entry = @lstat($current);
            if ($entry === false || ($entry['mode'] & 0o170000) !== 0o040000
                || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('Multipart worker directory must be private and process-owned.');
            }
        }
        $this->durability->ensureDirectory($path);
    }
}
