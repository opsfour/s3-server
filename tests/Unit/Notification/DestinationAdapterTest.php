<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Notification;

use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Notification\Destination\AmqpNotificationAdapter;
use OpsFour\S3Server\Notification\Destination\KafkaNotificationAdapter;
use OpsFour\S3Server\Notification\Destination\NatsNotificationAdapter;
use OpsFour\S3Server\Notification\Destination\NotificationDeliveryStatus;
use OpsFour\S3Server\Notification\Destination\RedisStreamNotificationAdapter;
use PHPUnit\Framework\TestCase;

final class DestinationAdapterTest extends TestCase
{
    public function test_redis_stream_adapter_publishes_normalized_payload(): void
    {
        $client = new RecordingRedisStreamClient();
        $result = (new RedisStreamNotificationAdapter($client, 's3-events'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-events', $client->calls[0]['stream']);
        $this->assertSame('*', $client->calls[0]['id']);
        $this->assertSame('s3:ObjectCreated:Put', $client->calls[0]['fields']['name']);
        $this->assertSame('bucket', $client->calls[0]['fields']['bucket']);
        $this->assertSame(['request_id' => 'req-1'], json_decode($client->calls[0]['fields']['attributes'], true, flags: JSON_THROW_ON_ERROR));
        foreach ($client->calls[0]['fields'] as $field) {
            $this->assertTrue(is_scalar($field));
        }
        $this->assertJson($client->calls[0]['fields']['payload']);
        $this->assertSame('redis-id-1', $result->context['message_id']);
    }

    public function test_redis_stream_adapter_supports_amphp_redis_execute_signature(): void
    {
        $client = new RecordingAmpRedisClient();
        $result = (new RedisStreamNotificationAdapter($client, 's3-events', 1000, false))->deliver($this->event());

        self::assertSame(NotificationDeliveryStatus::Sent, $result->status);
        self::assertSame('XADD', $client->command);
        self::assertSame(['s3-events', 'MAXLEN', '=', 1000, '*'], array_slice($client->arguments, 0, 5));
        self::assertContains('attributes', $client->arguments);
        self::assertContains('{"request_id":"req-1"}', $client->arguments);
        foreach ($client->arguments as $argument) {
            self::assertTrue(is_string($argument) || is_int($argument) || is_float($argument));
        }
        self::assertSame('redis-id-amp', $result->context['message_id']);
    }

    public function test_redis_stream_adapter_supports_dynamic_predis_style_clients(): void
    {
        $client = new RecordingDynamicRedisClient();
        $result = (new RedisStreamNotificationAdapter($client, 's3-events'))->deliver($this->event());

        self::assertSame(NotificationDeliveryStatus::Sent, $result->status);
        self::assertSame('xAdd', $client->method);
        self::assertSame('s3-events', $client->arguments[0]);
        self::assertSame('redis-id-dynamic', $result->context['message_id']);
    }

    public function test_kafka_adapter_publishes_json_payload_with_object_key(): void
    {
        $producer = new RecordingKafkaProducer();
        $result = (new KafkaNotificationAdapter($producer, 's3-events', partition: 2))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-events', $producer->calls[0]['topic']);
        $this->assertSame(2, $producer->calls[0]['partition']);
        $this->assertSame('bucket/key.txt', $producer->calls[0]['key']);
        $this->assertJson($producer->calls[0]['payload']);
    }

    public function test_amqp_adapter_publishes_json_payload(): void
    {
        $publisher = new RecordingAmqpPublisher();
        $result = (new AmqpNotificationAdapter($publisher, 's3-exchange', 'bucket.created'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-exchange', $publisher->calls[0]['exchange']);
        $this->assertSame('bucket.created', $publisher->calls[0]['routingKey']);
        $this->assertJson($publisher->calls[0]['payload']);
    }

    public function test_amqp_adapter_allows_default_exchange_and_empty_routing_key(): void
    {
        $publisher = new RecordingAmqpPublisher();
        $result = (new AmqpNotificationAdapter($publisher, '', ''))->deliver($this->event());

        self::assertSame(NotificationDeliveryStatus::Sent, $result->status);
        self::assertSame('', $publisher->calls[0]['exchange']);
        self::assertSame('', $publisher->calls[0]['routingKey']);
    }

    public function test_nats_adapter_publishes_json_payload(): void
    {
        $publisher = new RecordingNatsPublisher();
        $result = (new NatsNotificationAdapter($publisher, 's3.object.created'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3.object.created', $publisher->calls[0]['subject']);
        $this->assertJson($publisher->calls[0]['payload']);
    }

    public function test_adapter_publish_failure_is_retryable(): void
    {
        $result = (new NatsNotificationAdapter(
            static fn(): never => throw new \RuntimeException('broker unavailable'),
            's3.events',
        ))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::RetryableFailure, $result->status);
        $this->assertSame('Broker delivery failed.', $result->reason);
        $this->assertSame(\RuntimeException::class, $result->context['exception']);
    }

    public function test_builtin_broker_failures_do_not_return_client_exception_secrets(): void
    {
        $failingClient = static fn(): never => throw new \RuntimeException('sasl.password=secret-token');
        $adapters = [
            new RedisStreamNotificationAdapter($failingClient, 's3-events'),
            new KafkaNotificationAdapter($failingClient, 's3-events'),
            new AmqpNotificationAdapter($failingClient, 's3-exchange'),
            new NatsNotificationAdapter($failingClient, 's3.events'),
        ];

        foreach ($adapters as $adapter) {
            $result = $adapter->deliver($this->event());
            $this->assertSame(NotificationDeliveryStatus::RetryableFailure, $result->status);
            $this->assertSame('Broker delivery failed.', $result->reason);
            $this->assertStringNotContainsString('secret-token', json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(\RuntimeException::class, $result->context['exception']);
        }
    }

    public function test_explicit_broker_failure_result_is_retryable_for_each_adapter(): void
    {
        $failedPublish = static fn(): false => false;
        $adapters = [
            new RedisStreamNotificationAdapter($failedPublish, 's3-events'),
            new KafkaNotificationAdapter($failedPublish, 's3-events'),
            new AmqpNotificationAdapter($failedPublish, 's3-exchange'),
            new NatsNotificationAdapter($failedPublish, 's3.events'),
        ];

        foreach ($adapters as $adapter) {
            $result = $adapter->deliver($this->event());
            $this->assertSame(NotificationDeliveryStatus::RetryableFailure, $result->status, $adapter->name());
            $this->assertSame('Broker delivery failed.', $result->reason);
        }
    }

    public function test_missing_broker_publish_acknowledgement_is_retryable_for_each_adapter(): void
    {
        $unconfirmedPublish = static fn(): null => null;
        $adapters = [
            new RedisStreamNotificationAdapter($unconfirmedPublish, 's3-events'),
            new KafkaNotificationAdapter($unconfirmedPublish, 's3-events'),
            new AmqpNotificationAdapter($unconfirmedPublish, 's3-exchange'),
            new NatsNotificationAdapter($unconfirmedPublish, 's3.events'),
        ];

        foreach ($adapters as $adapter) {
            $result = $adapter->deliver($this->event());
            $this->assertSame(NotificationDeliveryStatus::RetryableFailure, $result->status, $adapter->name());
            $this->assertSame('Broker delivery failed.', $result->reason);
        }
    }

    public function test_invalid_event_payload_is_dead_lettered_without_calling_brokers(): void
    {
        $calls = 0;
        $publisher = static function () use (&$calls): string {
            $calls++;

            return 'unexpected';
        };
        $event = $this->event(['invalid_utf8' => "\xB1\x31"]);
        $adapters = [
            new RedisStreamNotificationAdapter($publisher, 's3-events'),
            new KafkaNotificationAdapter($publisher, 's3-events'),
            new AmqpNotificationAdapter($publisher, 's3-exchange'),
            new NatsNotificationAdapter($publisher, 's3.events'),
        ];

        foreach ($adapters as $adapter) {
            $result = $adapter->deliver($event);
            self::assertSame(NotificationDeliveryStatus::DeadLetter, $result->status, $adapter->name());
            self::assertSame('Event payload could not be encoded.', $result->reason);
            self::assertSame(\JsonException::class, $result->context['exception']);
        }
        self::assertSame(0, $calls);
    }

    public function test_invalid_broker_clients_fail_during_configuration(): void
    {
        foreach ([
            static fn() => new RedisStreamNotificationAdapter(new \stdClass(), 's3-events'),
            static fn() => new KafkaNotificationAdapter(new \stdClass(), 's3-events'),
            static fn() => new AmqpNotificationAdapter(new \stdClass(), 's3-exchange'),
            static fn() => new NatsNotificationAdapter(new \stdClass(), 's3.events'),
        ] as $createAdapter) {
            try {
                $createAdapter();
                self::fail('Invalid broker clients must fail during adapter configuration.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed>|null $attributes */
    private function event(?array $attributes = null): S3Event
    {
        return new S3Event(
            name: 's3:ObjectCreated:Put',
            bucket: 'bucket',
            key: 'key.txt',
            size: 7,
            etag: '"etag"',
            ownerId: 'owner',
            region: 'us-east-1',
            attributes: $attributes ?? ['request_id' => 'req-1'],
        );
    }
}

final class RecordingRedisStreamClient
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param array<string, string|int|float> $fields */
    public function xAdd(string $stream, string $id, array $fields, int $maxLen = 0, bool $approximate = true): string
    {
        $this->calls[] = compact('stream', 'id', 'fields', 'maxLen', 'approximate');

        return 'redis-id-1';
    }
}

final class RecordingAmpRedisClient
{
    public string $command = '';

    /** @var list<string|int|float> */
    public array $arguments = [];

    public function execute(string $command, string|int|float ...$arguments): string
    {
        $this->command = $command;
        $this->arguments = array_values($arguments);

        return 'redis-id-amp';
    }
}

final class RecordingDynamicRedisClient
{
    public string $method = '';

    /** @var list<mixed> */
    public array $arguments = [];

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): string
    {
        $this->method = $method;
        $this->arguments = $arguments;

        return 'redis-id-dynamic';
    }
}

final class RecordingKafkaProducer
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function produce(string $topic, ?int $partition, int $flags, string $payload, string $key): string
    {
        $this->calls[] = compact('topic', 'partition', 'flags', 'payload', 'key');

        return 'offset-1';
    }
}

final class RecordingAmqpPublisher
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function publish(string $payload, string $routingKey, string $exchange): string
    {
        $this->calls[] = compact('payload', 'routingKey', 'exchange');

        return 'amqp-ok';
    }
}

final class RecordingNatsPublisher
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function publish(string $subject, string $payload): string
    {
        $this->calls[] = compact('subject', 'payload');

        return 'nats-ok';
    }
}
