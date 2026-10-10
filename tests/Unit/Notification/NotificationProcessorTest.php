<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Notification\NotificationProcessor;
use OpsFour\S3Server\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class NotificationProcessorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-notification-processor-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_private_destination_is_dead_lettered_with_structured_sanitized_log(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'http://user:secret@127.0.0.1/private',
            '{}',
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $logger = new ProcessorArrayLogger();

        $this->process($item, $logger);

        $context = $logger->findContext('delivery_blocked');
        $this->assertNotNull($context);
        $this->assertSame('notification', $context['component']);
        $this->assertSame((int) $item['id'], $context['notification_id']);
        $this->assertSame('bucket', $context['bucket']);
        $this->assertSame('key.txt', $context['key']);
        $this->assertSame('s3:ObjectCreated:Put', $context['event_name']);
        $this->assertSame('http://127.0.0.1', $context['destination']);
        $this->assertSame('unsafe_destination', $context['reason']);
        $this->assertSame('dead_letter', $context['status']);
        $this->assertSame('Notification destination must not contain user information.', $context['error']);
    }

    public function test_public_ipv6_literal_is_validated_as_an_address_without_dns_lookup(): void
    {
        $url = 'https://[2606:4700:4700::1111]/webhook';
        $this->metadata->enqueueNotification('bucket', 'key.txt', 's3:ObjectCreated:Put', $url, '{}');
        $item = $this->metadata->dequeueNotifications(1)[0];
        $delegate = new SequenceHttpClient([[200, []]]);
        $processor = new NotificationProcessor($this->metadata, httpClient: new HttpClient($delegate, []));

        $this->invokeProcess($processor, $item);

        self::assertSame([['POST', $url]], $delegate->requests);
        self::assertSame(['sent' => 1], $this->metadata->getNotificationQueueStats());
    }

    public function test_stale_worker_does_not_report_delivery_for_a_reclaimed_lease(): void
    {
        $this->metadata->enqueueNotification('bucket', 'key.txt', 's3:ObjectCreated:Put', 'https://93.184.216.34/webhook', '{}');
        $first = $this->metadata->dequeueNotifications(1)[0];
        $this->metadata->updateNotificationStatus($first['id'], 'pending', nextAttemptAt: 0, incrementAttempts: false);
        $second = $this->metadata->dequeueNotifications(1)[0];
        self::assertSame(($first['lease_generation'] ?? 0) + 1, $second['lease_generation'] ?? null);

        $logger = new ProcessorArrayLogger();
        $delegate = new SequenceHttpClient([[204, []], [204, []]]);
        $processor = new NotificationProcessor($this->metadata, $logger, httpClient: new HttpClient($delegate, []));

        $this->invokeProcess($processor, $first);
        self::assertSame(['processing' => 1], $this->metadata->getNotificationQueueStats());
        self::assertNull($logger->findContext('delivery_sent'));

        $this->invokeProcess($processor, $second);
        self::assertSame(['sent' => 1], $this->metadata->getNotificationQueueStats());
        self::assertNotNull($logger->findContext('delivery_sent'));
        self::assertCount(2, $delegate->requests);
    }

    public function test_terminal_notifications_are_retained_from_completion_not_enqueue_time(): void
    {
        $connection = new \PDO('sqlite:' . $this->path);
        foreach (['sent', 'dead_letter'] as $status) {
            $id = $this->metadata->enqueueNotification(
                'bucket',
                $status . '.txt',
                's3:ObjectCreated:Put',
                'https://93.184.216.34/webhook',
                '{}',
            );
            $connection->prepare('UPDATE s3_notification_queue SET created_at = ? WHERE id = ?')
                ->execute(['2000-01-01T00:00:00Z', $id]);
            $leased = $this->metadata->dequeueNotifications(1)[0];
            self::assertSame($id, $leased['id']);
            self::assertTrue($this->metadata->updateNotificationStatus(
                $id,
                $status,
                expectedLeaseExpiresAt: $leased['lease_expires_at'],
            ));
        }

        $this->metadata->cleanupOldNotifications(86400);
        self::assertSame(['dead_letter' => 1, 'sent' => 1], $this->metadata->getNotificationQueueStats());

        $connection->exec("UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE status IN ('sent', 'dead_letter')");
        $this->metadata->cleanupOldNotifications(86400);
        self::assertSame([], $this->metadata->getNotificationQueueStats());
    }

    public function test_cancelled_webhook_remains_pending_without_exhausting_its_only_attempt(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
            maxAttempts: 1,
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            httpClient: new HttpClient(new CancelledNotificationHttpClient(), []),
        );

        $this->invokeProcess($processor, $item);

        self::assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());
        self::assertNull($logger->findContext('delivery_dead_letter'));
        self::assertNull($logger->findContext('webhook_request_failed'));
        $statement = (new \PDO('sqlite:' . $this->path))
            ->query('SELECT attempts, next_attempt_at FROM s3_notification_queue');
        self::assertNotFalse($statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['attempts']);
        self::assertGreaterThan(microtime(true), (float) $row['next_attempt_at']);
    }

    public function test_stopping_worker_cancels_in_flight_webhook_without_dead_letter(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
            maxAttempts: 1,
        );
        $delegate = new BlockingNotificationHttpClient();
        $processor = new NotificationProcessor(
            $this->metadata,
            pollInterval: 0.01,
            httpClient: new HttpClient($delegate, []),
        );
        $processor->start();
        try {
            $delegate->started->getFuture()->await(new \Amp\TimeoutCancellation(2));
        } finally {
            $processor->stop();
        }

        self::assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());
        $statement = (new \PDO('sqlite:' . $this->path))
            ->query('SELECT attempts FROM s3_notification_queue');
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function test_start_and_second_stop_are_rejected_until_in_flight_delivery_has_stopped(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
            maxAttempts: 1,
        );
        $delegate = new DelayedCancellationNotificationHttpClient();
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            pollInterval: 0.01,
            httpClient: new HttpClient($delegate, []),
        );
        $processor->start();
        $stop = null;

        try {
            $delegate->started->getFuture()->await(new \Amp\TimeoutCancellation(2));
            $stop = \Amp\async(static function () use ($processor): void {
                $processor->stop(0.001);
            });
            $delegate->cancellationObserved->getFuture()->await(new \Amp\TimeoutCancellation(2));
            \Amp\delay(0.02);
            self::assertNotNull($logger->findContext('processor_stop_timeout'));

            try {
                $processor->start();
                self::fail('A new processor loop started while the old delivery was stopping.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('previous loop has stopped', $error->getMessage());
            }
            try {
                $processor->stop();
                self::fail('A second stop ran over the first stop operation.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('already in progress', $error->getMessage());
            }
            self::assertFalse($stop->isComplete());

            $delegate->release->complete();
            $stop->await(new \Amp\TimeoutCancellation(2));
            self::assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());

            $processor->start();
            $processor->stop();
        } finally {
            if (! $delegate->release->getFuture()->isComplete()) {
                $delegate->release->complete();
            }
            $stop?->await(new \Amp\TimeoutCancellation(2));
        }
    }

    public function test_throwing_timeout_logger_cannot_interrupt_processor_drain_or_restart(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
            maxAttempts: 1,
        );
        $delegate = new DelayedCancellationNotificationHttpClient();
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('private-logger-failure');
            }
        };
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            pollInterval: 0.01,
            httpClient: new HttpClient($delegate, []),
        );
        $processor->start();
        $delegate->started->getFuture()->await(new \Amp\TimeoutCancellation(2));
        $stop = \Amp\async(static fn() => $processor->stop(0.001));
        $delegate->cancellationObserved->getFuture()->await(new \Amp\TimeoutCancellation(2));
        \Amp\delay(0.02);

        self::assertFalse($stop->isComplete());
        $delegate->release->complete();
        $stop->await(new \Amp\TimeoutCancellation(2));
        self::assertFalse($processor->isRunning());

        $processor->start();
        self::assertTrue($processor->isRunning());
        $processor->stop();
        self::assertFalse($processor->isRunning());
    }

    public function test_ipv6_transition_and_documentation_literals_cannot_bypass_webhook_ip_policy(): void
    {
        $urls = [
            'https://[64:ff9b::7f00:1]/webhook',
            'https://[64:ff9b:1::7f00:1]/webhook',
            'https://[2002:7f00:1::]/webhook',
            'https://[2001::1]/webhook',
            'https://[2001:db8::1]/webhook',
        ];
        $delegate = new SequenceHttpClient([]);
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor($this->metadata, $logger, httpClient: new HttpClient($delegate, []));

        foreach ($urls as $url) {
            $this->metadata->enqueueNotification('bucket', 'key.txt', 's3:ObjectCreated:Put', $url, '{}');
            $item = $this->metadata->dequeueNotifications(1)[0];
            $this->invokeProcess($processor, $item);
            self::assertNotNull($logger->findContext('delivery_blocked', ['destination' => str_replace('/webhook', '', $url)]));
        }

        self::assertSame([], $delegate->requests);
        self::assertSame(['dead_letter' => count($urls)], $this->metadata->getNotificationQueueStats());
    }

    public function test_special_use_ipv4_literals_cannot_reach_webhook_transport(): void
    {
        $addresses = ['100.64.0.1', '100.127.255.254', '198.18.0.1', '198.19.255.254', '192.0.2.1', '198.51.100.1', '203.0.113.1'];
        $delegate = new SequenceHttpClient([]);
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor($this->metadata, $logger, httpClient: new HttpClient($delegate, []));

        foreach ($addresses as $address) {
            $destination = "https://{$address}/webhook";
            $this->metadata->enqueueNotification('bucket', 'key.txt', 's3:ObjectCreated:Put', $destination, '{}');
            $item = $this->metadata->dequeueNotifications(1)[0];
            $this->invokeProcess($processor, $item);
            self::assertNotNull($logger->findContext('delivery_blocked', ['destination' => "https://{$address}"]));
        }

        self::assertSame([], $delegate->requests);
        self::assertSame(['dead_letter' => count($addresses)], $this->metadata->getNotificationQueueStats());
    }

    public function test_failed_delivery_logs_retry_then_dead_letter_and_circuit_breaker_state(): void
    {
        $logger = new ProcessorArrayLogger();
        $httpClient = new HttpClient(new FixedStatusHttpClient(500), []);
        $metrics = new MetricsCollector();

        for ($i = 1; $i <= 6; $i++) {
            $this->metadata->enqueueNotification(
                'bucket',
                "key-{$i}.txt",
                's3:ObjectCreated:Put',
                'http://93.184.216.34/webhook',
                '{}',
                maxAttempts: $i === 1 ? 1 : 10,
            );
        }

        $items = $this->metadata->dequeueNotifications(6);
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            httpClient: $httpClient,
            metrics: $metrics,
            requireHttps: false,
        );

        foreach ($items as $item) {
            $this->invokeProcess($processor, $item);
        }

        $retry = $logger->findContext('delivery_retry_scheduled');
        $this->assertNotNull($retry);
        $this->assertSame('pending', $retry['status']);
        $this->assertSame(1, $retry['attempt']);
        $this->assertSame(10, $retry['max_attempts']);
        $this->assertSame(2, $retry['backoff_seconds']);
        $this->assertArrayHasKey('next_attempt_at', $retry);

        $deadLetter = $logger->findContext('delivery_dead_letter', ['reason' => 'max_attempts_exceeded']);
        $this->assertNotNull($deadLetter);
        $this->assertSame('dead_letter', $deadLetter['status']);
        $this->assertSame(1, $deadLetter['attempt']);
        $this->assertSame(1, $deadLetter['max_attempts']);

        $opened = $logger->findContext('circuit_breaker_opened');
        $this->assertNotNull($opened);
        $this->assertSame('http://93.184.216.34', $opened['destination']);
        $this->assertSame(5, $opened['failures']);
        $this->assertSame(60.0, $opened['cooldown_seconds']);

        $deferred = $logger->findContext('delivery_deferred_circuit_open');
        $this->assertNotNull($deferred);
        $this->assertSame('pending', $deferred['status'] ?? 'pending');
        $this->assertSame(0, $deferred['attempts']);
        $this->assertSame(10, $deferred['max_attempts']);

        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_notification_events_total{stage="circuit_breaker",status="opened"} 1', $rendered);
        $this->assertStringContainsString('s3_server_notification_deliveries_total{status="retry"} 4', $rendered);
        $this->assertStringContainsString('s3_server_notification_deliveries_total{status="dead_letter"} 1', $rendered);
        $this->assertStringContainsString('s3_server_notification_deliveries_total{status="circuit_open"} 1', $rendered);
    }

    public function test_redirect_to_private_address_is_blocked_before_second_request(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $delegate = new SequenceHttpClient([
            [307, ['location' => 'https://127.0.0.1/internal']],
        ]);
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            httpClient: new HttpClient($delegate, []),
        );

        $this->invokeProcess($processor, $item);

        $this->assertCount(1, $delegate->requests);
        $this->assertSame(['dead_letter' => 1], $this->metadata->getNotificationQueueStats());
        $this->assertNotNull($logger->findContext('delivery_blocked', ['reason' => 'unsafe_destination']));
    }

    public function test_see_other_redirect_does_not_acknowledge_a_payloadless_get(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook',
            '{}',
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $delegate = new SequenceHttpClient([
            [303, ['location' => '/accepted']],
            [204, []],
        ]);
        $processor = new NotificationProcessor(
            $this->metadata,
            httpClient: new HttpClient($delegate, []),
        );

        $this->invokeProcess($processor, $item);

        $this->assertSame([['POST', 'https://93.184.216.34/webhook']], $delegate->requests);
        $this->assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());
    }

    public function test_temporary_redirect_revalidates_and_resends_post_payload(): void
    {
        $this->metadata->enqueueNotification('bucket', 'key.txt', 's3:ObjectCreated:Put', 'https://93.184.216.34/webhook', '{"event":"created"}');
        $item = $this->metadata->dequeueNotifications(1)[0];
        $delegate = new SequenceHttpClient([
            [307, ['location' => '/accepted']],
            [204, []],
        ]);
        $processor = new NotificationProcessor($this->metadata, httpClient: new HttpClient($delegate, []));

        $this->invokeProcess($processor, $item);

        $this->assertSame([
            ['POST', 'https://93.184.216.34/webhook'],
            ['POST', 'https://93.184.216.34/accepted'],
        ], $delegate->requests);
        $this->assertSame(['sent' => 1], $this->metadata->getNotificationQueueStats());
    }

    public function test_plain_http_destination_is_rejected_by_default(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'http://93.184.216.34/webhook',
            '{}',
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $delegate = new SequenceHttpClient([[204, []]]);
        $processor = new NotificationProcessor(
            $this->metadata,
            httpClient: new HttpClient($delegate, []),
        );

        $this->invokeProcess($processor, $item);

        $this->assertSame([], $delegate->requests);
        $this->assertSame(['dead_letter' => 1], $this->metadata->getNotificationQueueStats());
    }

    public function test_webhook_path_and_query_secrets_are_omitted_from_delivery_logs(): void
    {
        $this->metadata->enqueueNotification(
            'bucket',
            'key.txt',
            's3:ObjectCreated:Put',
            'https://93.184.216.34/webhook/private-path-token?token=private-test-token',
            '{}',
        );
        $item = $this->metadata->dequeueNotifications(1)[0];
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor(
            $this->metadata,
            $logger,
            httpClient: new HttpClient(new EchoingFailureHttpClient(), []),
        );

        $this->invokeProcess($processor, $item);

        $requestFailure = $logger->findContext('webhook_request_failed');
        self::assertNotNull($requestFailure);
        self::assertSame('https://93.184.216.34', $requestFailure['destination']);
        self::assertSame(\RuntimeException::class, $requestFailure['exception']);
        self::assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());
        self::assertStringNotContainsString('private-test-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private-path-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_unexpected_processing_exception_is_not_persisted_or_logged_verbatim(): void
    {
        $secret = 'database-password-private-test-token';
        $persistedError = null;
        $updateCalls = 0;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::exactly(2))
            ->method('updateNotificationStatus')
            ->willReturnCallback(static function (
                int $id,
                string $status,
                ?string $error = null,
            ) use (&$updateCalls, &$persistedError, $secret): bool {
                $updateCalls++;
                if ($updateCalls === 1) {
                    throw new \RuntimeException('Database request failed: ' . $secret);
                }

                self::assertSame(7, $id);
                self::assertSame('pending', $status);
                $persistedError = $error;

                return true;
            });
        $logger = new ProcessorArrayLogger();
        $processor = new NotificationProcessor(
            $metadata,
            $logger,
            httpClient: new HttpClient(new FixedStatusHttpClient(204), []),
        );

        $this->invokeProcess($processor, [
            'id' => 7,
            'bucket' => 'bucket',
            'key_name' => 'key.txt',
            'event_name' => 's3:ObjectCreated:Put',
            'destination_url' => 'https://93.184.216.34/webhook',
            'payload_json' => '{}',
            'attempts' => 0,
            'max_attempts' => 2,
            'lease_expires_at' => microtime(true) + 60,
        ]);

        self::assertSame('Notification processing failed.', $persistedError);
        self::assertNotNull($logger->findContext('delivery_retry_scheduled', ['reason' => 'processing_exception']));
        self::assertStringNotContainsString($secret, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_persisted_filtered_event_is_retried_and_delivered_after_processor_restart(): void
    {
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'created-logs',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://93.184.216.34/webhook',
            'filterRules' => [['name' => 'prefix', 'value' => 'logs/']],
        ]]);

        $dispatcher = new NotificationDispatcher($this->metadata);
        $generation = $this->metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'other/ignored.txt', bucketGeneration: $generation);
        $dispatcher->dispatch('s3:ObjectRemoved:Delete', 'bucket', 'logs/ignored.txt', bucketGeneration: $generation);
        $dispatcher->dispatch('s3:ObjectCreated:Put', 'bucket', 'logs/space + sign.txt', 12, '"etag"', 'writer', $generation);
        self::assertSame(['pending' => 1], $this->metadata->getNotificationQueueStats());

        // Open a fresh connection to exercise the persisted queue rather than
        // handing the dispatcher and processor the same in-memory state.
        $afterRestart = new SqliteMetadataStore($this->path);
        $afterRestart->initialize();
        $failedClient = new RecordingNotificationHttpClient(500);
        $failedProcessor = new NotificationProcessor(
            $afterRestart,
            pollInterval: 0.02,
            httpClient: new HttpClient($failedClient, []),
        );
        try {
            $failedProcessor->start();
            $this->waitForNotificationState($afterRestart, 'pending', $failedClient);
        } finally {
            $failedProcessor->stop();
        }

        self::assertCount(1, $failedClient->requests);
        self::assertSame('POST', $failedClient->requests[0]['method']);
        self::assertSame('https://93.184.216.34/webhook', $failedClient->requests[0]['url']);
        self::assertSame(['pending' => 1], $afterRestart->getNotificationQueueStats());

        $secondRestart = new SqliteMetadataStore($this->path);
        $secondRestart->initialize();
        $successClient = new RecordingNotificationHttpClient(204);
        $successProcessor = new NotificationProcessor(
            $secondRestart,
            pollInterval: 0.02,
            httpClient: new HttpClient($successClient, []),
        );
        try {
            $successProcessor->start();
            $this->waitForNotificationState($secondRestart, 'sent', $successClient, 5.0);
        } finally {
            $successProcessor->stop();
        }

        self::assertSame(['sent' => 1], $secondRestart->getNotificationQueueStats());
        self::assertCount(1, $successClient->requests);
        self::assertSame($failedClient->requests[0]['body'], $successClient->requests[0]['body']);
        $payload = json_decode($successClient->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ObjectCreated:Put', $payload['Records'][0]['eventName']);
        self::assertSame('created-logs', $payload['Records'][0]['s3']['configurationId']);
        self::assertSame('logs%2Fspace+%2B+sign.txt', $payload['Records'][0]['s3']['object']['key']);
        self::assertSame('bucket-owner', $payload['Records'][0]['s3']['bucket']['ownerIdentity']['principalId']);
    }

    private function waitForNotificationState(
        SqliteMetadataStore $metadata,
        string $status,
        RecordingNotificationHttpClient $client,
        float $timeoutSeconds = 2.0,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (count($client->requests) === 1 && $metadata->getNotificationQueueStats() === [$status => 1]) {
                return;
            }
            \Amp\delay(0.02);
        } while (microtime(true) < $deadline);

        self::fail('Notification did not reach ' . $status . ' after one HTTP request.');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function process(array $item, ProcessorArrayLogger $logger): void
    {
        $this->invokeProcess(new NotificationProcessor($this->metadata, $logger), $item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function invokeProcess(NotificationProcessor $processor, array $item): void
    {
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('process');
        $method->invoke($processor, $item);
    }
}

final class FixedStatusHttpClient implements DelegateHttpClient
{
    public function __construct(
        private readonly int $status,
    ) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        return new Response('1.1', $this->status, null, [], '', $request);
    }
}

