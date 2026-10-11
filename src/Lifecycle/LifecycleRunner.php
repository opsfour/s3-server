<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use Amp\Future;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

/**
 * Runs lifecycle processing on a periodic timer via Amp EventLoop.
 */
final class LifecycleRunner
{
    private readonly FailureIsolatingLogger $logger;

    private ?string $callbackId = null;

    /** @var Future<void>|null */
    private ?Future $executionFuture = null;

    private bool $stopping = false;

    public function __construct(
        private readonly LifecycleExecutor $executor,
        private readonly float $intervalSeconds = 60.0,
        LoggerInterface $logger = new NullLogger(),
    ) {
        if (! is_finite($this->intervalSeconds) || $this->intervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('Lifecycle interval must be a finite number greater than zero.');
        }
        $this->logger = new FailureIsolatingLogger($logger);
    }

    /**
     * Start the periodic lifecycle execution.
     */
    public function start(): void
    {
        if ($this->stopping || ($this->executionFuture !== null && ! $this->executionFuture->isComplete() && $this->callbackId === null)) {
            throw new \LogicException('Lifecycle runner cannot start before its previous sweep has stopped.');
        }
        if ($this->callbackId !== null) {
            return;
        }

        $this->executor->assertAdmissionFresh();
        $this->executor->resumeRunnerAfterStop();
        $this->callbackId = EventLoop::repeat($this->intervalSeconds, function (): void {
            if ($this->executionFuture !== null && ! $this->executionFuture->isComplete()) {
                $this->logger->warning('Lifecycle runner skipped overlapping execution.', $this->lifecycleContext([
                    'event' => 'runner_overlap_skipped',
                    'interval_seconds' => $this->intervalSeconds,
                ]));

                return;
            }

            $this->executionFuture = \Amp\async(function (): void {
                try {
                    $this->executor->assertAdmissionFresh();
                    $this->executor->execute();
                } catch (\Throwable $e) {
                    $this->logger->error('Lifecycle runner failed.', $this->lifecycleContext([
                        'event' => 'runner_failed',
                        'exception' => $e::class,
                    ]));
                }
            });
        });

        // Unreference so the lifecycle timer alone doesn't prevent event loop exit.
        EventLoop::unreference($this->callbackId);

        $this->logger->info('Lifecycle runner started.', $this->lifecycleContext([
            'event' => 'runner_started',
            'interval_seconds' => $this->intervalSeconds,
        ]));
    }

    /** True only while the periodic watcher is installed and enabled. */
    public function isRunning(): bool
    {
        if ($this->stopping || $this->callbackId === null) {
            return false;
        }

        try {
            return EventLoop::isEnabled($this->callbackId);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Fail closed when startup supervision cannot prove the timer is alive. */
    public function assertRunning(): void
    {
        if (! $this->isRunning()) {
            throw new \RuntimeException('Lifecycle runner is not running.');
        }
    }

    /**
     * Stop the periodic lifecycle execution.
     */
    public function stop(int $timeoutSeconds = 30): void
    {
        if ($this->stopping) {
            throw new \LogicException('Lifecycle runner stop is already in progress.');
        }
        $this->stopping = true;
        $this->executor->requestRunnerStop();

        try {
            if ($this->callbackId !== null) {
                EventLoop::cancel($this->callbackId);
                $this->callbackId = null;
                $this->logDuringStop('info', 'Lifecycle runner stopped.', $this->lifecycleContext([
                    'event' => 'runner_stopped',
                ]));
            }

            $executionFuture = $this->executionFuture;
            if ($executionFuture !== null && ! $executionFuture->isComplete()) {
                try {
                    $executionFuture->await(new \Amp\TimeoutCancellation(max(1, $timeoutSeconds)));
                } catch (\Amp\CancelledException) {
                    $this->logDuringStop('warning', 'Lifecycle sweep did not stop within {timeout}s; waiting before dependency shutdown.', $this->lifecycleContext([
                        'event' => 'runner_shutdown_timeout',
                        'timeout' => $timeoutSeconds,
                    ]));
                    try {
                        $executionFuture->await();
                    } catch (\Throwable $e) {
                        $this->logDuringStop('warning', 'Lifecycle sweep shutdown failed.', $this->lifecycleContext([
                            'event' => 'runner_shutdown_failed',
                            'exception' => $e::class,
                        ]));
                    }
                } catch (\Throwable $e) {
                    $this->logDuringStop('warning', 'Lifecycle sweep shutdown failed.', $this->lifecycleContext([
                        'event' => 'runner_shutdown_failed',
                        'exception' => $e::class,
                    ]));
                }
            }

            if ($executionFuture !== null && ! $executionFuture->isComplete()) {
                throw new \LogicException('Lifecycle sweep is still running after stop.');
            }

            $this->executionFuture = null;
        } finally {
            if ($this->executionFuture === null || $this->executionFuture->isComplete()) {
                $this->stopping = false;
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function logDuringStop(string $level, string $message, array $context): void
    {
        $this->logger->log($level, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function lifecycleContext(array $context = []): array
    {
        return ['component' => 'lifecycle'] + $context;
    }
}
