<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

use Amp\Sync\LocalSemaphore;
use Amp\Future;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * Enqueues S3 event notifications into the persistent notification queue.
 *
 * Matches events against bucket notification configs, checks key filters,
 * and inserts matching notifications into the database queue for asynchronous
 * delivery by NotificationProcessor.
 *
 * Also exposes a normalized internal listener API for applications that want
 * to react to S3 events without using the webhook queue.
 */
final class NotificationDispatcher
{
    /** @var array<string, list<callable(S3Event): void>> */
    private array $listeners = [];

    private readonly LocalSemaphore $listenerSemaphore;

    private int $queuedListenerTasks = 0;

    /** @var array<int, Future<void>> */
    private array $listenerFutures = [];

    private int $nextListenerTaskId = 0;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $region = 'us-east-1',
        private readonly int $maxConcurrentListeners = 100,
        private readonly int $maxQueuedListenerTasks = 10_000,
        private readonly ?MetricsCollector $metrics = null,
    ) {
        if ($this->maxConcurrentListeners < 1) {
            throw new \InvalidArgumentException('Notification listener concurrency must be >= 1.');
        }

        if ($this->maxQueuedListenerTasks < 1) {
            throw new \InvalidArgumentException('Notification listener queue limit must be >= 1.');
        }

        $this->listenerSemaphore = new LocalSemaphore($this->maxConcurrentListeners);
    }

    /**
     * Register an internal listener for an exact S3 event name or wildcard.
     *
     * Supported patterns:
     * - `s3:ObjectCreated:Put`
     * - `s3:ObjectCreated:*`
     * - `s3:*`
     *
     * @param callable(S3Event): void $listener
     */
    public function listen(string $eventPattern, callable $listener): void
    {
        $this->listeners[$eventPattern][] = $listener;
    }

    public function removeListeners(?string $eventPattern = null): void
    {
        if ($eventPattern === null) {
            $this->listeners = [];

            return;
        }

        unset($this->listeners[$eventPattern]);
    }

    /**
     * Enqueue event notifications for a bucket/key operation.
     *
     * @param string $eventName e.g., 's3:ObjectCreated:Put'
     * @param string $bucket The bucket name.
     * @param string $key The object key.
     * @param int $size Object size.
     * @param string $etag Object ETag.
     * @param string $ownerId The requester.
     */
    public function dispatch(
        string $eventName,
        string $bucket,
        string $key,
        int $size = 0,
        string $etag = '',
        string $ownerId = '',
    ): void {
        $event = $this->createEvent(
            name: $eventName,
            bucket: $bucket,
            key: $key,
            size: $size,
            etag: $etag,
            ownerId: $ownerId,
        );
        $this->dispatchEvent($event);
    }

    /** @param array<string, mixed> $attributes */
    public function createEvent(
        string $name,
        string $bucket,
        string $key,
        int $size = 0,
        string $etag = '',
        string $ownerId = '',
        array $attributes = [],
    ): S3Event {
        return new S3Event(
            name: $name,
            bucket: $bucket,
            key: $key,
            size: $size,
            etag: $etag,
            ownerId: $ownerId,
            region: $this->region,
            attributes: $attributes,
        );
    }

    public function dispatchEvent(S3Event $event, bool $enqueueWebhooks = true): void
    {
        if ($enqueueWebhooks) {
            $this->enqueueWebhooks($event);
        }
        $this->dispatchInternalEvent($event);
    }

    public function enqueueWebhooks(S3Event $event): void
    {
        $configs = $this->metadata->getBucketNotification($event->bucket);

        if ($configs === []) {
            return;
        }

        foreach ($configs as $config) {
            if (!$this->eventsMatch($event->name, $config['events'])) {
                continue;
            }

            if (!$this->keyMatchesFilters($event->key, array_values($config['filterRules'] ?? []))) {
                continue;
            }

            $destination = $config['destinationArn'];

            // Only enqueue HTTP(S) URL destinations.
            if (!str_starts_with($destination, 'http://') && !str_starts_with($destination, 'https://')) {
                $this->logger->debug('Skipping non-URL notification destination.', [
                    'component' => 'notification',
                    'event' => 'destination_skipped',
                    'bucket' => $event->bucket,
                    'key' => $event->key,
                    'event_name' => $event->name,
                    'destination_type' => $config['destinationType'],
                ]);
                $this->metrics?->recordNotificationEvent('webhook_destination', 'skipped');
                continue;
            }

            $payload = S3EventPayload::build(
                $event->name,
                $event->bucket,
                $event->key,
                $event->size,
                $event->etag,
                $event->ownerId,
                $event->region,
            );

            $this->metadata->enqueueNotification($event->bucket, $event->key, $event->name, $destination, $payload);
            $this->metrics?->recordNotificationEvent('webhook_enqueue', 'success');
        }
    }

    public function dispatchInternalEvent(S3Event $event): void
    {
        foreach ($this->matchingListeners($event->name) as $pattern => $listener) {
            if ($this->queuedListenerTasks >= $this->maxQueuedListenerTasks) {
                $this->metrics?->recordNotificationEvent('listener', 'dropped');
                $this->logger->warning('Notification listener task dropped because the queue limit is reached.', [
                    'component' => 'notification',
                    'event' => 'listener_task_dropped',
                    'event_name' => $event->name,
                    'bucket' => $event->bucket,
                    'key' => $event->key,
                    'listener_pattern' => $pattern,
                    'queued_listener_tasks' => $this->queuedListenerTasks,
                    'max_queued_listener_tasks' => $this->maxQueuedListenerTasks,
                ]);

                continue;
            }

            $this->queuedListenerTasks++;
            $this->metrics?->recordNotificationEvent('listener', 'queued');
            $taskId = ++$this->nextListenerTaskId;
            $this->listenerFutures[$taskId] = async(function () use ($event, $listener, $pattern, $taskId): void {
                $lock = null;
                try {
                    $lock = $this->listenerSemaphore->acquire();
                    $listener($event);
                    $this->metrics?->recordNotificationEvent('listener', 'success');
                } catch (\Throwable $e) {
                    $this->metrics?->recordNotificationEvent('listener', 'failed');
                    $this->logger->error('Notification listener failed.', [
                        'component' => 'notification',
                        'event' => 'listener_failed',
                        'event_name' => $event->name,
                        'bucket' => $event->bucket,
                        'key' => $event->key,
                        'listener_pattern' => $pattern,
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $lock?->release();
                    $this->queuedListenerTasks--;
                    unset($this->listenerFutures[$taskId]);
                }
            });
        }
    }

    public function shutdown(float $timeoutSeconds = 30): void
    {
        if ($this->listenerFutures === []) {
            return;
        }

        $cancellation = new \Amp\TimeoutCancellation(max(0.001, $timeoutSeconds));
        $timedOut = false;
        foreach ($this->listenerFutures as $future) {
            try {
                $future->await($timedOut ? null : $cancellation);
            } catch (\Amp\CancelledException) {
                $timedOut = true;
                $this->logger->warning('Notification listeners did not drain before shutdown timeout.', [
                    'component' => 'notification',
                    'event' => 'listener_shutdown_timeout',
                    'queued_listener_tasks' => $this->queuedListenerTasks,
                ]);

                // Listener callbacks cannot be cancelled safely. Keep runtime
                // dependencies alive until they return; the process supervisor
                // remains responsible for enforcing a hard shutdown deadline.
                try {
                    $future->await();
                } catch (\Throwable $e) {
                    $this->logger->error('Notification listener failed during shutdown.', [
                        'component' => 'notification',
                        'event' => 'listener_shutdown_failed',
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Notification listener failed during shutdown.', [
                    'component' => 'notification',
                    'event' => 'listener_shutdown_failed',
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return iterable<string, callable(S3Event): void>
     */
    private function matchingListeners(string $eventName): iterable
    {
        foreach ($this->listeners as $pattern => $listeners) {
            if (!$this->eventMatches($eventName, $pattern)) {
                continue;
            }

            foreach ($listeners as $listener) {
                yield $pattern => $listener;
            }
        }
    }

    /**
     * Check if the event matches any event in the config's events list.
     *
     * @param list<string> $events
     */
    private function eventsMatch(string $eventName, array $events): bool
    {
        foreach ($events as $event) {
            if ($this->eventMatches($eventName, $event)) {
                return true;
            }
        }

        return false;
    }

    private function eventMatches(string $eventName, string $configEventType): bool
    {
        if ($configEventType === $eventName) {
            return true;
        }

        // Wildcard matching: s3:ObjectCreated:* matches s3:ObjectCreated:Put
        // Also handles s3:* (matches everything starting with "s3:")
        if (str_ends_with($configEventType, ':*')) {
            $prefix = substr($configEventType, 0, -1);
            return str_starts_with($eventName, $prefix);
        }

        return false;
    }

    /**
     * @param list<array{name: string, value: string}> $filterRules
     */
    private function keyMatchesFilters(string $key, array $filterRules): bool
    {
        foreach ($filterRules as $rule) {
            $name = strtolower($rule['name']);
            $value = $rule['value'];

            if ($name === 'prefix' && !str_starts_with($key, $value)) {
                return false;
            }
            if ($name === 'suffix' && !str_ends_with($key, $value)) {
                return false;
            }
        }

        return true;
    }
}
