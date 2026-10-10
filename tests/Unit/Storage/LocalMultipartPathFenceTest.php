<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalMultipartPathFence;
use OpsFour\S3Server\Tests\Support\LocalObjectWriteFaultIo;
use PHPUnit\Framework\TestCase;

final class LocalMultipartPathFenceTest extends TestCase
{
    public function test_create_only_publication_and_permanent_seal_reject_late_writer(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-mpu-fence-' . bin2hex(random_bytes(8));
        $tempDirectory = $root . '/.tmp';
        $attempt = str_repeat('a', 32);
        $target = $root . '/.assemblies/bucket/' . str_repeat('b', 32) . '/'
            . str_repeat('c', 64) . '/' . $attempt . '/assembly';
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(mkdir($tempDirectory, 0700));
        try {
            $fence = new LocalMultipartPathFence($root);
            $first = $tempDirectory . '/first';
            file_put_contents($first, 'first payload');
            $fence->publish($first, $target);
            self::assertSame('first payload', file_get_contents($target));

            $second = $tempDirectory . '/second';
            file_put_contents($second, 'late payload');
            try {
                $fence->publish($second, $target);
                self::fail('A second physical dispatch replaced the committed path.');
            } catch (\RuntimeException) {
                self::assertSame('first payload', file_get_contents($target));
            }

            $fence->seal($target, $attempt);
            $seal = file_get_contents($target);
            self::assertIsString($seal);
            self::assertStringStartsWith("OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attempt . "\n", $seal);
            try {
                $fence->publish($second, $target);
                self::fail('A late physical dispatch replaced the permanent seal.');
            } catch (\RuntimeException) {
                self::assertSame($seal, file_get_contents($target));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_backend_worker_cannot_publish_late_assembly_or_sse_final_over_seal(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-mpu-worker-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $backend = new FilesystemBackend($root);
        try {
            $attempt = str_repeat('a', 32);
            $paths = $backend->planMultipartCompletionPaths('bucket', 'key', str_repeat('b', 32), $attempt);
            foreach ([$paths['assembly'], $paths['final']] as $path) {
                $backend->sealFencedMultipartPath($path, $attempt);
                $seal = file_get_contents($path);
                self::assertIsString($seal);
                try {
                    $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('late bytes'), $path);
                    self::fail('A late multipart worker replaced the seal.');
                } catch (InternalErrorException) {
                    self::assertSame($seal, file_get_contents($path));
                }
            }
        } finally {
            $backend->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_ambiguous_link_keeps_the_path_occupied_until_the_collector_seals_it(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-mpu-ambiguous-link-' . bin2hex(random_bytes(8));
        $attempt = str_repeat('a', 32);
        $target = $root . '/.assemblies/bucket/' . str_repeat('b', 32) . '/'
            . str_repeat('c', 64) . '/' . $attempt . '/assembly';
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(mkdir($root . '/.tmp', 0700));
        try {
            $staged = $root . '/.tmp/first';
            file_put_contents($staged, 'uncommitted payload');
            $fault = new LocalObjectWriteFaultIo();
            $fault->reportLinkFailureAfterSuccess = true;
            try {
                (new LocalMultipartPathFence($root, $fault))->publish($staged, $target);
                self::fail('An ambiguous hard link was reported as a committed write.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('uncertain', $error->getMessage());
            }
            self::assertSame('uncommitted payload', file_get_contents($target));

            $collectorFence = new LocalMultipartPathFence($root);
            $collectorFence->seal($target, $attempt);
            $seal = file_get_contents($target);
            self::assertIsString($seal);
            self::assertStringStartsWith("OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attempt . "\n", $seal);

            $late = $root . '/.tmp/late';
            file_put_contents($late, 'late payload');
            try {
                $collectorFence->publish($late, $target);
                self::fail('A late hard link replaced the seal.');
            } catch (\RuntimeException) {
                self::assertSame($seal, file_get_contents($target));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_failed_directory_sync_after_link_does_not_free_the_attempt_path(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-mpu-directory-sync-' . bin2hex(random_bytes(8));
        $attempt = str_repeat('a', 32);
        $target = $root . '/.assemblies/bucket/' . str_repeat('b', 32) . '/'
            . str_repeat('c', 64) . '/' . $attempt . '/final';
        self::assertTrue(mkdir(dirname($target), 0700, true));
        self::assertTrue(mkdir($root . '/.tmp', 0700));
        try {
            $staged = $root . '/.tmp/first';
            file_put_contents($staged, 'uncommitted payload');
            $fault = new LocalObjectWriteFaultIo();
            $fault->failDirectorySync = true;
            try {
                (new LocalMultipartPathFence($root, $fault))->publish($staged, $target);
                self::fail('Publication succeeded without a durable directory sync.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('synchronize local multipart directory', $error->getMessage());
            }
            self::assertSame('uncommitted payload', file_get_contents($target));

            $collectorFence = new LocalMultipartPathFence($root);
            $collectorFence->seal($target, $attempt);
            $seal = file_get_contents($target);
            self::assertIsString($seal);
            $late = $root . '/.tmp/late';
            file_put_contents($late, 'late payload');
            try {
                $collectorFence->publish($late, $target);
                self::fail('A late hard link replaced the seal.');
            } catch (\RuntimeException) {
                self::assertSame($seal, file_get_contents($target));
            }
        } finally {
            $this->removeDirectory($root);
        }
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