final class SequenceHttpClient implements DelegateHttpClient
{
    /** @var list<array{0: int, 1: array<non-empty-string, string>}> */
    private array $responses;

    /** @var list<array{0: string, 1: string}> */
    public array $requests = [];

    /** @param list<array{0: int, 1: array<non-empty-string, string>}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = [$request->getMethod(), (string) $request->getUri()];
        [$status, $headers] = array_shift($this->responses) ?? [500, []];

        return new Response('1.1', $status, null, $headers, '', $request);
    }
}

final class EchoingFailureHttpClient implements DelegateHttpClient
{
    public function request(Request $request, Cancellation $cancellation): Response
    {
        throw new \RuntimeException('Request failed for ' . $request->getUri());
    }
}

final class CancelledNotificationHttpClient implements DelegateHttpClient
{
    public function request(Request $request, Cancellation $cancellation): Response
    {
        throw new \Amp\CancelledException();
    }
}

final class BlockingNotificationHttpClient implements DelegateHttpClient
{
    /** @var \Amp\DeferredFuture<void> */
    public \Amp\DeferredFuture $started;

    public function __construct()
    {
        $this->started = new \Amp\DeferredFuture();
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->started->complete();
        \Amp\delay(60, cancellation: $cancellation);

        return new Response('1.1', 204, null, [], '', $request);
    }
}

