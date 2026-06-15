<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event\Adapter;

use OpsFour\S3Server\Event\S3Event;

/**
 * Bridges internal S3 events to Laravel's event dispatcher.
 *
 * The dispatcher is duck-typed so the library remains usable outside Laravel.
 * Pass Laravel's `events` service or `Illuminate\Contracts\Events\Dispatcher`.
 */
final readonly class LaravelEventListener
{
    public function __construct(
        private object $events,
        private ?string $eventName = null,
    ) {
        if (!method_exists($this->events, 'dispatch')) {
            throw new \InvalidArgumentException('Laravel event adapter requires a dispatch() method.');
        }
    }

    public function __invoke(S3Event $event): void
    {
        if ($this->eventName === null) {
            $this->events->dispatch($event);

            return;
        }

        $this->events->dispatch($this->eventName, [$event]);
    }
}
