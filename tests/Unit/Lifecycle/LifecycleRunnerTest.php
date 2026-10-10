<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Lifecycle;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class LifecycleRunnerTest extends TestCase
{
    public function test_running_state_tracks_the_periodic_watcher(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $runner = new LifecycleRunner(new LifecycleExecutor($metadata, new InMemoryBackend()), 60);

        self::assertFalse($runner->isRunning());
        try {
            $runner->assertRunning();
            self::fail('A lifecycle runner without a timer reported itself running.');
        } catch (\RuntimeException $error) {
            self::assertSame('Lifecycle runner is not running.', $error->getMessage());
        }

        $runner->start();
        self::assertTrue($runner->isRunning());
        $runner->assertRunning();

        $runner->stop();
        self::assertFalse($runner->isRunning());
    }

    public function test_logger_failure_during_stop_does_not_wedge_the_runner(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $logger = new class extends NullLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (($context['event'] ?? null) === 'runner_stopped') {
                    throw new \RuntimeException('injected logger failure');
                }
            }
        };
        $runner = new LifecycleRunner(new LifecycleExecutor($metadata, new InMemoryBackend()), 60, $logger);

        $runner->start();
        $runner->stop();
        self::assertFalse($runner->isRunning());

        $runner->start();
        self::assertTrue($runner->isRunning());
        $runner->stop();
        self::assertFalse($runner->isRunning());
    }

    public function test_logger_failure_during_start_and_periodic_tick_does_not_stop_runner(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $logger = new class extends AbstractLogger {
            public int $calls = 0;

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ++$this->calls;
                throw new \RuntimeException('injected logger failure');
            }
        };
        $runner = new LifecycleRunner(new LifecycleExecutor($metadata, new InMemoryBackend()), 0.001, $logger);
        $blockedSweep = new DeferredFuture();

        try {
            $runner->start();
            $this->referenceTimer($runner);
            (new \ReflectionProperty($runner, 'executionFuture'))->setValue($runner, $blockedSweep->getFuture());
            $deadline = microtime(true) + 0.2;
            while ($logger->calls < 2 && microtime(true) < $deadline) {
                \Amp\delay(0.005);
            }
            self::assertTrue($runner->isRunning());
            self::assertGreaterThan(1, $logger->calls);
        } finally {
            $blockedSweep->complete();
            $runner->stop();
        }
        self::assertFalse($runner->isRunning());
    }

    public function test_restart_resumes_sweeps_but_does_not_clear_a_direct_executor_stop(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $first = new DeferredFuture();
        $second = new DeferredFuture();
        $third = new DeferredFuture();
        $logger = new class ($first, $second, $third) extends AbstractLogger {
            /** @var list<string> */
            public array $statuses = [];

            /**
             * @param DeferredFuture<void> $first
             * @param DeferredFuture<void> $second
             * @param DeferredFuture<void> $third
             */
            public function __construct(
                private readonly DeferredFuture $first,
                private readonly DeferredFuture $second,
                private readonly DeferredFuture $third,
            ) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (($context['event'] ?? null) !== 'sweep_finished') {
                    return;
                }

                $this->statuses[] = (string) $context['status'];
                $signal = match (count($this->statuses)) {
                    1 => $this->first,
                    2 => $this->second,
                    3 => $this->third,
                    default => null,
                };
                $signal?->complete();
            }
        };
        $executor = new LifecycleExecutor($metadata, new InMemoryBackend(), $logger);
        $runner = new LifecycleRunner($executor, 0.01, $logger);

        try {
            $runner->start();
            $this->referenceTimer($runner);
            $first->getFuture()->await(new TimeoutCancellation(1));
            $runner->stop();

            $runner->start();
            $this->referenceTimer($runner);
            $second->getFuture()->await(new TimeoutCancellation(1));
            $runner->stop();

            $executor->requestStop();
            $runner->start();
            $this->referenceTimer($runner);
            $third->getFuture()->await(new TimeoutCancellation(1));
            $runner->stop();
        } finally {
            $runner->stop();
        }

        self::assertSame(['completed', 'completed', 'cancelled'], array_slice($logger->statuses, 0, 3));
    }

    public function test_start_during_stop_is_rejected_until_the_running_sweep_finishes(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $stopLogged = new DeferredFuture();
        $restarted = new DeferredFuture();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('acquireLock')->willReturn(true);
        $sweeps = 0;
        $metadata->method('listAllBuckets')->willReturnCallback(
            static function () use (&$sweeps, $entered, $release, $restarted): array {
                $sweeps++;
                if ($sweeps === 1) {
                    $entered->complete();
                    $release->getFuture()->await();
                } elseif ($sweeps === 2) {
                    $restarted->complete();
                }

                return [];
            },
        );
        $logger = new class ($stopLogged) extends AbstractLogger {
            /** @param DeferredFuture<void> $stopLogged */
            public function __construct(private readonly DeferredFuture $stopLogged) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (($context['event'] ?? null) === 'runner_stopped' && ! $this->stopLogged->getFuture()->isComplete()) {
                    $this->stopLogged->complete();
                }
            }
        };
        $runner = new LifecycleRunner(new LifecycleExecutor($metadata, new InMemoryBackend(), $logger), 0.01, $logger);
        $runner->start();
        $this->referenceTimer($runner);
        $entered->getFuture()->await(new TimeoutCancellation(1));
        $stop = \Amp\async(static fn() => $runner->stop());
        $stopLogged->getFuture()->await(new TimeoutCancellation(1));

        try {
            self::assertFalse($stop->isComplete());
            $rejected = false;
            try {
                $runner->start();
            } catch (\LogicException) {
                $rejected = true;
            }
            self::assertTrue($rejected);
        } finally {
            $release->complete();
            $stop->await(new TimeoutCancellation(1));
        }

        try {
            $runner->start();
            $this->referenceTimer($runner);
            $restarted->getFuture()->await(new TimeoutCancellation(1));
        } finally {
            $runner->stop();
        }
        self::assertGreaterThanOrEqual(2, $sweeps);
    }

    private function referenceTimer(LifecycleRunner $runner): void
    {
        // The production timer is deliberately unreferenced. Keep it alive
        // while this test waits for a sweep rather than sleeping for a guess.
        $callbackId = (new \ReflectionProperty($runner, 'callbackId'))->getValue($runner);
        self::assertIsString($callbackId);
        EventLoop::reference($callbackId);
    }
}