final class DelayedCancellationNotificationHttpClient implements DelegateHttpClient
{
    /** @var \Amp\DeferredFuture<void> */
    public \Amp\DeferredFuture $started;

    /** @var \Amp\DeferredFuture<void> */
    public \Amp\DeferredFuture $cancellationObserved;

    /** @var \Amp\DeferredFuture<void> */
    public \Amp\DeferredFuture $release;

    public function __construct()
    {
        $this->started = new \Amp\DeferredFuture();
        $this->cancellationObserved = new \Amp\DeferredFuture();
        $this->release = new \Amp\DeferredFuture();
    }

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->started->complete();
        try {
            \Amp\delay(60, cancellation: $cancellation);
        } catch (\Amp\CancelledException $error) {
            $this->cancellationObserved->complete();
            $this->release->getFuture()->await();

            throw $error;
        }

        return new Response('1.1', 204, null, [], '', $request);
    }
}

final class RecordingNotificationHttpClient implements DelegateHttpClient
{
    /** @var list<array{method: string, url: string, body: string}> */
    public array $requests = [];

    public function __construct(private readonly int $status) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'body' => \Amp\ByteStream\buffer($request->getBody()->getContent()),
        ];

        return new Response('1.1', $this->status, null, [], '', $request);
    }
}

final class ProcessorArrayLogger extends AbstractLogger
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
    public function findContext(string $event, array $expected = []): ?array
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
