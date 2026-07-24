<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use Amp\Future;
use Revolt\EventLoop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs lifecycle processing on a periodic timer via Amp EventLoop.
 */
final class LifecycleRunner
{
    private ?string $callbackId = null;

    /** @var Future<void>|null */
    private ?Future $executionFuture = null;

    public function __construct(
        private readonly LifecycleExecutor $executor,
        private readonly float $intervalSeconds = 60.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Start the periodic lifecycle execution.
     */
    public function start(): void
    {
        if ($this->callbackId !== null) {
            return;
        }

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
                    $this->executor->execute();
                } catch (\Throwable $e) {
                    $this->logger->error('Lifecycle runner failed.', $this->lifecycleContext([
                        'event' => 'runner_failed',
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
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

    /**
     * Stop the periodic lifecycle execution.
     */
    public function stop(int $timeoutSeconds = 30): void
    {
        $this->executor->requestStop();

        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
            $this->logger->info('Lifecycle runner stopped.', $this->lifecycleContext([
                'event' => 'runner_stopped',
            ]));
        }

        $executionFuture = $this->executionFuture;
        if ($executionFuture === null || $executionFuture->isComplete()) {
            return;
        }

        try {
            $executionFuture->await(new \Amp\TimeoutCancellation(max(1, $timeoutSeconds)));
        } catch (\Amp\CancelledException) {
            $this->logger->warning('Lifecycle sweep did not stop within {timeout}s; waiting before dependency shutdown.', $this->lifecycleContext([
                'event' => 'runner_shutdown_timeout',
                'timeout' => $timeoutSeconds,
            ]));
            try {
                $executionFuture->await();
            } catch (\Throwable $e) {
                $this->logger->warning('Lifecycle sweep shutdown failed.', $this->lifecycleContext([
                    'event' => 'runner_shutdown_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Lifecycle sweep shutdown failed.', $this->lifecycleContext([
                'event' => 'runner_shutdown_failed',
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));
        }

        $this->executionFuture = null;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function lifecycleContext(array $context = []): array
    {
        return ['component' => 'lifecycle'] + $context;
    }
}
