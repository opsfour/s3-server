<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\Parallel\Worker\TaskFailureException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalMultipartAssemblyFence;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Tests\Support\LocalMultipartLinkAckFaultIo;
use OpsFour\S3Server\Tests\Support\LocalMultipartSyncCorruptIo;
use PHPUnit\Framework\TestCase;

final class LocalMultipartAssemblyFenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/s3-assembly-fence-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $root = realpath($directory);
        self::assertIsString($root);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_streams_selected_parts_and_returns_physical_checksums(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first'), $this->part($uploadId, 3, 'second')];
        $target = $this->target();

        $result = (new LocalMultipartAssemblyFence($this->root))->assemble(
            'bucket',
            'key',
            str_repeat('a', 32),
            str_repeat('b', 32),
            $uploadId,
            $parts,
            $target,
        );

        self::assertSame('firstsecond', file_get_contents($target));
        self::assertSame(strlen('firstsecond'), $result->size);
        self::assertSame(md5(hex2bin(md5('first')) . hex2bin(md5('second'))) . '-2', $result->md5Hex);
        self::assertSame(base64_encode(hash('sha256', 'firstsecond', true)), $result->sha256Base64);
        self::assertSame([], array_diff(scandir($this->root . '/.tmp') ?: [], ['.', '..']));
    }

    public function test_same_size_part_corruption_never_publishes(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first')];
        self::assertSame(5, file_put_contents($parts[0]['storagePath'], 'other'));
        $target = $this->target();

        try {
            (new LocalMultipartAssemblyFence($this->root))->assemble(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('b', 32),
                $uploadId,
                $parts,
                $target,
            );
            self::fail('A corrupted selected part was published.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('checksum', $error->getMessage());
            self::assertFileDoesNotExist($target);
            self::assertSame([], array_diff(scandir($this->root . '/.tmp') ?: [], ['.', '..']));
        }
    }

    public function test_changed_synced_inode_is_rejected_before_create_only_link(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first')];
        $target = $this->target();

        try {
            (new LocalMultipartAssemblyFence($this->root, new LocalMultipartSyncCorruptIo()))->assemble(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('b', 32),
                $uploadId,
                $parts,
                $target,
            );
            self::fail('A changed synchronized assembly inode was published.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('physical integrity', $error->getMessage());
            self::assertFileDoesNotExist($target);
        }
    }

    public function test_lost_link_ack_leaves_only_exact_attempt_path_for_collector(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first')];
        $target = $this->target();

        try {
            (new LocalMultipartAssemblyFence($this->root, new LocalMultipartLinkAckFaultIo()))->assemble(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('b', 32),
                $uploadId,
                $parts,
                $target,
            );
            self::fail('A lost create-only link acknowledgement was treated as definite success.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('uncertain', $error->getMessage());
            self::assertSame('first', file_get_contents($target));
            self::assertSame([], array_diff(scandir($this->root . '/.tmp') ?: [], ['.', '..']));
        }
    }

    public function test_wrong_attempt_and_symlinked_part_are_rejected(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first')];
        $fence = new LocalMultipartAssemblyFence($this->root);
        $wrongTarget = StoragePath::forMultipartCompletion($this->root, 'bucket', 'key', str_repeat('a', 32), str_repeat('c', 32))['assembly'];
        try {
            $fence->assemble('bucket', 'key', str_repeat('a', 32), str_repeat('b', 32), $uploadId, $parts, $wrongTarget);
            self::fail('An unrelated completion attempt path was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($wrongTarget);
        }
        $partPath = $parts[0]['storagePath'];
        self::assertTrue(rename($partPath, $partPath . '.saved'));
        self::assertTrue(symlink($partPath . '.saved', $partPath));
        try {
            $fence->assemble('bucket', 'key', str_repeat('a', 32), str_repeat('b', 32), $uploadId, $parts, $this->target());
            self::fail('A symlinked part was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unsafe', $error->getMessage());
        }
    }

    public function test_backend_submits_fenced_assembly_to_worker(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $parts = [$this->part($uploadId, 1, 'first')];
        $target = $this->target();
        $backend = new FilesystemBackend($this->root);
        try {
            $result = $backend->assembleMultipartUploadFencedAtPath(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('b', 32),
                $uploadId,
                $parts,
                $target,
            );
            self::assertSame($target, $result->path);
            self::assertSame('first', file_get_contents($target));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_worker_task_failure_releases_admission_for_next_completion(): void
    {
        $backend = new FilesystemBackend($this->root);
        $uploadId = bin2hex(random_bytes(16));
        try {
            try {
                $backend->assembleMultipartUploadFencedAtPath(
                    'bucket',
                    'key',
                    str_repeat('a', 32),
                    str_repeat('b', 32),
                    $uploadId,
                    [],
                    $this->target(),
                );
                self::fail('An invalid assembly task was treated as complete.');
            } catch (TaskFailureException) {
                self::assertSame(0, $this->admissions($backend));
            }
            $parts = [$this->part($uploadId, 1, 'first')];
            $result = $backend->assembleMultipartUploadFencedAtPath(
                'bucket',
                'key',
                str_repeat('a', 32),
                str_repeat('b', 32),
                $uploadId,
                $parts,
                $this->target(),
            );
            self::assertSame('first', file_get_contents($result->path));
            self::assertSame(0, $this->admissions($backend));
        } finally {
            $backend->shutdown();
        }
    }

    public function test_full_admission_fails_before_assembly_worker_pool_or_storage_io(): void
    {
        $backend = new FilesystemBackend($this->root);
        $admissions = new \ReflectionProperty($backend, 'multipartFenceAdmissions');
        $admissions->setValue($backend, 2);
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                try {
                    $backend->assembleMultipartUploadFencedAtPath(
                        'bucket',
                        'key',
                        str_repeat('a', 32),
                        str_repeat('b', 32),
                        bin2hex(random_bytes(16)),
                        [],
                        $this->target(),
                    );
                    self::fail('A full multipart fence pool accepted another completion.');
                } catch (SlowDownException $error) {
                    self::assertSame(503, $error->getHttpStatus());
                }
            }
            self::assertSame(2, $this->admissions($backend));
            self::assertNull((new \ReflectionProperty($backend, 'multipartFencePool'))->getValue($backend));
            self::assertDirectoryDoesNotExist($this->root . '/.assemblies');
            self::assertDirectoryDoesNotExist($this->root . '/.tmp');
        } finally {
            $backend->shutdown();
        }
    }

    public function test_ambiguous_worker_response_keeps_admission_until_shutdown(): void
    {
        $backend = new FilesystemBackend($this->root);
        $run = new \ReflectionMethod($backend, 'runMultipartFenceTask');
        try {
            try {
                $run->invoke($backend, static function (): never {
                    throw new \RuntimeException('IPC acknowledgement was lost.');
                });
                self::fail('An ambiguous worker response was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('IPC acknowledgement was lost.', $error->getMessage());
                self::assertSame(1, $this->admissions($backend));
            }
            self::assertNull($run->invoke($backend, static fn(): null => null));
            self::assertSame(1, $this->admissions($backend));
        } finally {
            $backend->shutdown();
        }
        self::assertSame(0, $this->admissions($backend));
    }

    /** @return array{partNumber: int, etag: string, size: int, storagePath: string} */
    private function part(string $uploadId, int $number, string $body): array
    {
        $directory = $this->root . '/.parts/' . $uploadId;
        if (! is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        $path = $directory . '/' . $number . '-' . str_repeat('a', 32) . '-' . bin2hex(random_bytes(16));
        self::assertSame(strlen($body), file_put_contents($path, $body));
        self::assertTrue(chmod($path, 0600));

        return ['partNumber' => $number, 'etag' => md5($body), 'size' => strlen($body), 'storagePath' => $path];
    }

    private function target(): string
    {
        return StoragePath::forMultipartCompletion($this->root, 'bucket', 'key', str_repeat('a', 32), str_repeat('b', 32))['assembly'];
    }

    private function admissions(FilesystemBackend $backend): int
    {
        $value = (new \ReflectionProperty($backend, 'multipartFenceAdmissions'))->getValue($backend);
        self::assertIsInt($value);

        return $value;
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
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
