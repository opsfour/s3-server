<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;

/**
 * Blocking candidate-only SSE-v2 completion. Plaintext exists only in this
 * process's memory; only final ciphertext may reach the storage filesystem.
 * The caller must durably arm the exact attempt before dispatching this work.
 *
 * @internal No production handler dispatches this candidate yet.
 */
final readonly class LocalEncryptedMultipartCompletionFence
{
    private const int CHUNK_BYTES = 65_536;

    private string $canonicalRoot;

    public function __construct(
        private string $root,
        private EncryptionServiceInterface $encryption,
        private int $maxPlaintextBytes,
        private LocalObjectWriteIo $io = new NativeLocalObjectWriteIo(),
        private ?EfsHostEvidenceReader $evidence = null,
    ) {
        if ($maxPlaintextBytes < 1 || ($evidence !== null && $root !== $evidence->storageRoot())) {
            throw new \InvalidArgumentException('Encrypted completion root or size limit is invalid.');
        }
        $canonical = $evidence !== null ? $root : realpath($root);
        if ($canonical === false || ! is_dir($canonical)) {
            throw new \InvalidArgumentException('Encrypted completion root is unavailable.');
        }
        $this->canonicalRoot = rtrim($canonical, '/');
    }

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function complete(
        string $bucket,
        string $key,
        string $generation,
        string $attemptId,
        string $uploadId,
        array $parts,
        string $target,
        string $mode,
        ?string $customerKey = null,
    ): LocalEncryptedMultipartCompletionResult {
        $this->evidence?->assertFresh();
        $planned = StoragePath::forMultipartCompletion($this->root, $bucket, $key, $generation, $attemptId);
        StoragePath::assertSafeUploadIdSegment($uploadId);
        if ($target !== $planned['final'] || $parts === [] || count($parts) > 10_000
            || ! in_array($mode, [EncryptionRequestResolver::SSE_C, EncryptionRequestResolver::SSE_S3], true)
            || ($mode === EncryptionRequestResolver::SSE_C && ($customerKey === null || strlen($customerKey) !== 32))
            || ($mode === EncryptionRequestResolver::SSE_S3 && $customerKey !== null)) {
            throw new \InvalidArgumentException('Encrypted completion attempt or mode is invalid.');
        }

        $prefix = rtrim($this->root, '/') . '/.parts/' . $uploadId . '/';
        $previous = 0;
        $expectedSize = 0;
        foreach ($parts as $part) {
            $number = $part['partNumber'];
            $etag = strtolower(trim($part['etag'], '"'));
            if ($number <= $previous || $number > 10_000
                || $part['size'] < 0 || $part['size'] > MultipartPartSize::MAX_BYTES
                || ! preg_match('/^[a-f0-9]{32}$/D', $etag)
                || ! str_starts_with($part['storagePath'], $prefix)
                || ! preg_match('/^' . $number . '-[a-f0-9]{32}(?:-[a-f0-9]{32})?$/D', substr($part['storagePath'], strlen($prefix)))) {
                throw new \InvalidArgumentException('Encrypted completion part is invalid.');
            }
            if ($part['size'] > $this->maxPlaintextBytes - $expectedSize) {
                throw new \RuntimeException('Encrypted completion exceeds its plaintext limit.');
            }
            $expectedSize += $part['size'];
            $previous = $number;
        }

        BufferedSsePayload::assertMemoryHeadroom($expectedSize);
        $this->assertPrivatePartDirectory($uploadId);

        $plaintext = '';
        $partMd5s = '';
        foreach ($parts as $part) {
            $stored = $this->readExactPrivatePart($part['storagePath'], $part['size'] + EncryptedMultipartPartCodec::MAX_OVERHEAD);
            $plainPart = EncryptedMultipartPartCodec::decode(
                $this->encryption,
                $mode,
                $stored,
                $customerKey,
                $generation,
                $bucket,
                $uploadId,
                $part['partNumber'],
            );
            $etag = strtolower(trim($part['etag'], '"'));
            if (strlen($plainPart) !== $part['size'] || ! hash_equals($etag, md5($plainPart))) {
                throw new \RuntimeException('Encrypted completion part failed logical verification.');
            }
            $plaintext .= $plainPart;
            $partMd5s .= hex2bin($etag);
        }
        if (strlen($plaintext) !== $expectedSize) {
            throw new \RuntimeException('Encrypted completion size changed while reading parts.');
        }

        if ($mode === EncryptionRequestResolver::SSE_C) {
            \assert($customerKey !== null);
            $encrypted = $this->encryption->encryptSseC($plaintext, $customerKey);
            $metadata = [
                'sse-algorithm' => 'SSE-C',
                'sse-iv' => $encrypted['iv'],
                'sse-tag' => $encrypted['tag'],
                'sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
            ];
        } else {
            $encrypted = $this->encryption->encryptSseS3($plaintext);
            $metadata = [
                'sse-algorithm' => 'AES256',
                'sse-key' => $encrypted['encryptedDataKey'],
                'sse-iv' => $encrypted['iv'],
                'sse-tag' => $encrypted['tag'],
            ];
        }
        $ciphertext = $encrypted['ciphertext'];
        if (strlen($ciphertext) !== $expectedSize) {
            throw new \RuntimeException('Encrypted completion ciphertext size is invalid.');
        }
        // Drop the concatenated plaintext before opening any writable file.
        unset($plaintext, $plainPart, $stored);
        $this->ensureDirectory($this->root . '/.tmp');
        $this->ensureDirectory(dirname($target));
        $this->evidence?->assertFresh();
        $tempPath = $this->root . '/.tmp/' . bin2hex(random_bytes(16));
        $file = @fopen($tempPath, 'x+b');
        if ($file === false) {
            throw new \RuntimeException('Could not create encrypted completion staging inode.');
        }
        $lease = null;
        try {
            if (! @chmod($tempPath, 0600) || ! @flock($file, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Could not protect encrypted completion staging inode.');
            }
            $offset = 0;
            while ($offset < strlen($ciphertext)) {
                $this->evidence?->assertFresh();
                $written = @fwrite($file, substr($ciphertext, $offset, self::CHUNK_BYTES));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Could not write encrypted completion staging inode.');
                }
                $offset += $written;
            }
            $this->evidence?->assertFresh();
            if (! $this->io->syncFile($file)) {
                throw new \RuntimeException('Could not synchronize encrypted completion staging inode.');
            }
            $writtenInode = @fstat($file);
            if ($writtenInode === false) {
                throw new \RuntimeException('Could not identify encrypted completion staging inode.');
            }
            $lease = @fopen($tempPath, 'rb');
            $leaseInode = $lease !== false ? @fstat($lease) : false;
            if ($lease === false || $leaseInode === false
                || $leaseInode['dev'] !== $writtenInode['dev'] || $leaseInode['ino'] !== $writtenInode['ino']
                || ! @flock($lease, LOCK_SH | LOCK_NB)
                || ! @flock($file, LOCK_UN) || ! @fclose($file)) {
                throw new \RuntimeException('Could not close encrypted completion staging inode.');
            }
            $file = null;
            $verified = $this->readBackCiphertext($tempPath, $writtenInode['dev'], $writtenInode['ino'], strlen($ciphertext));
            if (! hash_equals(hash('sha256', $ciphertext), $verified)) {
                throw new \RuntimeException('Encrypted completion staging inode failed physical verification.');
            }
            $this->evidence?->assertFresh();
            (new LocalMultipartPathFence($this->root, $this->io, $this->evidence))->publishVerifiedTemp(
                $tempPath,
                $target,
                $writtenInode['dev'],
                $writtenInode['ino'],
            );

            return new LocalEncryptedMultipartCompletionResult(
                new StorageWriteResult($target, $expectedSize, md5($partMd5s) . '-' . count($parts)),
                $metadata,
            );
        } finally {
            if (is_resource($file)) {
                @flock($file, LOCK_UN);
                @fclose($file);
            }
            if (is_resource($lease)) {
                @flock($lease, LOCK_UN);
                @fclose($lease);
            }
            try {
                $this->evidence?->assertFresh();
                @unlink($tempPath);
            } catch (\Throwable) {
                // The temp collector handles an orphan after evidence loss.
            }
        }
    }

    private function readExactPrivatePart(string $path, int $maxBytes): string
    {
        $this->evidence?->assertFresh();
        $entry = @lstat($path);
        if ($entry === false || ($entry['mode'] & 0o170000) !== 0o100000
            || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
            throw new \RuntimeException('Encrypted completion part is unavailable or unsafe.');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Encrypted completion part could not be opened.');
        }
        try {
            $opened = @fstat($file);
            if ($opened === false || $opened['dev'] !== $entry['dev'] || $opened['ino'] !== $entry['ino']) {
                throw new \RuntimeException('Encrypted completion part changed while opening.');
            }
            $stored = '';
            while (! feof($file)) {
                $this->evidence?->assertFresh();
                $remaining = $maxBytes - strlen($stored);
                if ($remaining < 0) {
                    throw new \RuntimeException('Encrypted completion part exceeds its encoded limit.');
                }
                $chunk = @fread($file, min(self::CHUNK_BYTES, $remaining + 1));
                if ($chunk === false || ($chunk === '' && ! feof($file))) {
                    throw new \RuntimeException('Encrypted completion part could not be read completely.');
                }
                $stored .= $chunk;
                if (strlen($stored) > $maxBytes) {
                    throw new \RuntimeException('Encrypted completion part exceeds its encoded limit.');
                }
            }
            return $stored;
        } finally {
            @fclose($file);
        }
    }

    private function assertPrivatePartDirectory(string $uploadId): void
    {
        foreach ([$this->root . '/.parts', $this->root . '/.parts/' . $uploadId] as $path) {
            $this->evidence?->assertFresh();
            clearstatcache(true, $path);
            $entry = @lstat($path);
            if ($entry === false || ($entry['mode'] & 0o170000) !== 0o040000
                || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()
                || realpath($path) !== $this->canonicalRoot . substr($path, strlen(rtrim($this->root, '/')))) {
                throw new \RuntimeException('Encrypted completion part directory is unsafe or unavailable.');
            }
        }
    }

    private function readBackCiphertext(string $path, int $device, int $inode, int $size): string
    {
        $this->evidence?->assertFresh();
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Encrypted completion staging inode could not be reopened.');
        }
        try {
            $opened = @fstat($file);
            if ($opened === false || $opened['dev'] !== $device || $opened['ino'] !== $inode || $opened['size'] !== $size) {
                throw new \RuntimeException('Encrypted completion staging inode changed before verification.');
            }
            $hash = hash_init('sha256');
            $read = 0;
            while (! feof($file)) {
                $this->evidence?->assertFresh();
                $chunk = @fread($file, self::CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && ! feof($file))) {
                    throw new \RuntimeException('Encrypted completion staging inode could not be verified.');
                }
                $read += strlen($chunk);
                if ($read > $size) {
                    throw new \RuntimeException('Encrypted completion staging inode grew during verification.');
                }
                hash_update($hash, $chunk);
            }
            if ($read !== $size) {
                throw new \RuntimeException('Encrypted completion staging inode changed size during verification.');
            }
            return hash_final($hash);
        } finally {
            @fclose($file);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! str_starts_with($path, rtrim($this->root, '/') . '/')) {
            throw new \InvalidArgumentException('Encrypted completion directory escapes its root.');
        }
        $current = rtrim($this->root, '/');
        $expected = $this->canonicalRoot;
        foreach (explode('/', substr($path, strlen($current) + 1)) as $segment) {
            $this->evidence?->assertFresh();
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid encrypted completion directory.');
            }
            $current .= '/' . $segment;
            $expected .= '/' . $segment;
            clearstatcache(true, $current);
            if (is_link($current) || (! is_dir($current) && ! @mkdir($current, 0700) && ! is_dir($current))
                || realpath($current) !== $expected) {
                throw new \RuntimeException('Encrypted completion directory is unsafe or unavailable.');
            }
            $entry = @lstat($current);
            if ($entry === false || ($entry['mode'] & 0o170000) !== 0o040000
                || ($entry['mode'] & 0o077) !== 0 || $entry['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('Encrypted completion directory must be private and process-owned.');
            }
        }
    }
}
