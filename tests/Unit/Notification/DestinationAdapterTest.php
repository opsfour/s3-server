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
        $client = new RecordingRedisStreamClient;
        $result = (new RedisStreamNotificationAdapter($client, 's3-events'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-events', $client->calls[0]['stream']);
        $this->assertSame('*', $client->calls[0]['id']);
        $this->assertSame('s3:ObjectCreated:Put', $client->calls[0]['fields']['name']);
        $this->assertSame('bucket', $client->calls[0]['fields']['bucket']);
        $this->assertJson($client->calls[0]['fields']['payload']);
        $this->assertSame('redis-id-1', $result->context['message_id']);
    }

    public function test_kafka_adapter_publishes_json_payload_with_object_key(): void
    {
        $producer = new RecordingKafkaProducer;
        $result = (new KafkaNotificationAdapter($producer, 's3-events', partition: 2))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-events', $producer->calls[0]['topic']);
        $this->assertSame(2, $producer->calls[0]['partition']);
        $this->assertSame('bucket/key.txt', $producer->calls[0]['key']);
        $this->assertJson($producer->calls[0]['payload']);
    }

    public function test_amqp_adapter_publishes_json_payload(): void
    {
        $publisher = new RecordingAmqpPublisher;
        $result = (new AmqpNotificationAdapter($publisher, 's3-exchange', 'bucket.created'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3-exchange', $publisher->calls[0]['exchange']);
        $this->assertSame('bucket.created', $publisher->calls[0]['routingKey']);
        $this->assertJson($publisher->calls[0]['payload']);
    }

    public function test_nats_adapter_publishes_json_payload(): void
    {
        $publisher = new RecordingNatsPublisher;
        $result = (new NatsNotificationAdapter($publisher, 's3.object.created'))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::Sent, $result->status);
        $this->assertSame('s3.object.created', $publisher->calls[0]['subject']);
        $this->assertJson($publisher->calls[0]['payload']);
    }

    public function test_adapter_publish_failure_is_retryable(): void
    {
        $result = (new NatsNotificationAdapter(
            static fn (): never => throw new \RuntimeException('broker unavailable'),
            's3.events',
        ))->deliver($this->event());

        $this->assertSame(NotificationDeliveryStatus::RetryableFailure, $result->status);
        $this->assertSame('broker unavailable', $result->reason);
        $this->assertSame(\RuntimeException::class, $result->context['exception']);
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
            region: 'us-east-1',
            attributes: ['request_id' => 'req-1'],
        );
    }
}

final class RecordingRedisStreamClient
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $fields
     */
    public function xAdd(string $stream, string $id, array $fields, int $maxLen = 0, bool $approximate = true): string
    {
        $this->calls[] = compact('stream', 'id', 'fields', 'maxLen', 'approximate');

        return 'redis-id-1';
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
