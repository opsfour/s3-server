<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use PHPUnit\Framework\TestCase;

final class StorageTempBudgetCoordinatorTest extends TestCase
{
    public function test_worker_held_lease_preserves_budget_after_parent_exits(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Process control is unavailable.');
        }
        $directory = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $tempDir = realpath($directory);
        self::assertIsString($tempDir);
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $stagePath = $tempDir . '/s3fw-put-' . str_repeat('a', 64);
        $nextPath = $tempDir . '/s3fw-put-' . str_repeat('b', 64);
        self::assertSame(4, file_put_contents($stagePath, 'abcd'));
        $parent = $coordinator->start($stagePath);
        $coordinator->reserve($parent, 4);
        $ready = $tempDir . '/worker-ready';
        $release = $tempDir . '/worker-release';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/storage-temp-worker-lease-process.php', $parent->leasePath, $stagePath, $ready, $release],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $next = null;
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($ready, 'The child did not hold the staging lease.');
            $parent->close(); // The API process exits while its child still uses the stage.
            self::assertSame(4, file_put_contents($nextPath, 'wxyz'));
            $next = $coordinator->start($nextPath);
            try {
                $coordinator->reserve($next, 4);
                self::fail('An active child escaped the shared scratch budget.');
            } catch (SlowDownException) {
                self::assertFileExists($stagePath);
            }

