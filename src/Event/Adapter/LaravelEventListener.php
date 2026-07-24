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
    private \Closure $dispatch;

    public function __construct(
        object $events,
        private ?string $eventName = null,
    ) {
        $dispatch = [$events, 'dispatch'];
        if (!is_callable($dispatch)) {
            throw new \InvalidArgumentException('Laravel event adapter requires a dispatch() method.');
        }

        $this->dispatch = \Closure::fromCallable($dispatch);
    }

    public function __invoke(S3Event $event): void
    {
        if ($this->eventName === null) {
            ($this->dispatch)($event);

            return;
        }

        ($this->dispatch)($this->eventName, [$event]);
    }
}
