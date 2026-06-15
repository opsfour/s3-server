<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event\Adapter;

use OpsFour\S3Server\Event\S3Event;

/**
 * Bridges internal S3 events to an application queue.
 *
 * The enqueue callback receives the job produced by the optional factory.
 * Without a factory, the normalized event array is enqueued.
 */
final readonly class QueueJobListener
{
    public function __construct(
        private mixed $enqueue,
        private mixed $jobFactory = null,
    ) {}

    public function __invoke(S3Event $event): void
    {
        $job = $this->jobFactory !== null
            ? ($this->callableJobFactory())($event)
            : $event->toArray();

        ($this->callableEnqueue())($job, $event);
    }

    /**
     * @return callable(mixed, S3Event): void
     */
    private function callableEnqueue(): callable
    {
        if (!is_callable($this->enqueue)) {
            throw new \InvalidArgumentException('Queue job adapter requires a callable enqueue handler.');
        }

        return $this->enqueue;
    }

    /**
     * @return callable(S3Event): mixed
     */
    private function callableJobFactory(): callable
    {
        if (!is_callable($this->jobFactory)) {
            throw new \InvalidArgumentException('Queue job adapter factory must be callable.');
        }

        return $this->jobFactory;
    }
}
