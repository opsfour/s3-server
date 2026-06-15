<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Publishes normalized S3 events to a Redis Stream.
 *
 * The client can be a native Redis client, Predis-like client, or a callable.
 * Object clients must expose an xAdd() or xadd() method.
 */
final readonly class RedisStreamNotificationAdapter implements NotificationDestinationAdapter
{
    public function __construct(
        private mixed $client,
        private string $stream,
        private int $maxLen = 0,
        private bool $approximateTrimming = true,
    ) {
        if ($stream === '') {
            throw new \InvalidArgumentException('Redis stream name must not be empty.');
        }
        if ($maxLen < 0) {
            throw new \InvalidArgumentException('Redis stream maxLen must be >= 0.');
        }
    }

    public function name(): string
    {
        return 'redis-stream';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        $fields = S3EventDestinationPayload::toArray($event);
        $fields['payload'] = S3EventDestinationPayload::toJson($event);

        try {
            $messageId = $this->publish($fields);
        } catch (\Throwable $e) {
            return NotificationDeliveryResult::retryableFailure($e->getMessage(), [
                'stream' => $this->stream,
                'exception' => $e::class,
            ]);
        }

        return NotificationDeliveryResult::sent([
            'stream' => $this->stream,
            'message_id' => $messageId,
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function publish(array $fields): mixed
    {
        if (is_callable($this->client)) {
            return ($this->client)($this->stream, $fields, $this->maxLen, $this->approximateTrimming);
        }

        foreach (['xAdd', 'xadd'] as $method) {
            if (is_object($this->client) && method_exists($this->client, $method)) {
                if ($this->maxLen > 0) {
                    return $this->client->{$method}($this->stream, '*', $fields, $this->maxLen, $this->approximateTrimming);
                }

                return $this->client->{$method}($this->stream, '*', $fields);
            }
        }

        throw new \InvalidArgumentException('Redis stream adapter requires a callable client or an xAdd()/xadd() method.');
    }
}
