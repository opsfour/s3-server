<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event;

use Amp\Future;
use Amp\Sync\LocalSemaphore;
use OpsFour\S3Server\Contracts\EventDispatcher;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * In-memory event dispatcher with asynchronous delivery via Amp fibers.
 *
 * Each registered listener is invoked in its own fiber when an event
 * is dispatched, ensuring non-blocking event handling. Listener
 * exceptions are isolated to prevent one failing listener from affecting
 * request handling or other listeners.
 *
 * A semaphore limits concurrent listeners. A separate bounded queue prevents
 * unbounded fiber accumulation without suspending dispatch().
 */
final class SimpleEventDispatcher implements EventDispatcher
{
    /** @var array<string, list<callable(array<string, mixed>): void>> */
    private array $listeners = [];

    private readonly LocalSemaphore $semaphore;

    /** @var array<int, Future<void>> */
    private array $listenerFutures = [];

    private int $queuedListenerTasks = 0;

    private int $nextListenerTaskId = 0;

    private readonly FailureIsolatingLogger $logger;

    public function __construct(
        int $maxConcurrentListeners = 500,
        private readonly int $maxQueuedListenerTasks = 10_000,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->semaphore = new LocalSemaphore(max(1, $maxConcurrentListeners));
        if ($this->maxQueuedListenerTasks < 1) {
            throw new \InvalidArgumentException('Event listener queue limit must be >= 1.');
        }
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Remove all listeners for a given event, or all events if null.
     */
    public function removeListeners(?string $eventName = null): void
    {
        if ($eventName === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$eventName]);
        }
    }

    public function dispatch(string $eventName, array $payload = []): void
    {
        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            if ($this->queuedListenerTasks >= $this->maxQueuedListenerTasks) {
                $this->logger->warning('Event listener task dropped because the queue limit is reached.', [
                    'component' => 'event_dispatcher',
                    'event' => 'listener_task_dropped',
                    'event_name' => $eventName,
                    'queued_listener_tasks' => $this->queuedListenerTasks,
                    'max_queued_listener_tasks' => $this->maxQueuedListenerTasks,
                ]);

                continue;
            }

            $this->queuedListenerTasks++;
            $taskId = ++$this->nextListenerTaskId;
            $future = async(function () use ($eventName, $listener, $payload, $taskId): void {
                $lock = null;
                try {
                    $lock = $this->semaphore->acquire();
                    $listener($payload);
                } catch (\Throwable $error) {
                    $this->logger->error('Event listener failed.', [
                        'component' => 'event_dispatcher',
                        'event' => 'listener_failed',
                        'event_name' => $eventName,
                        'exception' => $error::class,
                    ]);
                } finally {
                    $lock?->release();
                    $this->queuedListenerTasks--;
                    unset($this->listenerFutures[$taskId]);
                }
            });
            $this->listenerFutures[$taskId] = $future;
            $future->ignore();
        }
    }

    /** Drain every accepted listener, including tasks enqueued by listeners. */
    public function shutdown(float $timeoutSeconds = 30): void
    {
        $cancellation = new \Amp\TimeoutCancellation(max(0.001, $timeoutSeconds));
        while (true) {
            $future = reset($this->listenerFutures);
            if ($future === false) {
                return;
            }
            try {
                $future->await($cancellation);
            } catch (\Amp\CancelledException) {
                $this->logger->warning('Event listeners did not drain before shutdown timeout.', [
                    'component' => 'event_dispatcher',
                    'event' => 'listener_shutdown_timeout',
                    'queued_listener_tasks' => $this->queuedListenerTasks,
                ]);

                // Keep the accepted callbacks and their dependencies owned so
                // a later shutdown attempt can finish the drain safely.
                throw new \RuntimeException('Event listener shutdown timed out.');
            } catch (\Throwable $error) {
                $this->logger->error('Event listener failed during shutdown.', [
                    'component' => 'event_dispatcher',
                    'event' => 'listener_shutdown_failed',
                    'exception' => $error::class,
                ]);
            }
        }
    }
}
