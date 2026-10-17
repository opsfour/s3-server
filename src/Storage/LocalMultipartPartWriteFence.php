<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;

/** Blocking, worker-only transfer from private node scratch to one planned part path. */
final readonly class LocalMultipartPartWriteFence
{
    public function __construct(
        private string $root,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private ?EfsHostEvidenceReader $evidence = null,
    ) {
        if ($evidence !== null && $root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Multipart worker root differs from EFS evidence.');
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
    ): StorageWriteResult {
        $this->evidence?->assertFresh();
        if ($target !== StoragePath::forPlannedPart($this->root, $bucket, $generation, $uploadId, $partNumber, $attemptId)
            || $expectedSize < 0 || strlen($expectedSha256) !== 32
            || ! str_starts_with($source, '/') || str_starts_with($source, rtrim($this->root, '/') . '/')
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
        try {
            $opened = fstat($input);
            if ($opened === false || $opened['dev'] !== $sourceInfo['dev'] || $opened['ino'] !== $sourceInfo['ino']
                || $opened['size'] !== $expectedSize) {
                throw new \RuntimeException('Multipart node-local stage changed while opening.');
            }
            $this->ensureDirectory($this->root . '/.tmp');
            $this->ensureDirectory(dirname($target));
            $tempPath = $this->root . '/.tmp/' . bin2hex(random_bytes(16));
            $this->evidence?->assertFresh();
            $temp = @fopen($tempPath, 'x+b');
            if ($temp === false) {
                throw new \RuntimeException('Could not create multipart EFS staging inode.');
            }
            if (! @chmod($tempPath, 0600) || ! @flock($temp, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not protect multipart EFS staging inode.');
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
                    $this->evidence?->assertFresh();
                    $written = @fwrite($temp, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Could not write multipart EFS staging inode.');
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
            $this->evidence?->assertFresh();
            if (! $this->io->syncFile($temp)) {
                throw new \RuntimeException('Could not synchronize multipart EFS staging inode.');
            }
            $writtenInode = @fstat($temp);
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Could not identify synchronized multipart EFS staging inode.');
            }
            // Keep a second shared lock while closing the writer descriptor.
            // This gives EFS close-to-open visibility without a stale-temp
            // sweeper taking the inode during the verification gap.
            $tempLease = @fopen($tempPath, 'rb');
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev'] || $leaseInode['ino'] !== $writtenInode['ino']
                || ! @flock($tempLease, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not hold synchronized multipart EFS staging inode.');
            }
            if (! @flock($temp, LOCK_UN) || ! @fclose($temp)) {
                throw new \RuntimeException('Could not close multipart EFS staging inode before verification.');
            }
            $temp = null;

            // Hash the physical inode after close/reopen, not the scratch
            // stream or the still-open writer descriptor.
            $verified = @fopen($tempPath, 'rb');
            if ($verified === false) {
                throw new \RuntimeException('Could not reopen multipart EFS staging inode for verification.');
            }
            try {
                $openedInode = @fstat($verified);
                if (! is_array($openedInode)
                    || $openedInode['dev'] !== $writtenInode['dev'] || $openedInode['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Multipart EFS staging inode changed before verification.');
                }
                $physicalCalculator = new ChecksumCalculator();
                while (! feof($verified)) {
                    $this->evidence?->assertFresh();
                    $chunk = @fread($verified, 65_536);
                    if ($chunk === false || ($chunk === '' && ! feof($verified))) {
                        throw new \RuntimeException('Could not verify multipart EFS staging inode.');
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
                throw new \RuntimeException('Multipart EFS staging inode failed physical integrity verification.');
            }
            $this->evidence?->assertFresh();
            (new LocalMultipartPathFence($this->root, $this->io, $this->evidence))->publishVerifiedTemp(
                $tempPath,
                $target,
                $writtenInode['dev'],
                $writtenInode['ino'],
            );
            $this->evidence?->assertFresh();
            if (! @unlink($tempPath)) {
                throw new \RuntimeException('Could not unlink multipart EFS staging inode.');
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
                try {
                    $this->evidence?->assertFresh();
                    @unlink($tempPath);
                } catch (\Throwable) {
                    // A healthy temp collector handles the orphan after evidence loss.
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
            $this->evidence?->assertFresh();
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
    }
}
