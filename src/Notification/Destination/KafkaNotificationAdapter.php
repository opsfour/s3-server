<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Publishes normalized S3 events through a Kafka producer abstraction.
 *
 * Supported clients:
 * - callable(string $topic, string $payload, string $key, S3Event $event): mixed
 * - object with produce($topic, $partition, $flags, $payload, $key)
 * - object with send($topic, $payload, $key)
 * - object with publish($topic, $payload, $key)
 */
final readonly class KafkaNotificationAdapter implements NotificationDestinationAdapter
{
    public function __construct(
        private mixed $producer,
        private string $topic,
        private ?int $partition = null,
    ) {
        if ($topic === '') {
            throw new \InvalidArgumentException('Kafka topic must not be empty.');
        }
    }

    public function name(): string
    {
        return 'kafka';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        $payload = S3EventDestinationPayload::toJson($event);
        $key = "{$event->bucket}/{$event->key}";

        try {
            $result = $this->publish($payload, $key, $event);
        } catch (\Throwable $e) {
            return NotificationDeliveryResult::retryableFailure($e->getMessage(), [
                'topic' => $this->topic,
                'exception' => $e::class,
            ]);
        }

        return NotificationDeliveryResult::sent([
            'topic' => $this->topic,
            'partition' => $this->partition,
            'result' => $result,
        ]);
    }

    private function publish(string $payload, string $key, S3Event $event): mixed
    {
        if (is_callable($this->producer)) {
            return ($this->producer)($this->topic, $payload, $key, $event);
        }

        if (!is_object($this->producer)) {
            throw new \InvalidArgumentException('Kafka adapter requires a callable or producer object.');
        }

        if (method_exists($this->producer, 'produce')) {
            return $this->producer->produce($this->topic, $this->partition, 0, $payload, $key);
        }
        if (method_exists($this->producer, 'send')) {
            return $this->producer->send($this->topic, $payload, $key);
        }
        if (method_exists($this->producer, 'publish')) {
            return $this->producer->publish($this->topic, $payload, $key);
        }

        throw new \InvalidArgumentException('Kafka producer must expose produce(), send(), or publish().');
    }
}
