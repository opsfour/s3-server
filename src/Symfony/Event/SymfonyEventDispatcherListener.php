<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony\Event;

use OpsFour\S3Server\Event\S3Event;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class SymfonyEventDispatcherListener
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?string $eventName = null,
    ) {}

    public function __invoke(S3Event $event): void
    {
        $this->eventDispatcher->dispatch($event, $this->eventName ?? $event->name);
    }
}
