<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/** Blocking physical half of an already armed, one-shot completion attempt. */
final readonly class LocalMultipartAssemblyFence
{
    private const int CHUNK_BYTES = 65_536;

    private string $canonicalRoot;

    public function __construct(
        private string $root,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence|null $evidence = null,
    ) {
        if ($evidence !== null && $root !== $evidence->storageRoot()) {
            throw new \InvalidArgumentException('Multipart assembly worker root differs from EFS evidence.');
        }
        $canonical = $evidence !== null ? $root : realpath($root);
        $evidence?->assertFresh();
        $directory = $canonical !== false && is_dir($canonical);
        $evidence?->assertFresh();
        if ($canonical === false || ! $directory) {
            throw new \InvalidArgumentException('Multipart assembly worker root is unavailable.');
        }
        $this->canonicalRoot = rtrim($canonical, '/');
    }

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function assemble(
        string $bucket,
        string $key,
        string $generation,
        string $attemptId,
        string $uploadId,
        array $parts,
        string $target,
    ): StorageWriteResult {
        $this->evidence?->assertFresh();
        $planned = StoragePath::forMultipartCompletion($this->root, $bucket, $key, $generation, $attemptId);
        StoragePath::assertSafeUploadIdSegment($uploadId);
        if ($target !== $planned['assembly'] || $parts === []) {
            throw new \InvalidArgumentException('Multipart assembly target or part list is invalid.');
        }
        $prefix = rtrim($this->root, '/') . '/.parts/' . $uploadId . '/';
        $previousNumber = 0;
        foreach ($parts as $part) {
            $number = $part['partNumber'];
            $etag = strtolower(trim($part['etag'], '"'));
            if ($number <= $previousNumber || $number > 10000
                || $part['size'] < 0 || $part['size'] > MultipartPartSize::MAX_BYTES
                || ! preg_match('/^[a-f0-9]{32}$/D', $etag)
                || ! str_starts_with($part['storagePath'], $prefix)
                || ! preg_match('/^' . $number . '-[a-f0-9]{32}(?:-[a-f0-9]{32})?$/D', substr($part['storagePath'], strlen($prefix)))) {
                throw new \InvalidArgumentException('Multipart assembly part does not match its upload.');
            }
            $previousNumber = $number;
        }

        $this->ensureDirectory(rtrim($prefix, '/'));
        $this->ensureDirectory($this->root . '/.tmp');
        $this->ensureDirectory(dirname($target));
        $tempPath = $this->root . '/.tmp/' . bin2hex(random_bytes(16));
        $temp = null;
        $tempLease = null;
        try {
            $this->evidence?->assertFresh();
            $temp = @fopen($tempPath, 'x+b');
            $this->evidence?->assertFresh();
            $protected = $temp !== false && @chmod($tempPath, 0600);
            $this->evidence?->assertFresh();
            $locked = $temp !== false && $protected && @flock($temp, LOCK_SH | LOCK_NB);
            $this->evidence?->assertFresh();
            if ($temp === false || ! $protected || ! $locked) {
                throw new \RuntimeException('Could not create protected multipart assembly inode.');
            }
            $calculator = new ChecksumCalculator();
            $partMd5s = '';
            foreach ($parts as $part) {
                $this->evidence?->assertFresh();
                $path = $part['storagePath'];
                $entry = @lstat($path);
                $this->evidence?->assertFresh();
                if ($entry === false || ($entry['mode'] & 0o170000) !== 0o100000
                    || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                    throw new \RuntimeException('Committed multipart part is unavailable or unsafe.');
                }
                $this->evidence?->assertFresh();
                $input = @fopen($path, 'rb');
                $this->evidence?->assertFresh();
                if ($input === false) {
                    throw new \RuntimeException('Committed multipart part could not be opened.');
                }
                try {
                    $opened = @fstat($input);
                    $this->evidence?->assertFresh();
                    if ($opened === false || $opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
                        throw new \RuntimeException('Committed multipart part changed while opening.');
                    }
                    $partHash = hash_init('md5');
                    $partBytes = 0;
                    while (! feof($input)) {
                        $this->evidence?->assertFresh();
                        $chunk = @fread($input, self::CHUNK_BYTES);
                        $this->evidence?->assertFresh();
                        if ($chunk === false || ($chunk === '' && ! feof($input))) {
                            throw new \RuntimeException('Committed multipart part could not be read completely.');
                        }
                        if ($chunk === '') {
                            break;
                        }
                        $partBytes += strlen($chunk);
                        if ($partBytes > $part['size']) {
                            throw new \RuntimeException('Committed multipart part exceeds its metadata size.');
                        }
                        hash_update($partHash, $chunk);
                        $calculator->update($chunk);
                        $offset = 0;
                        while ($offset < strlen($chunk)) {
                            $this->evidence?->assertFresh();
                            $written = @fwrite($temp, substr($chunk, $offset));
                            $this->evidence?->assertFresh();
                            if ($written === false || $written === 0) {
                                throw new \RuntimeException('Could not write multipart assembly inode.');
                            }
                            $offset += $written;
                        }
                    }
                    $this->evidence?->assertFresh();
                    if ($partBytes !== $part['size'] || ! hash_equals(strtolower(trim($part['etag'], '"')), hash_final($partHash))) {
                        throw new \RuntimeException('Committed multipart part failed physical checksum verification.');
                    }
                    $partMd5s .= hex2bin(strtolower(trim($part['etag'], '"')));
                } finally {
                    @fclose($input);
                }
            }
            $checksums = $calculator->finalize();
            $this->evidence?->assertFresh();
            $synced = $this->io->syncFile($temp);
            $this->evidence?->assertFresh();
            if (! $synced) {
                throw new \RuntimeException('Could not synchronize multipart assembly inode.');
            }
            $writtenInode = @fstat($temp);
            $this->evidence?->assertFresh();
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Could not identify multipart assembly inode.');
            }
            $this->evidence?->assertFresh();
            $tempLease = @fopen($tempPath, 'rb');
            $this->evidence?->assertFresh();
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            $this->evidence?->assertFresh();
            $leaseLocked = $tempLease !== false && @flock($tempLease, LOCK_SH | LOCK_NB);
            $this->evidence?->assertFresh();
            $writerUnlocked = @flock($temp, LOCK_UN);
            $this->evidence?->assertFresh();
            $writerClosed = $writerUnlocked && @fclose($temp);
            $this->evidence?->assertFresh();
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev'] || $leaseInode['ino'] !== $writtenInode['ino']
                || ! $leaseLocked || ! $writerUnlocked || ! $writerClosed) {
                throw new \RuntimeException('Could not close synchronized multipart assembly inode.');
            }
            $temp = null;
            $this->evidence?->assertFresh();
            $verified = @fopen($tempPath, 'rb');
            $this->evidence?->assertFresh();
            if ($verified === false) {
                throw new \RuntimeException('Could not reopen multipart assembly inode.');
            }
            try {
                $opened = @fstat($verified);
                $this->evidence?->assertFresh();
                if ($opened === false || $opened['dev'] !== $writtenInode['dev'] || $opened['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Multipart assembly inode changed before verification.');
                }
                $physical = new ChecksumCalculator();
                while (! feof($verified)) {
                    $this->evidence?->assertFresh();
                    $chunk = @fread($verified, self::CHUNK_BYTES);
                    $this->evidence?->assertFresh();
                    if ($chunk === false || ($chunk === '' && ! feof($verified))) {
                        throw new \RuntimeException('Could not verify multipart assembly inode.');
                    }
                    if ($chunk !== '') {
                        $physical->update($chunk);
                    }
                }
                $this->evidence?->assertFresh();
            } finally {
                @fclose($verified);
            }
            $actual = $physical->finalize();
            if ($actual->size !== $checksums->size
                || ! hash_equals($checksums->sha256Hex, $actual->sha256Hex)) {
                throw new \RuntimeException('Multipart assembly inode failed physical integrity verification.');
            }
            $this->evidence?->assertFresh();
            (new LocalMultipartPathFence($this->root, $this->io, $this->evidence))->publishVerifiedTemp(
                $tempPath,
                $target,
                $writtenInode['dev'],
                $writtenInode['ino'],
            );

            return new StorageWriteResult(
                $target,
                $actual->size,
                md5($partMd5s) . '-' . count($parts),
                $actual->crc32Base64(),
                $actual->crc32cBase64(),
                $actual->sha1Base64(),
                $actual->sha256Base64(),
            );
        } finally {
            if (is_resource($temp)) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @flock($temp, LOCK_UN);
                @fclose($temp);
            }
            if (is_resource($tempLease)) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @flock($tempLease, LOCK_UN);
                @fclose($tempLease);
            }
            $this->evidence?->assertFresh();
            @unlink($tempPath);
            $this->evidence?->assertFresh();
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! str_starts_with($path, rtrim($this->root, '/') . '/')) {
            throw new \InvalidArgumentException('Multipart assembly directory escapes its root.');
        }
        $current = rtrim($this->root, '/');
        $expected = $this->canonicalRoot;
        foreach (explode('/', substr($path, strlen($current) + 1)) as $segment) {
            $this->evidence?->assertFresh();
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid multipart assembly directory.');
            }
            $current .= '/' . $segment;
            $expected .= '/' . $segment;
            clearstatcache(true, $current);
            $linked = is_link($current);
            $this->evidence?->assertFresh();
            $directory = is_dir($current);
            $this->evidence?->assertFresh();
            $created = $directory;
            if (! $directory) {
                $created = @mkdir($current, 0700);
                $this->evidence?->assertFresh();
                if (! $created) {
                    $directory = is_dir($current);
                    $this->evidence?->assertFresh();
                }
            }
            $resolved = realpath($current);
            $this->evidence?->assertFresh();
            if ($linked || (! $directory && ! $created) || $resolved !== $expected) {
                throw new \RuntimeException('Multipart assembly directory is unsafe or unavailable.');
            }
            $entry = @lstat($current);
            $this->evidence?->assertFresh();
            if ($entry === false || ($entry['mode'] & 0o170000) !== 0o040000
                || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('Multipart assembly directory must be private and process-owned.');
            }
        }
    }
}
