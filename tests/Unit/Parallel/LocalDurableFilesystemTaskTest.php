<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\NullCancellation;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Sync\Channel;
use OpsFour\S3Server\Parallel\LocalDurableFilesystemTask;
use PHPUnit\Framework\TestCase;

final class LocalDurableFilesystemTaskTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/s3-durable-task-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0o700));
        $root = realpath($path);
        self::assertIsString($root);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_all_operations_run_in_a_context_worker(): void
    {
        $pool = new ContextWorkerPool(1);
        $temporaryDirectory = $this->root . '/.tmp';
        $targetDirectory = $this->root . '/bucket/aa/bb';
        $stagedPath = $temporaryDirectory . '/attempt';
        $targetPath = $targetDirectory . '/object';
        self::assertTrue(mkdir($temporaryDirectory, 0o700));
        self::assertSame(7, file_put_contents($stagedPath, 'payload'));

        try {
            $pool->submit(LocalDurableFilesystemTask::ensureDirectory($this->root, $targetDirectory))->await();
            self::assertDirectoryExists($targetDirectory);

            $pool->submit(LocalDurableFilesystemTask::publish($this->root, $stagedPath, $targetPath))->await();
            self::assertSame('payload', file_get_contents($targetPath));
            self::assertFileDoesNotExist($stagedPath);

            $pool->submit(LocalDurableFilesystemTask::delete($this->root, $targetPath))->await();
            self::assertFileDoesNotExist($targetPath);
        } finally {
            $pool->shutdown();
        }
    }

    public function test_directory_task_bootstraps_a_missing_storage_root(): void
    {
        $root = $this->root . '/new/storage';
        $pool = new ContextWorkerPool(1);

        try {
            $pool->submit(LocalDurableFilesystemTask::ensureDirectory($root, $root . '/bucket'))->await();
            self::assertDirectoryExists($root . '/bucket');
        } finally {
            $pool->shutdown();
        }
    }

    public function test_task_state_contains_only_scalar_properties(): void
    {
        $task = LocalDurableFilesystemTask::publish(
            $this->root,
            $this->root . '/.tmp/attempt',
            $this->root . '/bucket/object',
        );

        $properties = (new \ReflectionClass($task))->getProperties();
        self::assertNotSame([], $properties);
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            self::assertTrue($property->getType() instanceof \ReflectionNamedType);
            self::assertTrue($property->getType()->isBuiltin());
            self::assertSame('string', $property->getType()->getName());
            self::assertIsString($property->getValue($task));
        }

        self::assertInstanceOf(LocalDurableFilesystemTask::class, unserialize(serialize($task)));
    }

    public function test_cancellation_is_checked_once_before_the_storage_root_is_accessed(): void
    {
        $cancellation = $this->createMock(Cancellation::class);
        $cancellation->expects(self::once())
            ->method('throwIfRequested')
            ->willThrowException(new CancelledException());
        $task = LocalDurableFilesystemTask::delete(
            $this->root . '/missing-root',
            $this->root . '/missing-root/bucket/object',
        );

        $this->expectException(CancelledException::class);
        $task->run($this->createMock(Channel::class), $cancellation);
    }

    public function test_publish_rejects_an_invalid_path_combination(): void
    {
        $path = $this->root . '/.tmp/attempt';

        $this->expectException(\InvalidArgumentException::class);
        LocalDurableFilesystemTask::publish($this->root, $path, $path);
    }

    public function test_publish_rejects_a_staging_path_outside_the_temporary_namespace(): void
    {
        $targetDirectory = $this->root . '/bucket';
        self::assertTrue(mkdir($targetDirectory, 0o700));
        $source = $targetDirectory . '/source';
        self::assertSame(7, file_put_contents($source, 'payload'));
        $task = LocalDurableFilesystemTask::publish($this->root, $source, $targetDirectory . '/target');

        $this->expectException(\InvalidArgumentException::class);
        $task->run($this->createMock(Channel::class), new NullCancellation());
    }

    public function test_single_path_operations_reject_paths_outside_the_root(): void
    {
        $outside = dirname($this->root) . '/outside-' . bin2hex(random_bytes(8));

        foreach ([
            LocalDurableFilesystemTask::delete($this->root, $outside),
            LocalDurableFilesystemTask::ensureDirectory($this->root, $outside),
        ] as $task) {
            try {
                $task->run($this->createMock(Channel::class), new NullCancellation());
                self::fail('A durability task accepted a path outside its storage root.');
            } catch (\InvalidArgumentException) {
            }
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
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
