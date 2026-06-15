<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class NotificationDispatcherTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-notification-dispatcher-' . bin2hex(random_bytes(4));
        mkdir($this->path, 0o755, true);

        $this->metadata = new SqliteMetadataStore($this->path . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_dir($this->path)) {
            $this->recursiveDelete($this->path);
        }

        parent::tearDown();
    }

    public function test_internal_wildcard_listener_receives_normalized_s3_event_and_webhook_queue_still_works(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);

        $dispatcher = new NotificationDispatcher($this->metadata);
        $received = [];
        $dispatcher->listen('s3:ObjectCreated:*', static function (S3Event $event) use (&$received): void {
            $received[] = $event;
        });

        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'logs/new.txt', 12, '"etag"', 'owner');
        $this->runEventLoopTick();

        $this->assertCount(1, $received);
        $this->assertSame('s3:ObjectCreated:Put', $received[0]->name);
        $this->assertSame('bucket', $received[0]->bucket);
        $this->assertSame('logs/new.txt', $received[0]->key);
        $this->assertSame(12, $received[0]->size);
        $this->assertSame('"etag"', $received[0]->etag);
        $this->assertSame('owner', $received[0]->ownerId);

        $queued = $this->metadata->dequeueNotifications(10);
        $this->assertCount(1, $queued);
        $this->assertSame('s3:ObjectCreated:Put', $queued[0]['event_name']);
        $this->assertSame('https://notifications.example.test/s3', $queued[0]['destination_url']);
    }

    public function test_failed_internal_listener_is_isolated_and_logged(): void
    {
        $logger = new NotificationArrayLogger;
        $metrics = new MetricsCollector;
        $dispatcher = new NotificationDispatcher($this->metadata, $logger, metrics: $metrics);
        $received = [];

        $dispatcher->listen('s3:ObjectRemoved:*', static function (): void {
            throw new \RuntimeException('listener failed intentionally');
        });
        $dispatcher->listen('s3:ObjectRemoved:Delete', static function (S3Event $event) use (&$received): void {
            $received[] = $event->key;
        });

        $dispatcher->dispatch('s3:ObjectRemoved:Delete', 'bucket', 'old.txt', ownerId: 'owner');
        $this->runEventLoopTick();

        $this->assertSame(['old.txt'], $received);
        $this->assertNotNull($logger->findContext('listener_failed', [
            'component' => 'notification',
            'event_name' => 's3:ObjectRemoved:Delete',
            'bucket' => 'bucket',
            'key' => 'old.txt',
            'listener_pattern' => 's3:ObjectRemoved:*',
            'exception' => \RuntimeException::class,
        ]));

        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_notification_events_total{stage="listener",status="queued"} 2', $rendered);
        $this->assertStringContainsString('s3_server_notification_events_total{stage="listener",status="failed"} 1', $rendered);
        $this->assertStringContainsString('s3_server_notification_events_total{stage="listener",status="success"} 1', $rendered);
    }

    public function test_listener_queue_limit_drop_is_observable(): void
    {
        $metrics = new MetricsCollector;
        $dispatcher = new NotificationDispatcher(
            $this->metadata,
            maxConcurrentListeners: 1,
            maxQueuedListenerTasks: 1,
            metrics: $metrics,
        );

        $dispatcher->listen('s3:*', static function (): void {
            \Amp\delay(0.05);
        });
        $dispatcher->listen('s3:*', static function (): void {
        });

        $dispatcher->dispatchEvent(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'queued.txt',
            ownerId: 'owner',
        ), enqueueWebhooks: false);
        $this->runEventLoopTick();

        $this->assertStringContainsString(
            's3_server_notification_events_total{stage="listener",status="dropped"} 1',
            $metrics->renderPrometheus(),
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

final class NotificationArrayLogger extends AbstractLogger
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
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>|null
     */
    public function findContext(string $event, array $expected): ?array
    {
        foreach ($this->records as $record) {
            $context = $record['context'];
            if (($context['event'] ?? null) !== $event) {
                continue;
            }

            foreach ($expected as $key => $value) {
                if (($context[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $context;
        }

        return null;
    }
}
