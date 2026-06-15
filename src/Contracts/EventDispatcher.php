<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

/**
 * Contract for dispatching S3 event notifications.
 *
 * Allows the server to emit events (e.g., s3:ObjectCreated:*, s3:ObjectRemoved:*)
 * that external listeners can subscribe to for event-driven workflows.
 */
interface EventDispatcher
{
    /**
     * Dispatch an event to all registered listeners.
     *
     * Events are fired asynchronously — dispatching does not block the caller.
     *
     * @param  string  $eventName  The event name (e.g., 's3:ObjectCreated:Put').
     * @param  array<string, mixed>  $payload  The event payload data.
     */
    public function dispatch(string $eventName, array $payload = []): void;

    /**
     * Register a listener for an event.
     *
     * @param  string  $eventName  The event name to listen for.
     * @param  callable(array<string, mixed>): void  $listener  The listener callback.
     */
    public function listen(string $eventName, callable $listener): void;
}