            self::assertSame(0, file_put_contents($release, ''));
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);
            $coordinator->reserve($next, 4);
            self::assertFileDoesNotExist($stagePath);
        } finally {
            $parent->close();
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            if ($next !== null) {
                @unlink($nextPath);
                $coordinator->release($next);
            }
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @unlink($stagePath);
            @unlink($ready);
            @unlink($release);
            @rmdir($tempDir);
        }
    }

    public function test_shared_directory_rejects_different_budget_while_a_transfer_is_active(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $tempPath = $tempDir . '/s3fw-put-active';
        file_put_contents($tempPath, 'abcd');
        $reservation = $coordinator->start($tempPath);
        try {
            $coordinator->reserve($reservation, 4);
            try {
                new StorageTempBudgetCoordinator($tempDir, 6);
                self::fail('A second process with a different active budget was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('same budget', $error->getMessage());
            }
        } finally {
            @unlink($tempPath);
            $coordinator->release($reservation);
        }

        self::assertInstanceOf(StorageTempBudgetCoordinator::class, new StorageTempBudgetCoordinator($tempDir, 6));
        foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($tempDir . '/.opsfour-s3-storage-budget');
        @rmdir($tempDir);
    }

    public function test_unleased_crash_file_is_swept_only_after_it_is_stale(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $oldPath = $tempDir . '/s3fw-put-unleased-old';
        $newPath = $tempDir . '/s3fw-put-unleased-new';
        $activePath = $tempDir . '/s3fw-put-active';
        $triggerPath = $tempDir . '/s3fw-put-trigger';
        file_put_contents($oldPath, 'old');
        file_put_contents($newPath, 'new');
        file_put_contents($activePath, 'active');
        touch($oldPath, time() - 90_000);

        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $active = $coordinator->start($activePath);
        touch($activePath, time() - 90_000);
        file_put_contents($triggerPath, 'trigger');
        $trigger = (new StorageTempBudgetCoordinator($tempDir, 5))->start($triggerPath);
        try {
            self::assertFileDoesNotExist($oldPath);
            self::assertFileExists($newPath);
            self::assertFileExists($activePath);
        } finally {
            @unlink($triggerPath);
            $coordinator->release($trigger);
            @unlink($activePath);
            $coordinator->release($active);
            @unlink($newPath);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_abandoned_worker_multipart_temp_file_is_recovered_by_its_lease(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        try {
            $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
            $workerPath = $tempDir . '/s3fw-mpu-crashed';
            self::assertSame(4, file_put_contents($workerPath, 'abcd'));
            $worker = $coordinator->start($workerPath);
            $coordinator->reserve($worker, 4);
            $worker->close(); // Simulate a worker exit without cleanup.

            $nextPath = $tempDir . '/s3fw-put-next';
            self::assertSame(4, file_put_contents($nextPath, 'wxyz'));
            $next = $coordinator->start($nextPath);
            try {
                $coordinator->reserve($next, 4);
                self::assertFileDoesNotExist($workerPath);
            } finally {
                @unlink($nextPath);
                $coordinator->release($next);
            }
        } finally {
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_corrupt_abandoned_state_holds_budget_until_its_unknown_temp_file_is_removed(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $corruptPath = $tempDir . '/s3fw-put-corrupt';
        $validPath = $tempDir . '/s3fw-put-valid';
        $nextPath = $tempDir . '/s3fw-put-next';
        file_put_contents($corruptPath, 'abcd');
        file_put_contents($validPath, 'efgh');
        file_put_contents($nextPath, 'next');
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 8);
        $corrupt = $coordinator->start($corruptPath);
        $valid = $coordinator->start($validPath);
        $next = null;
        try {
            $coordinator->reserve($corrupt, 4);
            $coordinator->reserve($valid, 4);
            $corrupt->close();
            $valid->close();
            $corruptState = dirname($corrupt->leasePath) . '/state-' . substr(basename($corrupt->leasePath), 6);
            self::assertSame(7, file_put_contents($corruptState, '{broken'));

            $next = (new StorageTempBudgetCoordinator($tempDir, 8))->start($nextPath);
            try {
                $coordinator->reserve($next, 4);
                self::fail('Corrupt state released budget while its unknown temp file still existed.');
            } catch (SlowDownException) {
                self::assertFileExists($corruptPath);
            }

            self::assertFileDoesNotExist($corrupt->leasePath);
            self::assertFileDoesNotExist($corruptState);
            self::assertFileDoesNotExist($validPath);
            self::assertFileDoesNotExist($valid->leasePath);
            self::assertCount(1, glob($tempDir . '/.opsfour-s3-storage-budget/quarantine-*') ?: []);

            self::assertTrue(unlink($corruptPath));
            $coordinator->reserve($next, 4);
            self::assertSame([], glob($tempDir . '/.opsfour-s3-storage-budget/quarantine-*') ?: []);
        } finally {
            @unlink($nextPath);
            if ($next !== null) {
                $coordinator->release($next);
            }
            @unlink($corruptPath);
            @unlink($validPath);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_active_other_process_blocks_capacity_and_crash_reclaims_its_file(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $readyPath = $tempDir . '/worker-ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/storage-temp-reservation-worker.php', $tempDir, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $reservation = null;

        try {
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath, 'The storage reservation worker did not become ready.');

            $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
            $parentPath = $tempDir . '/s3fw-put-parent';
            self::assertSame(4, file_put_contents($parentPath, 'wxyz'));
            $reservation = $coordinator->start($parentPath);

            try {
                $coordinator->reserve($reservation, 4);
                self::fail('The other process must count toward the shared staging budget.');
            } catch (SlowDownException) {
                self::assertFileExists($tempDir . '/s3fw-put-worker');
            }

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $coordinator->reserve($reservation, 4);
            self::assertFileDoesNotExist($tempDir . '/s3fw-put-worker');
        } finally {
            if ($reservation !== null) {
                @unlink($reservation->tempPath);
                (new StorageTempBudgetCoordinator($tempDir, 5))->release($reservation);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @unlink($readyPath);
            @unlink($tempDir . '/s3fw-put-worker');
            @unlink($tempDir . '/s3fw-put-parent');
            @rmdir($tempDir);
        }
    }

    public function test_incomplete_state_replacement_preserves_active_bytes(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-storage-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 5);
        $firstPath = $tempDir . '/s3fw-put-first';
        $secondPath = $tempDir . '/s3fw-put-second';
        file_put_contents($firstPath, 'abcd');
        file_put_contents($secondPath, 'z');
        $first = $coordinator->start($firstPath);
        $second = null;
        try {
            $coordinator->reserve($first, 4);
            $statePath = dirname($first->leasePath) . '/state-' . substr(basename($first->leasePath), 6);
            self::assertSame(7, file_put_contents($statePath . '.tmp', '{broken'));
            self::assertSame(7, file_put_contents(dirname($first->leasePath) . '/total', 'corrupt'));

            $second = (new StorageTempBudgetCoordinator($tempDir, 5))->start($secondPath);
            $coordinator->reserve($second, 1);
            $this->expectException(SlowDownException::class);
            $coordinator->reserve($second, 1);
        } finally {
            @unlink($secondPath);
            if ($second !== null) {
                $coordinator->release($second);
            }
            @unlink($firstPath);
            $coordinator->release($first);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }
}
