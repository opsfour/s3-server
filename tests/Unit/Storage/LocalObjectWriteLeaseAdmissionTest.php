<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalObjectWriteLeaseAdmissionTest extends TestCase
{
    public function test_contention_is_rejected_without_queuing_and_release_restores_capacity(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(1);
        $permit = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        try {
            $admission->acquire();
            self::fail('A second concurrent write was admitted.');
        } catch (SlowDownException) {
        }

        $permit->release();
        $permit->release();
        self::assertSame(0, $admission->activeCount());
        $next = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        $next->release();
        self::assertSame(0, $admission->activeCount());
    }

    public function test_cancelled_waiter_releases_a_late_permit_handover(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(1, waitForCapacity: true, maximumWaiters: 1);
        $first = $admission->acquire();
        $cancellation = new DeferredCancellation();
        $waiting = async(fn() => $admission->acquire($cancellation->getCancellation()));
        delay(0.01);
        $cancellation->cancel();

        try {
            $waiting->await();
            self::fail('Cancelled admission wait unexpectedly acquired capacity.');
        } catch (CancelledException) {
        }

        self::assertSame(0, $admission->waitingCount());
        $first->release();
        delay(0.01);
        $next = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        $next->release();
        self::assertSame(0, $admission->activeCount());
    }

    public function test_timed_out_waiter_releases_a_late_permit_handover(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(
            1,
            waitForCapacity: true,
            waitTimeoutSeconds: 0.01,
            maximumWaiters: 1,
        );
        $first = $admission->acquire();

        try {
            $admission->acquire();
            self::fail('Admission wait unexpectedly outlived its deadline.');
        } catch (SlowDownException) {
        }

        self::assertSame(0, $admission->waitingCount());
        $first->release();
        delay(0.01);
        $next = $admission->acquire();
        self::assertSame(1, $admission->activeCount());
        $next->release();
        self::assertSame(0, $admission->activeCount());
    }

    public function test_waiter_queue_is_bounded_independently_from_active_capacity(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(
            1,
            waitForCapacity: true,
            waitTimeoutSeconds: 1.0,
            maximumWaiters: 1,
        );
        $first = $admission->acquire();
        $waiting = async(fn() => $admission->acquire());
        delay(0.01);
        self::assertSame(1, $admission->waitingCount());

        try {
            $admission->acquire();
            self::fail('Admission accepted more than its bounded waiter count.');
        } catch (SlowDownException) {
        }

        $first->release();
        $second = $waiting->await();
        self::assertSame(0, $admission->waitingCount());
        self::assertSame(1, $admission->activeCount());
        $second->release();
        self::assertSame(0, $admission->activeCount());
    }
}
