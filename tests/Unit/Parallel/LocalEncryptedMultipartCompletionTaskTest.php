<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureException;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Parallel\LocalEncryptedMultipartCompletionTask;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalEncryptedMultipartCompletionTaskTest extends TestCase
{
    private string $root;

    private string $originalKey;

    private string $originalKeys;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/s3-encrypted-completion-task-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $canonical = realpath($root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->originalKey = getenv('S3_ENCRYPTION_MASTER_KEY') ?: '';
        $this->originalKeys = getenv('S3_ENCRYPTION_MASTER_KEYS') ?: '';
    }

    protected function tearDown(): void
    {
        putenv($this->originalKey === '' ? 'S3_ENCRYPTION_MASTER_KEY' : 'S3_ENCRYPTION_MASTER_KEY=' . $this->originalKey);
        putenv($this->originalKeys === '' ? 'S3_ENCRYPTION_MASTER_KEYS' : 'S3_ENCRYPTION_MASTER_KEYS=' . $this->originalKeys);
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'SSE-S3' => [EncryptionRequestResolver::SSE_S3];
        yield 'SSE-C without master key' => [EncryptionRequestResolver::SSE_C];
    }

    #[DataProvider('modes')]
    public function test_real_process_rejects_missing_host_evidence_before_touching_parts_or_final_path(string $mode): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEYS');
        putenv('S3_ENCRYPTION_MASTER_KEY');
        $customerKey = $mode === EncryptionRequestResolver::SSE_C ? random_bytes(32) : null;
        $fingerprint = '';
        if ($mode === EncryptionRequestResolver::SSE_S3) {
            putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
            $fingerprint = (new ConfigMasterKeyProvider())->keyringFingerprint();
        }

        $uploadId = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $attemptId = bin2hex(random_bytes(16));
        $partPath = StoragePath::forPart($this->root, $uploadId, 1);
        self::assertTrue(mkdir(dirname($partPath), 0700, true));
        $plaintext = 'sensitive multipart payload';
        $encoded = EncryptedMultipartPartCodec::encode(
            new EncryptionService($mode === EncryptionRequestResolver::SSE_S3 ? new ConfigMasterKeyProvider() : null),
            $mode,
            $plaintext,
            $customerKey,
            $generation,
            'bucket',
            $uploadId,
            1,
        );
        self::assertSame(strlen($encoded), file_put_contents($partPath, $encoded));
        self::assertTrue(chmod($partPath, 0600));
        $planned = StoragePath::forMultipartCompletion($this->root, 'bucket', 'key', $generation, $attemptId);
        $task = new LocalEncryptedMultipartCompletionTask(
            new EfsWorkerEvidenceScope(
                $this->root . '/absent-evidence',
                $this->root,
                bin2hex(random_bytes(16)),
                'fs-12345678',
                'api-one',
                'postgres',
                hash('sha256', 'test source'),
                '1',
                '1',
                '1',
                '1',
                [],
            ),
            'bucket',
            'key',
            $generation,
            $attemptId,
            $uploadId,
            [['partNumber' => 1, 'etag' => md5($plaintext), 'size' => strlen($plaintext), 'storagePath' => $partPath]],
            $planned['final'],
            $mode,
            1024,
            $customerKey,
            $fingerprint,
        );
        $pool = new ContextWorkerPool(1);
        try {
            $pool->submit($task)->getFuture()->await();
            self::fail('A worker without host evidence completed an encrypted upload.');
        } catch (TaskFailureException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            self::assertSame($encoded, file_get_contents($partPath));
            self::assertFileDoesNotExist($planned['final']);
            self::assertDirectoryDoesNotExist($this->root . '/.tmp');
        } finally {
            $pool->shutdown();
        }
    }
}
