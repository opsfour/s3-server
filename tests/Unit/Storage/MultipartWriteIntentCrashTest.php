<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MultipartWriteIntentCrashTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function crashPhases(): iterable
    {
        yield 'after assembly' => ['assembly'];
        yield 'after armed local assembly' => ['armed-assembly'];
        yield 'after SSE ciphertext write' => ['ciphertext'];
        yield 'after encrypted part read' => ['encrypted-read'];
        yield 'after metadata commit' => ['committed'];
    }

    #[DataProvider('crashPhases')]
    public function test_process_exit_leaves_recoverable_paths_and_active_upload(string $phase): void
    {
        $root = sys_get_temp_dir() . '/s3-mpu-crash-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $databasePath = $root . '/metadata.sqlite';
        $storagePath = $root . '/storage';
        mkdir($storagePath, 0700);

        try {
            $worker = dirname(__DIR__, 2) . '/Support/multipart-intent-crash-worker.php';
            $process = new Process([PHP_BINARY, $worker, $databasePath, $storagePath, $phase]);
            $process->setTimeout(30);
            $process->run();
            self::assertSame(71, $process->getExitCode(), $process->getErrorOutput());

            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $pdo = new \PDO('sqlite:' . $databasePath);
            $uploadId = file_get_contents($storagePath . '/upload-id');
            self::assertIsString($uploadId);
            if ($phase === 'committed') {
                $count = $pdo->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
                self::assertInstanceOf(\PDOStatement::class, $count);
                self::assertSame(0, (int) $count->fetchColumn());
                $object = $metadata->getObjectMetadata('bucket', 'key');
                self::assertNotNull($object);
                $committedPath = $object->systemMetadata['storagePath'] ?? null;
                self::assertIsString($committedPath);
                self::assertFileExists($committedPath);
                self::assertNull($metadata->getMultipartUpload($uploadId));

                return;
            }
            $attemptQuery = $pdo->query('SELECT attempt_id FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $attemptQuery);
            $attemptId = $attemptQuery->fetchColumn();
            self::assertIsString($attemptId);
            $intent = $metadata->getMultipartWriteIntent($attemptId);
            self::assertNotNull($intent);
            self::assertSame($uploadId, $intent->uploadId);
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
            $upload = $metadata->getMultipartUpload($uploadId);
            self::assertNotNull($upload);
            if (in_array($phase, ['assembly', 'armed-assembly'], true)) {
                self::assertFileExists($intent->assemblyPath);
            } else {
                self::assertFileDoesNotExist($intent->assemblyPath);
            }
            if ($phase === 'ciphertext') {
                self::assertFileExists($intent->finalPath);
            } else {
                self::assertFileDoesNotExist($intent->finalPath);
            }
            if (in_array($phase, ['ciphertext', 'encrypted-read'], true)) {
                self::assertSame(EncryptedMultipartPartCodec::FORMAT, $upload['user_metadata']['__mpu-part-format'] ?? null);
                self::assertSame('AES256', $intent->sseMode);
                $parts = $metadata->getParts($uploadId);
                self::assertCount(1, $parts);
                $partPath = $parts[0]['storage_path'];
                self::assertFileExists($partPath);
                $storedPart = file_get_contents($partPath);
                self::assertIsString($storedPart);
                self::assertStringStartsWith("S3MPU2\0", $storedPart);
                self::assertStringNotContainsString('multipart bytes', $storedPart);
            }

            $expire = $pdo->prepare('UPDATE s3_multipart_write_intents SET lease_until = ?, next_attempt_at = ? WHERE attempt_id = ?');
            self::assertInstanceOf(\PDOStatement::class, $expire);
            $expire->execute([microtime(true) - 1, microtime(true) - 1, $attemptId]);
            $storage = new FilesystemBackend($storagePath);
            try {
                $collector = new MultipartWriteIntentCollector($metadata, StorageTierRegistry::single($storage));
                self::assertSame(1, $collector->collect());
            } finally {
                $storage->shutdown();
            }

            foreach ([$intent->assemblyPath, $intent->finalPath] as $sealedPath) {
                $seal = file_get_contents($sealedPath);
                self::assertIsString($seal);
                self::assertStringStartsWith(
                    "OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attemptId . "\n",
                    $seal,
                );
            }
            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attemptId)?->state);
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $metadata->getParts($uploadId));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
