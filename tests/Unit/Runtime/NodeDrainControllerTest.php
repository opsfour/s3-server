<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\NodeDrainController;
use PHPUnit\Framework\TestCase;

final class NodeDrainControllerTest extends TestCase
{
    public function test_state_is_monotonic_and_begin_drain_is_idempotent(): void
    {
        $drain = new NodeDrainController();

        self::assertTrue($drain->acceptsNewWork());
        self::assertTrue($drain->beginDrain());
        self::assertFalse($drain->beginDrain());
        self::assertTrue($drain->isDraining());
        $drain->startPropagationWindow();
        $remaining = $drain->remainingPropagationDelay(5);
        self::assertGreaterThan(4.9, $remaining);
        self::assertLessThanOrEqual(5.0, $remaining);

        $drain->stop();
        self::assertTrue($drain->isStopped());
        self::assertFalse($drain->beginDrain());
        self::assertFalse($drain->acceptsNewWork());
        self::assertSame(0.0, $drain->remainingPropagationDelay(5));
    }
}
