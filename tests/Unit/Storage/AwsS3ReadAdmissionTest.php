<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Storage\AwsS3ReadAdmission;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class AwsS3ReadAdmissionTest extends TestCase
{
    public function test_saturation_remains_occupied_until_actual_release(): void
    {
        $admission = new AwsS3ReadAdmission(1, 0);
        $admission->acquire(new TimeoutCancellation(1));
        self::assertSame(1, $admission->active());

        try {
            $admission->acquire(new TimeoutCancellation(1));
            self::fail('Occupied AWS read capacity was reported as free.');
        } catch (SlowDownException) {
        }

        $admission->release();
        self::assertSame(0, $admission->active());
        $admission->acquire(new TimeoutCancellation(1));
        self::assertSame(1, $admission->active());
    }

    public function test_capacity_cannot_be_released_twice(): void
    {
        $admission = new AwsS3ReadAdmission(1, 0);
        $admission->acquire(new TimeoutCancellation(1));
        $admission->release();

        $this->expectException(\LogicException::class);
        $admission->release();
    }

    public function test_release_hands_the_active_slot_to_one_waiter_without_leaking_capacity(): void
    {
        $admission = new AwsS3ReadAdmission(1, 1);
        $admission->acquire(new TimeoutCancellation(1));
        $waiting = async(static function () use ($admission): void {
            $admission->acquire(new TimeoutCancellation(1));
        });
        delay(0);
        self::assertSame(1, $admission->active());
        self::assertSame(1, $admission->waiting());

        $admission->release();
        $waiting->await();
        self::assertSame(1, $admission->active());
        self::assertSame(0, $admission->waiting());

        $admission->release();
        self::assertSame(0, $admission->active());
    }

    public function test_timed_out_waiter_is_removed_and_capacity_recovers(): void
    {
        $admission = new AwsS3ReadAdmission(1, 1);
        $admission->acquire(new TimeoutCancellation(1));
        $waiting = async(static function () use ($admission): void {
            $admission->acquire(new TimeoutCancellation(0.01));
        });
        delay(0);
        self::assertSame(1, $admission->waiting());

        delay(0.02);
        $failure = null;
        try {
            $waiting->await();
        } catch (CancelledException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure, 'The bounded waiter did not time out.');
        self::assertSame(1, $admission->active());
        self::assertSame(0, $admission->waiting());

        $admission->release();
        $admission->acquire(new TimeoutCancellation(1));
        self::assertSame(1, $admission->active());
        $admission->release();
        self::assertSame(0, $admission->active());
    }
}
