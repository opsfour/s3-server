<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueueLeaseHeartbeatTest extends TestCase
{
    #[DataProvider('invalidIntervals')]
    public function test_non_finite_and_non_positive_intervals_are_rejected(float $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QueueLeaseHeartbeat(static fn(float $expiresAt): bool => true, $interval);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidIntervals(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function test_heartbeat_renews_until_stopped(): void
    {
        $renewals = 0;

        \Amp\async(function () use (&$renewals): void {
            $heartbeat = new QueueLeaseHeartbeat(
                static function (float $expiresAt) use (&$renewals): bool {
                    self::assertGreaterThan(microtime(true), $expiresAt);
                    $renewals++;

                    return true;
                },
                0.01,
            );

            \Amp\delay(0.035);
            $heartbeat->stop();
        })->await();

        self::assertGreaterThanOrEqual(2, $renewals);
    }

    public function test_heartbeat_reports_a_lost_lease(): void
    {
        $heartbeat = new QueueLeaseHeartbeat(static fn(float $expiresAt): bool => false, 0.01);
        \Amp\delay(0.02);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue processing lease was lost.');
        $heartbeat->stop();
    }
}
