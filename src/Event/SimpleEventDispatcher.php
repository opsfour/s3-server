<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event;

use Amp\Sync\LocalSemaphore;
use OpsFour\S3Server\Contracts\EventDispatcher;

use function Amp\async;

/**
 * In-memory event dispatcher with asynchronous delivery via Amp fibers.
 *
 * Each registered listener is invoked in its own fiber when an event
 * is dispatched, ensuring non-blocking event handling. Listener
 * exceptions are silently caught to prevent one failing listener
 * from affecting others.
 *
 * A semaphore limits concurrent listener fibers to prevent unbounded
 * fiber accumulation under sustained load.
 */
final class SimpleEventDispatcher implements EventDispatcher
{
    /** @var array<string, list<callable(array<string, mixed>): void>> */
    private array $listeners = [];

    private readonly LocalSemaphore $semaphore;

    public function __construct(int $maxConcurrentListeners = 500)
    {
        $this->semaphore = new LocalSemaphore(max(1, $maxConcurrentListeners));
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
            $lock = $this->semaphore->acquire();
            async(function () use ($listener, $payload, $lock): void {
                try {
                    $listener($payload);
                } catch (\Throwable) {
                    // Silently ignore listener exceptions to prevent
                    // one failing listener from affecting request handling.
                } finally {
                    $lock->release();
                }
            });
        }
    }
}
