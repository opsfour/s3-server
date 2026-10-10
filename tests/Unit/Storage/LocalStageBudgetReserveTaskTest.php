<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureException;
use OpsFour\S3Server\Parallel\LocalStageBudgetReserveTask;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use PHPUnit\Framework\TestCase;

final class LocalStageBudgetReserveTaskTest extends TestCase
{
    public function test_late_worker_cannot_reserve_bytes_after_stage_and_lease_are_released(): void
    {
        $scratch = sys_get_temp_dir() . '/s3-late-budget-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($scratch, 0700));
        $coordinator = new StorageTempBudgetCoordinator($scratch, 5);
        $path = $scratch . '/s3fw-put-late';
        self::assertSame(4, file_put_contents($path, 'data'));
        $reservation = $coordinator->start($path);
        $leasePath = $reservation->leasePath;
        $tempPath = $reservation->tempPath;
        $pool = new ContextWorkerPool(1);

        try {
            self::assertTrue(unlink($path));
            $coordinator->release($reservation);
            try {
                $pool->submit(new LocalStageBudgetReserveTask($scratch, 5, $leasePath, $tempPath, 4))->await();
                self::fail('A late worker must not recreate a released reservation.');
            } catch (TaskFailureException $error) {
                self::assertStringContainsString('released', $error->getMessage());
            }

            self::assertSame([], glob($scratch . '/.opsfour-s3-storage-budget/lease-*'));
            self::assertSame('0', file_get_contents($scratch . '/.opsfour-s3-storage-budget/total'));
        } finally {
            $pool->shutdown();
            foreach (glob($scratch . '/.opsfour-s3-storage-budget/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($scratch . '/.opsfour-s3-storage-budget');
            @unlink($path);
            @rmdir($scratch);
        }
    }
}
