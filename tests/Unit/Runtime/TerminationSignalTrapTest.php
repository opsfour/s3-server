<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\TerminationSignalTrap;
use PHPUnit\Framework\TestCase;

final class TerminationSignalTrapTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function terminationSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminationSignals')]
    public function test_termination_signal_sent_at_start_boundary_is_caught(int $terminationSignal): void
    {
        if (! function_exists('posix_kill')) {
            self::markTestSkipped('The POSIX extension is required.');
        }

        $started = false;
        $pid = getmypid();
        self::assertIsInt($pid);
        $signal = (new TerminationSignalTrap())->startAndWait(
            static function () use (&$started, $pid, $terminationSignal): void {
                $started = true;
                if (! posix_kill($pid, $terminationSignal)) {
                    throw new \RuntimeException('Could not send the termination signal to the test process.');
                }
            },
        );

        self::assertTrue($started);
        self::assertSame($terminationSignal, $signal);
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
