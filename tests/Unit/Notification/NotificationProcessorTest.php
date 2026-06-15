<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
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
        $this->assertSame('http://127.0.0.1/private', $context['destination']);
        $this->assertSame('private_or_reserved_destination', $context['reason']);
        $this->assertSame('dead_letter', $context['status']);
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
        $processor = new NotificationProcessor($this->metadata, $logger, httpClient: $httpClient, metrics: $metrics);

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
        $this->assertSame('http://93.184.216.34/webhook', $opened['destination']);
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

    /**
     * @param array<string, mixed> $item
     */
    private function process(array $item, ProcessorArrayLogger $logger): void
    {
        $this->invokeProcess(new NotificationProcessor($this->metadata, $logger), $item);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function invokeProcess(NotificationProcessor $processor, array $item): void
    {
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('process');
        $method->setAccessible(true);
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

final class ProcessorArrayLogger extends AbstractLogger
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
