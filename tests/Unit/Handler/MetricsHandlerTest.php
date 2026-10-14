<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartCompletionCapacityReader;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use PHPUnit\Framework\TestCase;

final class MetricsHandlerTest extends TestCase
{
    public function test_completion_capacity_metric_reads_persisted_counter_on_each_authorized_scrape(): void
    {
        $path = sys_get_temp_dir() . '/s3-metrics-completion-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $pdo = new \PDO('sqlite:' . $path);
            $handler = new MetricsHandler(new MetricsCollector(), $store, 'token');

            $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_multipart_completion_intents 0', $first);
            self::assertStringContainsString('s3_server_multipart_completion_capacity_remaining 1000000', $first);

            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 12 WHERE id = 1');
            $next = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_multipart_completion_intents 12', $next);
            self::assertStringContainsString('s3_server_multipart_completion_capacity_remaining 999988', $next);

            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 0.5 WHERE id = 1');
            try {
                $handler->handleRequest($this->request('Bearer token'));
                self::fail('An authorized metrics scrape accepted a fractional completion counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is unavailable', $error->getMessage());
            }
            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 12 WHERE id = 1');

            $pdo->exec('DELETE FROM s3_multipart_completion_capacity WHERE id = 1');
            self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
            $this->expectException(\RuntimeException::class);
            $handler->handleRequest($this->request('Bearer token'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_custom_metadata_store_without_capacity_reader_omits_gauge(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $observed = new ObservedMetadataStore($metadata, new MetricsCollector(), 'custom');
        $handler = new MetricsHandler(new MetricsCollector(), $observed);

        $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_capacity_remaining /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $rendered);
    }

    public function test_write_intent_capacity_metrics_read_both_persisted_rows_and_fail_closed(): void
    {
        $path = sys_get_temp_dir() . '/s3-metrics-write-intents-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $pdo = new \PDO('sqlite:' . $path);
            $cached = new CachedMetadataStoreDecorator($store);
            $handler = new MetricsHandler(new MetricsCollector(), new ObservedMetadataStore($cached, new MetricsCollector(), 'sqlite'), 'token');

            $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_object_write_intents 0', $first);
            self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 1000000', $first);

            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 19 WHERE kind = 'object'");
            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 23 WHERE kind = 'part'");
            $next = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_object_write_intents 19', $next);
            self::assertStringContainsString('s3_server_object_write_capacity_remaining 999981', $next);
            self::assertStringContainsString('s3_server_multipart_part_write_intents 23', $next);
            self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 999977', $next);

            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 0.5 WHERE kind = 'object'");
            try {
                $handler->handleRequest($this->request('Bearer token'));
                self::fail('An authorized metrics scrape accepted a fractional counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is unavailable', $error->getMessage());
            }
            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 19 WHERE kind = 'object'");

            $pdo->exec("DELETE FROM s3_write_intent_capacity WHERE kind = 'part'");
            self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
            $this->expectException(\RuntimeException::class);
            $handler->handleRequest($this->request('Bearer token'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_write_intent_reader_losing_its_sample_clears_both_gauges(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, WriteIntentCapacityReader::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $metadata->expects(self::exactly(2))->method('getWriteIntentCapacityCounts')
            ->willReturnOnConsecutiveCalls(['object' => 7, 'part' => 9], null);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_object_write_intents 7', $first);
        self::assertStringContainsString('s3_server_multipart_part_write_intents 9', $first);
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_capacity_remaining /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_capacity_remaining /m', $second);
    }

    public function test_optional_reader_losing_its_sample_clears_prior_capacity_gauges(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, MultipartCompletionCapacityReader::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $metadata->expects(self::exactly(2))->method('getMultipartCompletionIntentCount')->willReturnOnConsecutiveCalls(7, null);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_multipart_completion_intents 7', $first);
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_capacity_remaining /m', $second);
    }

    public function test_configured_bearer_token_is_required(): void
    {
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: 'secret-token');

        $unauthorized = $handler->handleRequest($this->request());
        self::assertSame(401, $unauthorized->getStatus());
        self::assertSame('no-store', $unauthorized->getHeader('cache-control'));
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        $authorized = $handler->handleRequest($this->request('Bearer secret-token'));
        self::assertSame(200, $authorized->getStatus());
        self::assertSame('no-store', $authorized->getHeader('cache-control'));
    }

    public function test_whitespace_bearer_token_is_rejected_instead_of_publishing_metrics(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new MetricsHandler(new MetricsCollector(), bearerToken: " \t ");
    }

    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $request = $this->request();
        $request->setHeader('authorization', ['Bearer secret-token', 'Bearer secret-token']);

        self::assertSame(401, (new MetricsHandler(
            new MetricsCollector(),
            bearerToken: 'secret-token',
        ))->handleRequest($request)->getStatus());
    }

    public function test_metadata_cancellation_is_propagated_unchanged(): void
    {
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getNotificationQueueStats')->willThrowException($cancellation);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        try {
            $handler->handleRequest($this->request());
            self::fail('Metrics scrape swallowed metadata cancellation.');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($cancellation, $caught);
        }
    }

    public function test_zero_string_bearer_token_does_not_open_metrics(): void
    {
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: '0');

        self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request('Bearer 0'))->getStatus());
    }

    private function request(?string $authorization = null): Request
    {
        return new Request(
            new MetricsHandlerTestClient(),
            'GET',
            Http::new('http://127.0.0.1/metrics'),
            $authorization !== null ? ['authorization' => $authorization] : [],
        );
    }
}

final class MetricsHandlerTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}
