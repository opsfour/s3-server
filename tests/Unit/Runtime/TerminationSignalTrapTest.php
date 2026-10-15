<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\TerminationSignalTrap;
use PHPUnit\Framework\TestCase;

final class TerminationSignalTrapTest extends TestCase
{
    public function test_sigterm_sent_at_start_boundary_is_caught(): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('The POSIX extension is required.');
        }

        $started = false;
        $pid = getmypid();
        self::assertIsInt($pid);
        $signal = (new TerminationSignalTrap())->startAndWait(
            static function () use (&$started, $pid): void {
                $started = true;
                if (! posix_kill($pid, SIGTERM)) {
                    throw new \RuntimeException('Could not send SIGTERM to the test process.');
                }
            },
        );

        self::assertTrue($started);
        self::assertSame(SIGTERM, $signal);
    }

    public function test_startup_failure_cancels_signal_watchers(): void
    {
        $trap = new TerminationSignalTrap();

        try {
            $trap->startAndWait(
                static function (): never {
                    throw new \RuntimeException('startup failed');
                },
            );
            self::fail('Startup failure was not propagated.');
        } catch (\RuntimeException $e) {
            self::assertSame('startup failed', $e->getMessage());
        }

        $closed = new \ReflectionProperty($trap, 'closed');
        $watcherIds = new \ReflectionProperty($trap, 'watcherIds');
        self::assertTrue($closed->getValue($trap));
        self::assertSame([], $watcherIds->getValue($trap));
    }
}
