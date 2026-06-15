<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Lifecycle;

use Revolt\EventLoop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs lifecycle processing on a periodic timer via Amp EventLoop.
 */
final class LifecycleRunner
{
    private ?string $callbackId = null;

    private bool $running = false;

    public function __construct(
        private readonly LifecycleExecutor $executor,
        private readonly float $intervalSeconds = 60.0,
        private readonly LoggerInterface $logger = new NullLogger,
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
            if ($this->running) {
                $this->logger->warning('Lifecycle runner skipped overlapping execution.', $this->lifecycleContext([
                    'event' => 'runner_overlap_skipped',
                    'interval_seconds' => $this->intervalSeconds,
                ]));

                return;
            }

            $this->running = true;
            try {
                $this->executor->execute();
            } catch (\Throwable $e) {
                $this->logger->error('Lifecycle runner failed.', $this->lifecycleContext([
                    'event' => 'runner_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]));
            } finally {
                $this->running = false;
            }
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
    public function stop(): void
    {
        if ($this->callbackId !== null) {
            EventLoop::cancel($this->callbackId);
            $this->callbackId = null;
            $this->logger->info('Lifecycle runner stopped.', $this->lifecycleContext([
                'event' => 'runner_stopped',
            ]));
        }
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
