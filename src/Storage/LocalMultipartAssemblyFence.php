<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Multipart\MultipartPartSize;

/** Blocking physical half of an already armed, one-shot completion attempt. */
final readonly class LocalMultipartAssemblyFence
{
    private const int CHUNK_BYTES = 65_536;

    private string $canonicalRoot;

    private LocalDurableFilePublisher $durability;

    public function __construct(
        private string $root,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        LocalDurableFileIo $durableIo = new NativeLocalObjectWriteIo(),
        private ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        $canonical = realpath($root);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new \InvalidArgumentException('Multipart assembly worker root is unavailable.');
        }
        $this->canonicalRoot = rtrim($canonical, '/');
        $this->durability = new LocalDurableFilePublisher($root, $durableIo);
        $namespace = 'filesystem_' . substr(hash('sha256', $this->canonicalRoot), 0, 24);
        $this->tempBudget?->registerNamespace($namespace, $this->canonicalRoot . '/.tmp');
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
        $reservation = null;
        $ownedTemp = false;
        try {
            $reservation = $this->tempBudget?->start($tempPath, 'fenced_assembly');
            $created = $reservation !== null ? @lstat($tempPath) : null;
            $temp = @fopen($tempPath, $reservation !== null ? 'r+b' : 'x+b');
            $ownedTemp = $reservation !== null || is_resource($temp);
            $openedTemp = is_resource($temp) ? @fstat($temp) : false;
            if ($temp === false || ($reservation !== null && ($created === false || $openedTemp === false
                || ! isset($created['dev'], $created['ino'], $openedTemp['dev'], $openedTemp['ino'])
                || $openedTemp['dev'] !== $created['dev'] || $openedTemp['ino'] !== $created['ino']))
                || ! @chmod($tempPath, 0600) || ! @flock($temp, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not create protected multipart assembly inode.');
            }
            if ($reservation !== null) {
                $knownBytes = 0;
                foreach ($parts as $part) {
                    if ($part['size'] > PHP_INT_MAX - $knownBytes) {
                        throw new \RuntimeException('Multipart assembly size exceeds node accounting range.');
                    }
                    $knownBytes += $part['size'];
                }
                $this->tempBudget?->reserve($reservation, $knownBytes);
            }
            $calculator = new ChecksumCalculator();
            $partMd5s = '';
            foreach ($parts as $part) {
                $path = $part['storagePath'];
                $entry = @lstat($path);
                if ($entry === false || ($entry['mode'] & 0o170000) !== 0o100000
                    || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                    throw new \RuntimeException('Committed multipart part is unavailable or unsafe.');
                }
                $input = @fopen($path, 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Committed multipart part could not be opened.');
                }
                try {
                    $opened = @fstat($input);
                    if ($opened === false || $opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
                        throw new \RuntimeException('Committed multipart part changed while opening.');
                    }
                    $partHash = hash_init('md5');
                    $partBytes = 0;
                    while (! feof($input)) {
                        $chunk = @fread($input, self::CHUNK_BYTES);
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
                            $written = @fwrite($temp, substr($chunk, $offset));
                            if ($written === false || $written === 0) {
                                throw new \RuntimeException('Could not write multipart assembly inode.');
                            }
                            $offset += $written;
                        }
                    }
                    if ($partBytes !== $part['size'] || ! hash_equals(strtolower(trim($part['etag'], '"')), hash_final($partHash))) {
                        throw new \RuntimeException('Committed multipart part failed physical checksum verification.');
                    }
                    $partMd5s .= hex2bin(strtolower(trim($part['etag'], '"')));
                } finally {
                    @fclose($input);
                }
            }
            $checksums = $calculator->finalize();
            if (! $this->io->syncFile($temp)) {
                throw new \RuntimeException('Could not synchronize multipart assembly inode.');
            }
            $writtenInode = @fstat($temp);
            if (! is_array($writtenInode)) {
                throw new \RuntimeException('Could not identify multipart assembly inode.');
            }
            $tempLease = @fopen($tempPath, 'rb');
            $leaseInode = $tempLease !== false ? @fstat($tempLease) : false;
            if ($tempLease === false || ! is_array($leaseInode)
                || $leaseInode['dev'] !== $writtenInode['dev'] || $leaseInode['ino'] !== $writtenInode['ino']
                || ! @flock($tempLease, LOCK_SH | LOCK_NB)
                || ! @flock($temp, LOCK_UN) || ! @fclose($temp)) {
                throw new \RuntimeException('Could not close synchronized multipart assembly inode.');
            }
            $temp = null;
            $verified = @fopen($tempPath, 'rb');
            if ($verified === false) {
                throw new \RuntimeException('Could not reopen multipart assembly inode.');
            }
            try {
                $opened = @fstat($verified);
                if ($opened === false || $opened['dev'] !== $writtenInode['dev'] || $opened['ino'] !== $writtenInode['ino']) {
                    throw new \RuntimeException('Multipart assembly inode changed before verification.');
                }
                $physical = new ChecksumCalculator();
                while (! feof($verified)) {
                    $chunk = @fread($verified, self::CHUNK_BYTES);
                    if ($chunk === false || ($chunk === '' && ! feof($verified))) {
                        throw new \RuntimeException('Could not verify multipart assembly inode.');
                    }
                    if ($chunk !== '') {
                        $physical->update($chunk);
                    }
                }
            } finally {
                @fclose($verified);
            }
            $actual = $physical->finalize();
            if ($actual->size !== $checksums->size
                || ! hash_equals($checksums->sha256Hex, $actual->sha256Hex)) {
                throw new \RuntimeException('Multipart assembly inode failed physical integrity verification.');
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
                    throw new \RuntimeException('Could not durably remove multipart assembly inode.');
                }
            } else {
                $this->durability->delete($tempPath);
            }
            $tempPath = '';

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
                @flock($temp, LOCK_UN);
                @fclose($temp);
            }
            if (is_resource($tempLease)) {
                @flock($tempLease, LOCK_UN);
                @fclose($tempLease);
            }
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
            throw new \InvalidArgumentException('Multipart assembly directory escapes its root.');
        }
        $current = rtrim($this->root, '/');
        $expected = $this->canonicalRoot;
        foreach (explode('/', substr($path, strlen($current) + 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid multipart assembly directory.');
            }
            $current .= '/' . $segment;
            $expected .= '/' . $segment;
            clearstatcache(true, $current);
            if (is_link($current) || (! is_dir($current) && ! @mkdir($current, 0700) && ! is_dir($current))
                || realpath($current) !== $expected) {
                throw new \RuntimeException('Multipart assembly directory is unsafe or unavailable.');
            }
            $entry = @lstat($current);
            if ($entry === false || ($entry['mode'] & 0o170000) !== 0o040000
                || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('Multipart assembly directory must be private and process-owned.');
            }
        }
        $this->durability->ensureDirectory($path);
    }
}
