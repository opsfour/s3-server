<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Event;

use OpsFour\S3Server\Event\Adapter\LaravelEventListener;
use OpsFour\S3Server\Event\Adapter\DestinationAdapterListener;
use OpsFour\S3Server\Event\Adapter\PsrEventDispatcherListener;
use OpsFour\S3Server\Event\Adapter\QueueJobListener;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\Destination\NotificationDeliveryResult;
use OpsFour\S3Server\Notification\Destination\NotificationDeliveryStatus;
use OpsFour\S3Server\Notification\Destination\NotificationDestinationAdapter;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class EventAdapterTest extends TestCase
{
    public function test_psr_event_dispatcher_adapter_dispatches_s3_event_object(): void
    {
        $dispatcher = new class {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $event = $this->event();

        (new PsrEventDispatcherListener($dispatcher))($event);

        $this->assertSame([$event], $dispatcher->events);
    }

    public function test_laravel_event_adapter_dispatches_event_object_or_named_event(): void
    {
        $events = new class {
            /** @var list<array{event: mixed, payload: mixed}> */
            public array $dispatched = [];

            public function dispatch(mixed $event, mixed $payload = []): mixed
            {
                $this->dispatched[] = ['event' => $event, 'payload' => $payload];

                return $event;
            }
        };
        $event = $this->event();

        (new LaravelEventListener($events))($event);
        (new LaravelEventListener($events, 's3.event'))($event);

        $this->assertSame($event, $events->dispatched[0]['event']);
        $this->assertSame([], $events->dispatched[0]['payload']);
        $this->assertSame('s3.event', $events->dispatched[1]['event']);
        $this->assertSame([$event], $events->dispatched[1]['payload']);
    }

    public function test_queue_job_adapter_enqueues_event_array_or_factory_job(): void
    {
        $jobs = [];
        $event = $this->event();

        (new QueueJobListener(static function (mixed $job, S3Event $event) use (&$jobs): void {
            $jobs[] = ['job' => $job, 'event' => $event];
        }))($event);
        (new QueueJobListener(
            static function (mixed $job, S3Event $event) use (&$jobs): void {
                $jobs[] = ['job' => $job, 'event' => $event];
            },
            static fn(S3Event $event): array => ['type' => 'custom-job', 'key' => $event->key],
        ))($event);

        $this->assertSame('s3:ObjectCreated:Put', $jobs[0]['job']['name']);
        $this->assertSame('bucket', $jobs[0]['job']['bucket']);
        $this->assertSame($event, $jobs[0]['event']);
        $this->assertSame(['type' => 'custom-job', 'key' => 'key.txt'], $jobs[1]['job']);
        $this->assertSame($event, $jobs[1]['event']);
    }

    public function test_adapters_work_as_notification_dispatcher_listeners(): void
    {
        $path = sys_get_temp_dir() . '/s3-event-adapter-' . bin2hex(random_bytes(4));
        mkdir($path, 0o755, true);

        try {
            $metadata = new SqliteMetadataStore($path . '/metadata.sqlite');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $dispatcher = new NotificationDispatcher($metadata);
            $jobs = [];

            $dispatcher->listen('s3:ObjectCreated:*', new QueueJobListener(
                static function (mixed $job) use (&$jobs): void {
                    $jobs[] = $job;
                },
            ));
            $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'key.txt', 7, '"etag"', 'owner');
            $this->runEventLoopTick();

            $this->assertCount(1, $jobs);
            $this->assertSame('s3:ObjectCreated:Put', $jobs[0]['name']);
            $this->assertSame('key.txt', $jobs[0]['key']);
        } finally {
            $this->recursiveDelete($path);
        }
    }

    public function test_destination_adapter_listener_logs_delivery_result_context(): void
    {
        $adapter = new RecordingDestinationAdapter(NotificationDeliveryResult::retryableFailure('broker unavailable', [
            'partition' => 3,
        ]));
        $logger = new EventAdapterArrayLogger();
        $event = $this->event();

        (new DestinationAdapterListener($adapter, $logger))($event);

        $this->assertSame([$event], $adapter->events);
        $context = $logger->findContext('destination_delivery_result');
        $this->assertNotNull($context);
        $this->assertSame('notification', $context['component']);
        $this->assertSame('recording', $context['adapter']);
        $this->assertSame('s3:ObjectCreated:Put', $context['event_name']);
        $this->assertSame('bucket', $context['bucket']);
        $this->assertSame('key.txt', $context['key']);
        $this->assertSame(NotificationDeliveryStatus::RetryableFailure->value, $context['status']);
        $this->assertSame('broker unavailable', $context['reason']);
        $this->assertSame(3, $context['partition']);
    }

    public function test_destination_adapter_listener_rethrows_adapter_exceptions_for_dispatcher_observability(): void
    {
        $path = sys_get_temp_dir() . '/s3-destination-adapter-' . bin2hex(random_bytes(4));
        mkdir($path, 0o755, true);

        try {
            $metadata = new SqliteMetadataStore($path . '/metadata.sqlite');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $logger = new EventAdapterArrayLogger();
            $dispatcher = new NotificationDispatcher($metadata, $logger);
            $dispatcher->listen('s3:ObjectCreated:*', new DestinationAdapterListener(
                new ThrowingDestinationAdapter(),
                $logger,
            ));

            $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'key.txt', 7, '"etag"', 'owner');
            $this->runEventLoopTick();

            $adapterFailure = $logger->findContext('destination_adapter_failed');
            $this->assertNotNull($adapterFailure);
            $this->assertSame('throwing', $adapterFailure['adapter']);
            $this->assertSame(\RuntimeException::class, $adapterFailure['exception']);

            $listenerFailure = $logger->findContext('listener_failed');
            $this->assertNotNull($listenerFailure);
            $this->assertSame('s3:ObjectCreated:Put', $listenerFailure['event_name']);
        } finally {
            $this->recursiveDelete($path);
        }
    }

    private function event(): S3Event
    {
        return new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'key.txt',
            size: 7,
            etag: '"etag"',
            ownerId: 'owner',
        );
    }

    private function runEventLoopTick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}

final class RecordingDestinationAdapter implements NotificationDestinationAdapter
{
    /** @var list<S3Event> */
    public array $events = [];

    public function __construct(
        private readonly NotificationDeliveryResult $result,
    ) {}

    public function name(): string
    {
        return 'recording';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        $this->events[] = $event;

        return $this->result;
    }
}

final class ThrowingDestinationAdapter implements NotificationDestinationAdapter
{
    public function name(): string
    {
        return 'throwing';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        throw new \RuntimeException('adapter exploded');
    }
}

final class EventAdapterArrayLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findContext(string $event): ?array
    {
        foreach ($this->records as $record) {
            if (($record['context']['event'] ?? null) === $event) {
                return $record['context'];
            }
        }

        return null;
    }
}
