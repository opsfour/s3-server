<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Exception\SlowDownException;
use PHPUnit\Framework\TestCase;

final class PostObjectSpoolCoordinatorTest extends TestCase
{
    public function test_explicit_recovery_removes_an_abandoned_reservation(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-recovery-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $tempPath = $tempDir . '/s3-post-abandoned.tmp';
        self::assertSame(4, file_put_contents($tempPath, 'data'));
        $coordinator = new PostObjectSpoolCoordinator($tempDir);
        $reservation = $coordinator->start($tempPath);
        $coordinator->reserve($reservation, 4, 4);
        $leasePath = $reservation->leasePath;
        $statePath = dirname($leasePath) . '/state-' . substr(basename($leasePath), 6);
        $totalPath = dirname($leasePath) . '/total';

        try {
            $reservation->close();
            $coordinator->recoverAbandoned();

            self::assertFileDoesNotExist($tempPath);
            self::assertFileDoesNotExist($leasePath);
            self::assertFileDoesNotExist($statePath);
            self::assertSame('0', file_get_contents($totalPath));
        } finally {
            @unlink($tempPath);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_crashed_reservation_is_recovered_when_processes_use_aliases_of_one_temp_directory(): void
    {
        $root = sys_get_temp_dir() . '/s3-post-alias-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $tempDir = $root . '/real';
        self::assertTrue(mkdir($tempDir, 0700));
        $alias = $root . '/alias';
        self::assertTrue(symlink($tempDir, $alias));
        $abandonedPath = $alias . '/s3-post-abandoned.tmp';
        $nextPath = $tempDir . '/s3-post-next.tmp';
        self::assertSame(4, file_put_contents($abandonedPath, 'data'));
        self::assertSame(1, file_put_contents($nextPath, 'x'));

        try {
            $first = new PostObjectSpoolCoordinator($alias);
            $abandoned = $first->start($abandonedPath);
            $first->reserve($abandoned, 4, 4);
            $first->release($abandoned, false);

            $second = new PostObjectSpoolCoordinator($tempDir);
            $next = $second->start($nextPath);
            try {
                $second->reserve($next, 1, 4);
                self::assertFileDoesNotExist($abandonedPath);
            } finally {
                @unlink($nextPath);
                $second->release($next, true);
            }
        } finally {
            @unlink($abandonedPath);
            @unlink($nextPath);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @unlink($alias);
            @rmdir($tempDir);
            @rmdir($root);
        }
    }

    public function test_processes_cannot_use_different_budgets_while_a_form_is_active(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $firstPath = $tempDir . '/s3-post-first.tmp';
        $secondPath = $tempDir . '/s3-post-second.tmp';
        file_put_contents($firstPath, 'abcd');
        file_put_contents($secondPath, 'e');
        $firstCoordinator = new PostObjectSpoolCoordinator($tempDir);
        $secondCoordinator = new PostObjectSpoolCoordinator($tempDir);
        $first = $firstCoordinator->start($firstPath);
        $second = $secondCoordinator->start($secondPath);

        try {
            $firstCoordinator->reserve($first, 4, 4);
            $rejected = false;
            try {
                $secondCoordinator->reserve($second, 1, 8);
            } catch (\RuntimeException $exception) {
                $rejected = true;
                self::assertStringContainsString('same budget', $exception->getMessage());
            }
            self::assertTrue($rejected, 'A process with a larger local limit must not exceed the active shared limit.');
            self::assertSame(0, $second->bytes());
            @unlink($firstPath);
            $firstCoordinator->release($first, true);
            $secondCoordinator->reserve($second, 1, 8);
            self::assertSame(1, $second->bytes());
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
            $firstCoordinator->release($first, true);
            $secondCoordinator->release($second, true);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_incomplete_state_replacement_does_not_hide_an_active_reservation(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new PostObjectSpoolCoordinator($tempDir);
        $activePath = $tempDir . '/s3-post-active.tmp';
        $otherPath = $tempDir . '/s3-post-other.tmp';
        file_put_contents($activePath, 'abcd');
        $active = $coordinator->start($activePath);
        $other = null;
        try {
            $coordinator->reserve($active, 4, 5);
            $statePath = dirname($active->leasePath) . '/state-' . substr(basename($active->leasePath), 6);
            self::assertSame(7, file_put_contents($statePath . '.tmp', '{broken'));
            self::assertSame(7, file_put_contents(dirname($active->leasePath) . '/total', 'corrupt'));

            file_put_contents($otherPath, 'z');
            $other = (new PostObjectSpoolCoordinator($tempDir))->start($otherPath);
            self::assertSame(1, file_put_contents(dirname($active->leasePath) . '/total', '5'));
            $coordinator->reserve($other, 1, 5);
            try {
                $coordinator->reserve($other, 1, 5);
                self::fail('An incomplete replacement must not erase the active four-byte lease.');
            } catch (SlowDownException) {
                self::assertSame(1, $other->bytes());
            }
        } finally {
            @unlink($otherPath);
            if ($other !== null) {
                $coordinator->release($other, true);
            }
            @unlink($activePath);
            $coordinator->release($active, true);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_other_process_reservation_blocks_upload_then_crash_releases_budget(): void
    {
        if (! function_exists('proc_open') || ! function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-post-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $readyPath = $tempDir . '/worker-ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/post-spool-reservation-worker.php', $tempDir, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $reservation = null;
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath, 'The reservation worker did not become ready.');

            $coordinator = new PostObjectSpoolCoordinator($tempDir);
            $parentTempPath = $tempDir . '/s3-post-parent.tmp';
            self::assertSame(4, file_put_contents($parentTempPath, 'wxyz'));
            $reservation = $coordinator->start($parentTempPath);

            try {
                $coordinator->reserve($reservation, 4, 5);
                self::fail('The other API process must count toward the same node budget.');
            } catch (SlowDownException) {
                self::assertFileExists($tempDir . '/s3-post-worker.tmp');
            }

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $coordinator->reserve($reservation, 4, 5);
            self::assertFileDoesNotExist($tempDir . '/s3-post-worker.tmp');
            self::assertSame(4, $reservation->bytes());
        } finally {
            if ($reservation !== null) {
                @unlink($reservation->tempPath);
                (new PostObjectSpoolCoordinator($tempDir))->release($reservation, true);
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
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @unlink($readyPath);
            @unlink($tempDir . '/s3-post-worker.tmp');
            @unlink($tempDir . '/s3-post-parent.tmp');
            @rmdir($tempDir);
        }
    }
}
