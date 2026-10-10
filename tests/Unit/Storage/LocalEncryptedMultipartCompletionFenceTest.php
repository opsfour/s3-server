<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Storage\LocalEncryptedMultipartCompletionFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalEncryptedMultipartCompletionFenceTest extends TestCase
{
    private string $root;

    private EncryptionService $encryption;

    private string $customerKey;

    private string $generation;

    private string $attemptId;

    private string $uploadId;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-local-sse-complete-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $this->customerKey = random_bytes(32);
        $this->generation = bin2hex(random_bytes(16));
        $this->attemptId = bin2hex(random_bytes(16));
        $this->uploadId = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'SSE-S3' => [EncryptionRequestResolver::SSE_S3];
        yield 'SSE-C' => [EncryptionRequestResolver::SSE_C];
    }

    #[DataProvider('modes')]
    public function test_candidate_writes_only_verified_final_ciphertext(string $mode): void
    {
        $first = 'SENSITIVE-PLAINTEXT-PART-ONE-' . str_repeat('a', 63);
        $second = 'SENSITIVE-PLAINTEXT-PART-TWO-' . str_repeat('b', 59);
        $parts = [
            $this->part($mode, 1, $first),
            $this->part($mode, 2, $second),
        ];
        $target = $this->target();
        $result = $this->fence()->complete(
            'bucket',
            'key',
            $this->generation,
            $this->attemptId,
            $this->uploadId,
            $parts,
            $target,
            $mode,
            $mode === EncryptionRequestResolver::SSE_C ? $this->customerKey : null,
        );

        self::assertSame($target, $result->write->path);
        self::assertSame(strlen($first . $second), $result->write->size);
        self::assertSame(md5(hex2bin(md5($first)) . hex2bin(md5($second))) . '-2', $result->write->md5Hex);
        $ciphertext = file_get_contents($target);
        self::assertIsString($ciphertext);
        self::assertSame(strlen($first . $second), strlen($ciphertext));
        self::assertNotSame($first . $second, $ciphertext);
        self::assertStringNotContainsString('SENSITIVE-PLAINTEXT', $ciphertext);
        if ($mode === EncryptionRequestResolver::SSE_C) {
            self::assertSame(base64_encode(md5($this->customerKey, true)), $result->encryptionMetadata['sse-customer-key-md5']);
            $decrypted = $this->encryption->decryptSseC(
                $ciphertext,
                $this->customerKey,
                $result->encryptionMetadata['sse-iv'],
                $result->encryptionMetadata['sse-tag'],
            );
        } else {
            $decrypted = $this->encryption->decryptSseS3(
                $ciphertext,
                $result->encryptionMetadata['sse-key'],
                $result->encryptionMetadata['sse-iv'],
                $result->encryptionMetadata['sse-tag'],
            );
        }
        self::assertSame($first . $second, $decrypted);
        self::assertSame([], glob($this->root . '/.tmp/*') ?: []);
        foreach (glob($this->root . '/.parts/' . $this->uploadId . '/*') ?: [] as $path) {
            self::assertStringNotContainsString('SENSITIVE-PLAINTEXT', (string) file_get_contents($path));
        }
    }

    #[DataProvider('modes')]
    public function test_tampered_part_never_creates_a_final_object(string $mode): void
    {
        $part = $this->part($mode, 1, 'secret part');
        $bytes = file_get_contents($part['storagePath']);
        self::assertIsString($bytes);
        $lastByte = substr($bytes, -1) === 'X' ? 'Y' : 'X';
        self::assertSame(strlen($bytes), file_put_contents($part['storagePath'], substr($bytes, 0, -1) . $lastByte));

        try {
            $this->fence()->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                $mode,
                $mode === EncryptionRequestResolver::SSE_C ? $this->customerKey : null,
            );
            self::fail('A changed ciphertext part was accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->target());
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
        }
    }

    public function test_replayed_part_from_another_upload_is_rejected_before_disk_write(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_S3, 1, 'secret part');
        $otherUploadId = bin2hex(random_bytes(16));
        $otherPath = StoragePath::forPart($this->root, $otherUploadId, 1);
        self::assertTrue(mkdir(dirname($otherPath), 0700, true));
        self::assertTrue(copy($part['storagePath'], $otherPath));
        $part['storagePath'] = $otherPath;

        try {
            $this->fence()->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $otherUploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_S3,
            );
            self::fail('A replayed ciphertext part was accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->target());
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
        }
    }

    public function test_wrong_sse_c_customer_key_is_rejected_before_disk_write(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_C, 1, 'secret part');
        try {
            $this->fence()->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_C,
                random_bytes(32),
            );
            self::fail('A different customer key was accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->target());
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
        }
    }

    public function test_symlinked_part_directory_is_rejected_before_reading_bytes(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_S3, 1, 'secret part');
        $uploadDirectory = dirname($part['storagePath']);
        $otherDirectory = $this->root . '/.parts/other';
        self::assertTrue(rename($uploadDirectory, $otherDirectory));
        self::assertTrue(symlink($otherDirectory, $uploadDirectory));
        try {
            $this->fence()->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_S3,
            );
            self::fail('A symbolic-link part directory was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('part directory', $error->getMessage());
            self::assertFileDoesNotExist($this->target());
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
        }
    }

    public function test_same_length_post_sync_corruption_is_rejected_before_link(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_S3, 1, 'secret part');
        $io = new class implements LocalObjectWriteIo {
            private NativeLocalObjectWriteIo $native;

            public function __construct()
            {
                $this->native = new NativeLocalObjectWriteIo();
            }

            public function syncFile($file): bool
            {
                if (! $this->native->syncFile($file)) {
                    return false;
                }
                $position = ftell($file);
                if ($position === false) {
                    return false;
                }
                fseek($file, 0);
                fwrite($file, 'X');
                fseek($file, $position);

                return true;
            }

            public function syncDirectory(string $path): bool
            {
                return $this->native->syncDirectory($path);
            }

            public function link(string $source, string $target): bool
            {
                return $this->native->link($source, $target);
            }

            public function rename(string $source, string $target): bool
            {
                return $this->native->rename($source, $target);
            }
        };

        try {
            (new LocalEncryptedMultipartCompletionFence($this->root, $this->encryption, 1024, $io))->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_S3,
            );
            self::fail('A corrupted final ciphertext inode was published.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('physical verification', $error->getMessage());
            self::assertFileDoesNotExist($this->target());
            self::assertSame([], glob($this->root . '/.tmp/*') ?: []);
        }
    }

    public function test_bad_path_private_mode_and_size_limit_fail_before_publication(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_S3, 1, 'secret part');
        $wrongPath = $part;
        $wrongPath['storagePath'] = $this->root . '/outside';
        foreach ([$wrongPath, $part] as $index => $candidate) {
            if ($index === 1) {
                chmod($part['storagePath'], 0644);
            }
            try {
                $this->fence()->complete(
                    'bucket',
                    'key',
                    $this->generation,
                    $this->attemptId,
                    $this->uploadId,
                    [$candidate],
                    $this->target(),
                    EncryptionRequestResolver::SSE_S3,
                );
                self::fail('An unsafe part was accepted.');
            } catch (\InvalidArgumentException|\RuntimeException) {
                self::assertFileDoesNotExist($this->target());
            }
        }
        chmod($part['storagePath'], 0600);
        try {
            (new LocalEncryptedMultipartCompletionFence($this->root, $this->encryption, 1))->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_S3,
            );
            self::fail('The plaintext limit was bypassed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('plaintext limit', $error->getMessage());
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
        }
    }

    public function test_memory_headroom_rejects_completion_before_reading_or_writing_files(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_C, 1, str_repeat('x', 1_048_576));
        $previousLimit = ini_get('memory_limit');
        self::assertIsString($previousLimit);
        $limit = memory_get_usage(true) + 16_777_216 + 2_097_152;
        self::assertNotFalse(ini_set('memory_limit', (string) $limit));
        try {
            (new LocalEncryptedMultipartCompletionFence($this->root, $this->encryption, 2_097_152))->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_C,
                $this->customerKey,
            );
            self::fail('Completion ignored the process memory headroom limit.');
        } catch (EntityTooLargeException $error) {
            self::assertStringContainsString('available memory', $error->getMessage());
        } finally {
            ini_set('memory_limit', $previousLimit);
        }

        self::assertFileExists($part['storagePath']);
        self::assertFileDoesNotExist($this->target());
        self::assertDirectoryDoesNotExist($this->root . '/.tmp');
        self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
    }

    public function test_existing_final_path_survives_create_only_collision(): void
    {
        $part = $this->part(EncryptionRequestResolver::SSE_S3, 1, 'secret part');
        self::assertTrue(mkdir(dirname($this->target()), 0700, true));
        self::assertSame(8, file_put_contents($this->target(), 'old-data'));
        try {
            $this->fence()->complete(
                'bucket',
                'key',
                $this->generation,
                $this->attemptId,
                $this->uploadId,
                [$part],
                $this->target(),
                EncryptionRequestResolver::SSE_S3,
            );
            self::fail('An existing final path was overwritten.');
        } catch (\RuntimeException) {
            self::assertSame('old-data', file_get_contents($this->target()));
            self::assertSame([], glob($this->root . '/.tmp/*') ?: []);
        }
    }

    /** @return array{partNumber: int, etag: string, size: int, storagePath: string} */
    private function part(string $mode, int $partNumber, string $plaintext): array
    {
        $path = StoragePath::forPart($this->root, $this->uploadId, $partNumber);
        if (! is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0700, true));
        }
        $encoded = EncryptedMultipartPartCodec::encode(
            $this->encryption,
            $mode,
            $plaintext,
            $mode === EncryptionRequestResolver::SSE_C ? $this->customerKey : null,
            $this->generation,
            'bucket',
            $this->uploadId,
            $partNumber,
        );
        self::assertSame(strlen($encoded), file_put_contents($path, $encoded));
        self::assertTrue(chmod($path, 0600));

        return ['partNumber' => $partNumber, 'etag' => md5($plaintext), 'size' => strlen($plaintext), 'storagePath' => $path];
    }

    private function target(): string
    {
        return StoragePath::forMultipartCompletion($this->root, 'bucket', 'key', $this->generation, $this->attemptId)['final'];
    }

    private function fence(): LocalEncryptedMultipartCompletionFence
    {
        return new LocalEncryptedMultipartCompletionFence($this->root, $this->encryption, 1024);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
