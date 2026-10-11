<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Publishes normalized S3 events to a Redis Stream.
 *
 * The client can be an amphp/redis v2 client, a native or Predis-like client,
 * or a callable. Object clients must expose execute() or xAdd()/xadd().
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
        if (! is_callable($client) && ! self::supportsObjectClient($client)) {
            throw new \InvalidArgumentException('Redis stream adapter requires a callable client or an execute()/xAdd()/xadd() method.');
        }
    }

    public function name(): string
    {
        return 'redis-stream';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        try {
            $fields = self::scalarFields(S3EventDestinationPayload::toArray($event));
            $fields['payload'] = S3EventDestinationPayload::toJson($event);
            $messageId = $this->publish($fields);
        } catch (\JsonException $e) {
            return NotificationDeliveryResult::deadLetter('Event payload could not be encoded.', [
                'stream' => $this->stream,
                'exception' => $e::class,
            ]);
        } catch (\Throwable $e) {
            return NotificationDeliveryResult::retryableFailure('Broker delivery failed.', [
                'stream' => $this->stream,
                'exception' => $e::class,
            ]);
        }
        if ($messageId === false || $messageId === null) {
            return NotificationDeliveryResult::retryableFailure('Broker delivery failed.', ['stream' => $this->stream]);
        }

        return NotificationDeliveryResult::sent([
            'stream' => $this->stream,
            'message_id' => $messageId,
        ]);
    }

    /**
     * @param array<string, string|int|float> $fields
     */
    private function publish(array $fields): mixed
    {
        if (is_callable($this->client)) {
            return ($this->client)($this->stream, $fields, $this->maxLen, $this->approximateTrimming);
        }

        foreach (['xAdd', 'xadd'] as $method) {
            if (is_object($this->client) && is_callable([$this->client, $method])) {
                if ($this->maxLen > 0) {
                    return $this->client->{$method}($this->stream, '*', $fields, $this->maxLen, $this->approximateTrimming);
                }

                return $this->client->{$method}($this->stream, '*', $fields);
            }
        }

        if (is_object($this->client) && is_callable([$this->client, 'execute'])) {
            $arguments = [$this->stream];
            if ($this->maxLen > 0) {
                array_push($arguments, 'MAXLEN', $this->approximateTrimming ? '~' : '=', $this->maxLen);
            }
            $arguments[] = '*';
            foreach ($fields as $name => $value) {
                array_push($arguments, $name, $value);
            }

            return $this->client->execute('XADD', ...$arguments);
        }

        throw new \InvalidArgumentException('Redis stream adapter requires a callable client or an execute()/xAdd()/xadd() method.');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, string|int|float>
     */
    private static function scalarFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $name => $value) {
            $result[$name] = match (true) {
                is_string($value), is_int($value), is_float($value) => $value,
                is_bool($value) => (int) $value,
                $value === null => '',
                default => json_encode($value, JSON_THROW_ON_ERROR),
            };
        }

        return $result;
    }

    private static function supportsObjectClient(mixed $client): bool
    {
        if (! is_object($client)) {
            return false;
        }

        foreach (['xAdd', 'xadd', 'execute'] as $method) {
            if (is_callable([$client, $method])) {
                return true;
            }
        }

        return false;
    }
}
