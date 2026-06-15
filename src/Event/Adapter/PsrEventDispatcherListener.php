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
    public function __construct(
        private object $dispatcher,
    ) {
        if (!method_exists($this->dispatcher, 'dispatch')) {
            throw new \InvalidArgumentException('PSR event dispatcher adapter requires a dispatch(object): object method.');
        }
    }

    public function __invoke(S3Event $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
