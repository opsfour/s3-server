<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Publishes normalized S3 events through an AMQP channel/exchange abstraction.
 *
 * Supported publishers:
 * - callable(string $exchange, string $routingKey, string $payload, S3Event $event): mixed
 * - object with publish($payload, $routingKey, $exchange)
 * - object with basic_publish($message, $exchange, $routingKey)
 */
final readonly class AmqpNotificationAdapter implements NotificationDestinationAdapter
{
    public function __construct(
        private mixed $publisher,
        private string $exchange,
        private string $routingKey = 's3.event',
        private mixed $messageFactory = null,
    ) {
        if ($messageFactory !== null && !is_callable($messageFactory)) {
            throw new \InvalidArgumentException('AMQP message factory must be callable.');
        }
        if (! is_callable($publisher) && ! self::supportsObjectPublisher($publisher)) {
            throw new \InvalidArgumentException('AMQP adapter requires a callable or publisher object with publish() or basic_publish().');
        }
    }

    public function name(): string
    {
        return 'amqp';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        try {
            $payload = S3EventDestinationPayload::toJson($event);
            $result = $this->publish($payload, $event);
        } catch (\JsonException $e) {
            return NotificationDeliveryResult::deadLetter('Event payload could not be encoded.', [
                'exchange' => $this->exchange,
                'routing_key' => $this->routingKey,
                'exception' => $e::class,
            ]);
        } catch (\Throwable $e) {
            return NotificationDeliveryResult::retryableFailure('Broker delivery failed.', [
                'exchange' => $this->exchange,
                'routing_key' => $this->routingKey,
                'exception' => $e::class,
            ]);
        }
        if ($result === false || $result === null) {
            return NotificationDeliveryResult::retryableFailure('Broker delivery failed.', [
                'exchange' => $this->exchange,
                'routing_key' => $this->routingKey,
            ]);
        }

        return NotificationDeliveryResult::sent([
            'exchange' => $this->exchange,
            'routing_key' => $this->routingKey,
            'result' => $result,
        ]);
    }

    private function publish(string $payload, S3Event $event): mixed
    {
        if (is_callable($this->publisher)) {
            return ($this->publisher)($this->exchange, $this->routingKey, $payload, $event);
        }

        if (!is_object($this->publisher)) {
            throw new \InvalidArgumentException('AMQP adapter requires a callable or publisher object.');
        }

        if (method_exists($this->publisher, 'publish')) {
            return $this->publisher->publish($payload, $this->routingKey, $this->exchange);
        }
        if (method_exists($this->publisher, 'basic_publish')) {
            $message = $this->messageFactory !== null
                ? ($this->messageFactory)($payload, $event)
                : $payload;

            return $this->publisher->basic_publish($message, $this->exchange, $this->routingKey);
        }

        throw new \InvalidArgumentException('AMQP publisher must expose publish() or basic_publish().');
    }

    private static function supportsObjectPublisher(mixed $publisher): bool
    {
        return is_object($publisher) && (
            is_callable([$publisher, 'publish'])
            || is_callable([$publisher, 'basic_publish'])
        );
    }
}
