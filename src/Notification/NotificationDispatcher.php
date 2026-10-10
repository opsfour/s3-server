<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Sync\LocalSemaphore;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Security\RequestNetworkContext;
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

    private bool $efsCandidateSealed = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        LoggerInterface $logger = new NullLogger(),
        private readonly string $region = 'us-east-1',
        private readonly int $maxConcurrentListeners = 100,
        private readonly int $maxQueuedListenerTasks = 10_000,
        private readonly ?MetricsCollector $metrics = null,
        private readonly ?EfsHostEvidenceReader $efsEvidence = null,
    ) {
        if ($this->maxConcurrentListeners < 1) {
            throw new \InvalidArgumentException('Notification listener concurrency must be >= 1.');
        }

        if ($this->maxQueuedListenerTasks < 1) {
            throw new \InvalidArgumentException('Notification listener queue limit must be >= 1.');
        }

        $this->listenerSemaphore = new LocalSemaphore($this->maxConcurrentListeners);
        $this->logger = new FailureIsolatingLogger($logger);
    }

    /** Prove that an inert EFS candidate uses the exact SQL facade and watcher. */
    public function assertBoundTo(MetadataStore $metadata, EfsHostEvidenceReader $evidence): void
    {
        if ($this->metadata !== $metadata || $this->efsEvidence !== $evidence || $this->listeners !== []) {
            throw new \RuntimeException('Notification dispatcher differs from the EFS candidate resources.');
        }
        $evidence->assertFresh();
        $this->efsCandidateSealed = true;
    }

    /**
     * Register an internal listener for an exact S3 event name or wildcard.
     *
     * Supported patterns:
     * - `s3:ObjectCreated:Put`
     * - `s3:ObjectCreated:*`
     * - `s3:*`
     *
     * @param  callable(S3Event): void  $listener
     */
    public function listen(string $eventPattern, callable $listener): void
    {
        if ($this->efsEvidence !== null) {
            throw new \LogicException('Internal listeners are unavailable for the EFS candidate.');
        }
        $this->listeners[$eventPattern][] = $listener;
    }

    public function removeListeners(?string $eventPattern = null): void
    {
        if ($this->efsEvidence !== null) {
            throw new \LogicException('EFS candidate listener registrations are frozen.');
        }
        if ($eventPattern === null) {
            $this->listeners = [];

            return;
        }

        unset($this->listeners[$eventPattern]);
    }

    /**
     * Enqueue event notifications for a bucket/key operation.
     *
     * @param  string  $eventName  e.g., 's3:ObjectCreated:Put'
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  int  $size  Object size.
     * @param  string  $etag  Object ETag.
     * @param  string  $ownerId  The requester.
     */
    public function dispatch(
        string $eventName,
        string $bucket,
        string $key,
        int $size = 0,
        string $etag = '',
        string $ownerId = '',
        string $bucketGeneration = '',
    ): void {
        $this->assertCandidateReady();
        $event = $this->createEvent(
            name: $eventName,
            bucket: $bucket,
            key: $key,
            size: $size,
            etag: $etag,
            ownerId: $ownerId,
            bucketGeneration: $bucketGeneration,
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
        ?Request $request = null,
        string $bucketGeneration = '',
    ): S3Event {
        if ($request !== null) {
            $sourceIp = RequestNetworkContext::sourceIp($request);
            if ($sourceIp !== null) {
                $attributes['sourceIp'] = $sourceIp;
            }
            if ($request->hasAttribute('requestId')) {
                $attributes['requestId'] = (string) $request->getAttribute('requestId');
            }
            if ($request->hasAttribute('amzId2')) {
                $attributes['hostId'] = (string) $request->getAttribute('amzId2');
            }
        }

        return new S3Event(
            name: $name,
            bucket: $bucket,
            key: $key,
            size: $size,
            etag: $etag,
            ownerId: $ownerId,
            region: $this->region,
            attributes: $attributes,
            bucketGeneration: $bucketGeneration,
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
        $this->assertCandidateReady();
        // A delayed event must not use the notification configuration of a
        // later bucket with the same name. Unscoped external events can still
        // reach internal listeners, but cannot enqueue a webhook.
        if ($event->bucketGeneration === '') {
            return;
        }
        $this->assertCandidateReady();
        $bucketInfo = $this->metadata->getBucket($event->bucket);
        if ($bucketInfo === null || $bucketInfo->generation !== $event->bucketGeneration) {
            return;
        }
        $bucketOwnerId = $bucketInfo->ownerId;

        // Object writes acquire this same owner lock before committing metadata.
        // It makes queue IDs comparable for one key even when different nodes
        // enqueue the events. Re-read the bucket and its config after locking:
        // both may have changed since the event was created.
        $this->assertCandidateReady();
        $queued = $this->metadata->transaction(function () use ($event, $bucketOwnerId): int {
            $this->assertCandidateReady();
            OwnerWriteLock::acquire($this->metadata, $bucketOwnerId);
            $this->assertCandidateReady();
            $current = $this->metadata->getBucket($event->bucket);
            if ($current === null || $current->ownerId !== $bucketOwnerId
                || $current->generation !== $event->bucketGeneration) {
                return 0;
            }

            $destinations = [];
            $this->assertCandidateReady();
            foreach ($this->metadata->getBucketNotification($event->bucket) as $config) {
                if (! $this->eventsMatch($event->name, $config['events'])) {
                    continue;
                }

                if (! $this->keyMatchesFilters($event->key, array_values($config['filterRules'] ?? []))) {
                    continue;
                }

                $destination = $config['destinationArn'];
                if (! str_starts_with($destination, 'http://') && ! str_starts_with($destination, 'https://')) {
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

                $destinations[] = $config;
            }
            if ($destinations === []) {
                return 0;
            }

            $eventTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
            $orderedEvent = str_starts_with($event->name, 's3:ObjectCreated:')
                || str_starts_with($event->name, 's3:ObjectRemoved:');
            $sequencer = null;
            foreach ($destinations as $config) {
                $payload = $this->eventPayload($event, $bucketOwnerId, $config['id'], $sequencer, $eventTime);
                $this->assertCandidateReady();
                $id = $this->metadata->enqueueNotification(
                    $event->bucket,
                    $event->key,
                    $event->name,
                    $config['destinationArn'],
                    $payload,
                );
                if ($orderedEvent && $sequencer === null) {
                    $sequencer = strtoupper(str_pad(dechex($id), 16, '0', STR_PAD_LEFT));
                    $this->assertCandidateReady();
                    $this->metadata->updateNotificationPayload(
                        $id,
                        $this->eventPayload($event, $bucketOwnerId, $config['id'], $sequencer, $eventTime),
                    );
                }
            }
            return count($destinations);
        });
        for ($i = 0; $i < $queued; $i++) {
            $this->metrics?->recordNotificationEvent('webhook_enqueue', 'success');
        }
    }

    private function eventPayload(S3Event $event, string $bucketOwnerId, string $configurationId, ?string $sequencer, string $eventTime): string
    {
        return S3EventPayload::build(
            eventName: $event->name,
            bucket: $event->bucket,
            key: $event->key,
            size: $event->size,
            etag: $event->etag,
            ownerId: $event->ownerId,
            region: $event->region,
            bucketOwnerId: $bucketOwnerId,
            configurationId: $configurationId,
            sourceIp: is_string($event->attributes['sourceIp'] ?? null) ? $event->attributes['sourceIp'] : null,
            requestId: is_string($event->attributes['requestId'] ?? null) ? $event->attributes['requestId'] : null,
            hostId: is_string($event->attributes['hostId'] ?? null) ? $event->attributes['hostId'] : null,
            versionId: is_string($event->attributes['versionId'] ?? null) ? $event->attributes['versionId'] : null,
            sequencer: $sequencer,
            eventTime: $eventTime,
            sizeKnown: ($event->attributes['sizeKnown'] ?? false) === true,
            restoreExpiresAt: is_string($event->attributes['restoreExpiresAt'] ?? null) ? $event->attributes['restoreExpiresAt'] : null,
            sourceStorageClass: is_string($event->attributes['sourceTier'] ?? null) ? $event->attributes['sourceTier'] : null,
        );
    }

    public function dispatchInternalEvent(S3Event $event): void
    {
        // Qualified candidates never admit internal listeners. There is no
        // callback or SQL to protect here, and post-commit notification cleanup
        // must not turn an already committed S3 write into an error response.
        if ($this->efsEvidence !== null && $this->listeners === []) {
            return;
        }
        $this->assertCandidateReady();
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
                    $this->assertCandidateReady();
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
        $cancellation = new \Amp\TimeoutCancellation(max(0.001, $timeoutSeconds));
        $timedOut = false;
        // A listener may dispatch another event before it returns. Keep
        // draining the live set instead of iterating a snapshot that misses
        // those newly queued callbacks.
        while (true) {
            $future = reset($this->listenerFutures);
            if ($future === false) {
                return;
            }
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
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Notification listener failed during shutdown.', [
                    'component' => 'notification',
                    'event' => 'listener_shutdown_failed',
                    'exception' => $e::class,
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
            if (! $this->eventMatches($eventName, $pattern)) {
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
     * @param  list<string>  $events
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
     * @param  list<array{name: string, value: string}>  $filterRules
     */
    private function keyMatchesFilters(string $key, array $filterRules): bool
    {
        foreach ($filterRules as $rule) {
            $name = strtolower($rule['name']);
            // S3 stores notification filter values in form-encoded form:
            // '+' means space and a literal plus is represented as '%2B'.
            $value = urldecode($rule['value']);

            if ($name === 'prefix' && ! str_starts_with($key, $value)) {
                return false;
            }
            if ($name === 'suffix' && ! str_ends_with($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private function assertCandidateReady(): void
    {
        if ($this->efsEvidence === null) {
            return;
        }
        if (! $this->efsCandidateSealed) {
            throw new \LogicException('EFS candidate notification dispatcher is not sealed.');
        }
        $this->efsEvidence->assertFresh();
    }
}
