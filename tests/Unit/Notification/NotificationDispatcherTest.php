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

        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'logs/new.txt', 12, '"etag"', 'owner', $this->bucketGeneration());
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

    public function test_delayed_event_cannot_use_a_recreated_buckets_webhook_configuration(): void
    {
        $dispatcher = new NotificationDispatcher($this->metadata);
        $oldEvent = $dispatcher->createEvent('s3:ObjectCreated:Put', 'bucket', 'alice-private-key', ownerId: 'owner', bucketGeneration: $this->bucketGeneration());
        self::assertNotSame('', $oldEvent->bucketGeneration);
        self::assertSame($oldEvent->bucketGeneration, $oldEvent->withVersionId('v1')->bucketGeneration);

        $this->metadata->deleteBucket('owner', 'bucket');
        $this->metadata->createBucket('bob', 'bucket', 'us-east-1');
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'bob-webhook',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://bob.example.test/notify',
            'filterRules' => null,
        ]]);

        $dispatcher->enqueueWebhooks($oldEvent);
        $database = new \PDO('sqlite:' . $this->path . '/metadata.sqlite');
        $count = $database->query('SELECT COUNT(*) FROM s3_notification_queue');
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());

        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'bob-key', ownerId: 'bob', bucketGeneration: $this->bucketGeneration());
        $queued = $this->metadata->dequeueNotifications(10);
        self::assertCount(1, $queued);
        self::assertSame('bob-key', $queued[0]['key_name']);
        self::assertSame('https://bob.example.test/notify', $queued[0]['destination_url']);
    }

    public function test_unscoped_external_event_cannot_enqueue_webhooks(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);

        $dispatcher = new NotificationDispatcher($this->metadata);
        $dispatcher->enqueueWebhooks(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'unscoped-key',
            ownerId: 'owner',
        ));
        $dispatcher->enqueueWebhooks($dispatcher->createEvent('s3:ObjectCreated:Put', 'bucket', 'unscoped-created-key'));
        self::assertSame([], $this->metadata->dequeueNotifications(10));
    }

    public function test_delayed_dispatch_short_form_cannot_send_old_owner_event_after_bucket_reuse(): void
    {
        $oldGeneration = $this->bucketGeneration();
        $this->metadata->deleteBucket('owner', 'bucket');
        $this->metadata->createBucket('bob', 'bucket', 'us-east-1');
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'bob-webhook',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://bob.example.test/notify',
            'filterRules' => null,
        ]]);

        $dispatcher = new NotificationDispatcher($this->metadata);
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'alice-private-key', ownerId: 'owner');
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'alice-private-key', ownerId: 'owner', bucketGeneration: $oldGeneration);
        $database = new \PDO('sqlite:' . $this->path . '/metadata.sqlite');
        $count = $database->query('SELECT COUNT(*) FROM s3_notification_queue');
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());

        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'bob-key', ownerId: 'bob', bucketGeneration: $this->bucketGeneration());
        $queued = $this->metadata->dequeueNotifications(10);
        self::assertCount(1, $queued);
        self::assertSame('bob-key', $queued[0]['key_name']);
        self::assertSame('https://bob.example.test/notify', $queued[0]['destination_url']);
    }

    public function test_webhook_payload_uses_s3_event_fields_without_invented_request_provenance(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);

        (new NotificationDispatcher($this->metadata))->dispatch(
            's3:ObjectCreated:Put',
            'bucket',
            'space + sign.txt',
            12,
            '"etag"',
            'requester',
            $this->bucketGeneration(),
        );

        $queued = $this->metadata->dequeueNotifications(1);
        self::assertCount(1, $queued);
        $payload = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $record = $payload['Records'][0];
        self::assertSame('ObjectCreated:Put', $record['eventName']);
        self::assertSame('requester', $record['userIdentity']['principalId']);
        self::assertSame('webhook-created', $record['s3']['configurationId']);
        self::assertSame('owner', $record['s3']['bucket']['ownerIdentity']['principalId']);
        self::assertSame('space+%2B+sign.txt', $record['s3']['object']['key']);
        self::assertArrayNotHasKey('requestParameters', $record);
        self::assertArrayNotHasKey('responseElements', $record);
        self::assertMatchesRegularExpression('/^[0-9A-F]{16,}$/', $record['s3']['object']['sequencer']);
    }

    public function test_webhook_payload_preserves_the_event_occurrence_time_when_enqueued_later(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:Put'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);
        $occurredAt = new \DateTimeImmutable('2026-09-08T07:06:05.432100+02:00');
        $event = new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'delayed.txt',
            ownerId: 'owner',
            occurredAt: $occurredAt,
            bucketGeneration: $this->bucketGeneration(),
        );

        (new NotificationDispatcher($this->metadata))->enqueueWebhooks($event);

        $queued = $this->metadata->dequeueNotifications(1);
        self::assertCount(1, $queued);
        $record = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertSame('2026-09-08T05:06:05.432Z', $record['eventTime']);
    }

    public function test_webhook_sequencer_is_shared_by_destinations_and_increases_after_restart(): void
    {
        $configs = [
            [
                'id' => 'first',
                'events' => ['s3:ObjectCreated:*', 's3:ObjectRemoved:*'],
                'destinationType' => 'Topic',
                'destinationArn' => 'https://first.example.test/s3',
                'filterRules' => null,
            ],
            [
                'id' => 'second',
                'events' => ['s3:ObjectCreated:*', 's3:ObjectRemoved:*'],
                'destinationType' => 'Topic',
                'destinationArn' => 'https://second.example.test/s3',
                'filterRules' => null,
            ],
        ];
        $this->metadata->putBucketNotification('bucket', $configs);

        $generation = $this->bucketGeneration();
        (new NotificationDispatcher($this->metadata))->dispatch('s3:ObjectCreated:Put', 'bucket', 'same-key', bucketGeneration: $generation);

        // A new store instance represents a second API process using the same DB.
        $secondStore = new SqliteMetadataStore($this->path . '/metadata.sqlite');
        $secondStore->initialize();
        (new NotificationDispatcher($secondStore))->dispatch('s3:ObjectRemoved:Delete', 'bucket', 'same-key', bucketGeneration: $generation);

        $queued = $this->metadata->dequeueNotifications(10);
        self::assertCount(4, $queued);
        $sequences = array_map(static function (array $row): string {
            $record = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];

            return $record['s3']['object']['sequencer'];
        }, $queued);
        self::assertSame($sequences[0], $sequences[1]);
        self::assertSame($sequences[2], $sequences[3]);
        self::assertLessThan(hexdec($sequences[2]), hexdec($sequences[0]));
        $firstRecord = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        $secondRecord = json_decode($queued[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertSame($firstRecord['eventTime'], $secondRecord['eventTime']);
    }

    public function test_restore_webhook_omits_put_delete_only_sequencer(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'restore',
            'events' => ['s3:ObjectRestore:Completed'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://example.test/restore',
            'filterRules' => null,
        ]]);

        (new NotificationDispatcher($this->metadata))->dispatch('s3:ObjectRestore:Completed', 'bucket', 'archived', bucketGeneration: $this->bucketGeneration());

        $queued = $this->metadata->dequeueNotifications(1);
        self::assertCount(1, $queued);
        $record = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertArrayNotHasKey('sequencer', $record['s3']['object']);

        $restored = (new NotificationDispatcher($this->metadata))->createEvent(
            's3:ObjectRestore:Completed',
            'bucket',
            'empty-archive',
            size: 0,
            etag: '"empty-etag"',
            attributes: [
                'sizeKnown' => true,
                'sourceTier' => 'GLACIER',
                'restoreExpiresAt' => '2026-10-04T12:00:00+00:00',
            ],
            bucketGeneration: $this->bucketGeneration(),
        );
        (new NotificationDispatcher($this->metadata))->enqueueWebhooks($restored);
        $restoredRows = $this->metadata->dequeueNotifications(1);
        self::assertCount(1, $restoredRows);
        $restoredRecord = json_decode($restoredRows[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertSame(0, $restoredRecord['s3']['object']['size']);
        self::assertSame('empty-etag', $restoredRecord['s3']['object']['eTag']);
        self::assertArrayNotHasKey('sequencer', $restoredRecord['s3']['object']);
        self::assertSame([
            'lifecycleRestorationExpiryTime' => '2026-10-04T12:00:00+00:00',
            'lifecycleRestoreStorageClass' => 'GLACIER',
        ], $restoredRecord['glacierEventData']['restoreEventData']);
    }

    public function test_url_encoded_key_filters_match_decoded_object_keys(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'encoded-key-filter',
            'events' => ['s3:ObjectCreated:Put'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => [
                ['name' => 'prefix', 'value' => 'red+flower%2B'],
                ['name' => 'suffix', 'value' => '%2B%E2%82%AC.txt'],
            ],
        ]]);
        $dispatcher = new NotificationDispatcher($this->metadata);

        $generation = $this->bucketGeneration();
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'red flower+photo+€.txt', bucketGeneration: $generation);
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'red+flower+photo+€.txt', bucketGeneration: $generation);
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'red flower+photo+$.txt', bucketGeneration: $generation);

        $queued = $this->metadata->dequeueNotifications(10);
        self::assertSame(['red flower+photo+€.txt'], array_column($queued, 'key_name'));
        self::assertSame([
            ['name' => 'prefix', 'value' => 'red+flower%2B'],
            ['name' => 'suffix', 'value' => '%2B%E2%82%AC.txt'],
        ], $this->metadata->getBucketNotification('bucket')[0]['filterRules']);
    }

    public function test_failed_internal_listener_is_isolated_and_logged(): void
    {
        $logger = new NotificationArrayLogger();
        $metrics = new MetricsCollector();
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
        $metrics = new MetricsCollector();
        $dispatcher = new NotificationDispatcher(
            $this->metadata,
            maxConcurrentListeners: 1,
            maxQueuedListenerTasks: 1,
            metrics: $metrics,
        );

        $dispatcher->listen('s3:*', static function (): void {
            \Amp\delay(0.05);
        });
        $dispatcher->listen('s3:*', static function (): void {});

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

    public function test_shutdown_waits_for_in_flight_internal_listeners(): void
    {
        $dispatcher = new NotificationDispatcher($this->metadata);
        $completed = false;
        $dispatcher->listen('s3:ObjectCreated:*', static function () use (&$completed): void {
            \Amp\delay(0.02);
            $completed = true;
        });

        $dispatcher->dispatchEvent(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'drain.txt',
            ownerId: 'owner',
        ), enqueueWebhooks: false);
        $dispatcher->shutdown(1);

        self::assertTrue($completed);
    }

    public function test_shutdown_drains_listeners_spawned_by_in_flight_listeners(): void
    {
        $dispatcher = new NotificationDispatcher($this->metadata);
        $nestedCompleted = false;
        $dispatcher->listen('s3:ObjectCreated:Put', static function () use ($dispatcher): void {
            \Amp\delay(0.01);
            $dispatcher->dispatchEvent(new S3Event(
                name: 's3:ObjectCreated:Copy',
                bucket: 'bucket',
                key: 'nested.txt',
                ownerId: 'owner',
            ), enqueueWebhooks: false);
        });
        $dispatcher->listen('s3:ObjectCreated:Copy', static function () use (&$nestedCompleted): void {
            \Amp\delay(0.03);
            $nestedCompleted = true;
        });
        $dispatcher->dispatchEvent(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'first.txt',
            ownerId: 'owner',
        ), enqueueWebhooks: false);

        $dispatcher->shutdown(1);

        self::assertTrue($nestedCompleted);
    }

    public function test_shutdown_timeout_is_finite_and_can_be_retried_after_listener_finishes(): void
    {
        $logger = new NotificationArrayLogger();
        $dispatcher = new NotificationDispatcher($this->metadata, $logger);
        $state = (object) ['completed' => false];
        $dispatcher->listen('s3:ObjectCreated:*', static function () use ($state): void {
            \Amp\delay(0.03);
            $state->completed = true;
        });

        $dispatcher->dispatchEvent(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'slow-drain.txt',
            ownerId: 'owner',
        ), enqueueWebhooks: false);
        try {
            $dispatcher->shutdown(0.005);
            self::fail('A timed-out listener drain must report an incomplete shutdown.');
        } catch (\RuntimeException $error) {
            self::assertSame('Notification listener shutdown timed out.', $error->getMessage());
        }

        self::assertFalse($state->completed);
        self::assertNotNull($logger->findContext('listener_shutdown_timeout', [
            'component' => 'notification',
        ]));

        \Amp\delay(0.04);
        $dispatcher->shutdown(1);
        self::assertTrue($state->completed);
    }

    public function test_throwing_timeout_logger_cannot_hide_incomplete_listener_shutdown(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('private-logger-failure');
            }
        };
        $dispatcher = new NotificationDispatcher($this->metadata, $logger);
        $completed = false;
        $dispatcher->listen('s3:ObjectCreated:*', static function () use (&$completed): void {
            \Amp\delay(0.03);
            $completed = true;
        });
        $dispatcher->dispatchEvent(new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'throwing-logger-drain.txt',
            ownerId: 'owner',
        ), enqueueWebhooks: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Notification listener shutdown timed out.');

        try {
            $dispatcher->shutdown(0.005);
        } finally {
            self::assertFalse($completed);
        }
    }

    public function test_webhook_enqueue_rolls_back_with_caller_transaction(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);
        $dispatcher = new NotificationDispatcher($this->metadata);
        $event = $dispatcher->createEvent('s3:ObjectCreated:Put', 'bucket', 'rolled-back.txt', bucketGeneration: $this->bucketGeneration());

        try {
            $this->metadata->transaction(function () use ($dispatcher, $event): void {
                $dispatcher->enqueueWebhooks($event);
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('roll back', $e->getMessage());
        }

        self::assertSame([], $this->metadata->dequeueNotifications(10));
    }

    public function test_arn_destination_is_retained_but_not_enqueued_as_a_webhook(): void
    {
        $arn = 'arn:aws:sqs:us-east-1:123456789012:object-events';
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'queue-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Queue',
            'destinationArn' => $arn,
            'filterRules' => null,
        ]]);

        $logger = new NotificationArrayLogger();
        $metrics = new MetricsCollector();
        $dispatcher = new NotificationDispatcher($this->metadata, $logger, metrics: $metrics);
        $dispatcher->enqueueWebhooks(
            $dispatcher->createEvent('s3:ObjectCreated:Put', 'bucket', 'arn-only.txt', bucketGeneration: $this->bucketGeneration()),
        );

        self::assertSame($arn, $this->metadata->getBucketNotification('bucket')[0]['destinationArn']);
        self::assertSame([], $this->metadata->dequeueNotifications(10));
        self::assertNotNull($logger->findContext('destination_skipped', [
            'component' => 'notification',
            'bucket' => 'bucket',
            'key' => 'arn-only.txt',
            'event_name' => 's3:ObjectCreated:Put',
            'destination_type' => 'Queue',
        ]));
        self::assertStringContainsString(
            's3_server_notification_events_total{stage="webhook_destination",status="skipped"} 1',
            $metrics->renderPrometheus(),
        );
    }

    public function test_committed_webhook_outbox_survives_bucket_deletion(): void
    {
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'webhook-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);
        $dispatcher = new NotificationDispatcher($this->metadata);
        $dispatcher->enqueueWebhooks(
            $dispatcher->createEvent('s3:ObjectCreated:Put', 'bucket', 'committed.txt', bucketGeneration: $this->bucketGeneration()),
        );

        $this->metadata->deleteBucket('owner', 'bucket');

        $queued = $this->metadata->dequeueNotifications(10);
        self::assertCount(1, $queued);
        self::assertSame('s3:ObjectCreated:Put', $queued[0]['event_name']);
        self::assertSame('bucket', $queued[0]['bucket']);
    }

    private function runEventLoopTick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    private function bucketGeneration(): string
    {
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);

        return $bucket->generation;
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
     * @param  array<string, mixed>  $context
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
     * @param  array<string, mixed>  $expected
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
