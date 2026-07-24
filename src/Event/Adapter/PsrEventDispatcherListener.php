<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event\Adapter;

use OpsFour\S3Server\Event\S3Event;

/**
 * Bridges internal S3 events to a PSR-14 style dispatcher.
 *
 * The dispatcher is intentionally duck-typed to avoid forcing
 * psr/event-dispatcher as a hard dependency of the core package.
 */
final readonly class PsrEventDispatcherListener
{
    private \Closure $dispatch;

    public function __construct(
        object $dispatcher,
    ) {
        $dispatch = [$dispatcher, 'dispatch'];
        if (!is_callable($dispatch)) {
            throw new \InvalidArgumentException('PSR event dispatcher adapter requires a dispatch(object): object method.');
        }

        $this->dispatch = \Closure::fromCallable($dispatch);
    }

    public function __invoke(S3Event $event): void
    {
        ($this->dispatch)($event);
    }
}
