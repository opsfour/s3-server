<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use PHPUnit\Framework\TestCase;

final class QueueLeaseHeartbeatTest extends TestCase
{
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
