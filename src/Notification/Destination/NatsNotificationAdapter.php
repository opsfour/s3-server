<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Publishes normalized S3 events through a NATS client abstraction.
 *
 * Supported publishers:
 * - callable(string $subject, string $payload, S3Event $event): mixed
 * - object with publish($subject, $payload)
 */
final readonly class NatsNotificationAdapter implements NotificationDestinationAdapter
{
    public function __construct(
        private mixed $publisher,
        private string $subject,
    ) {
        if ($subject === '') {
            throw new \InvalidArgumentException('NATS subject must not be empty.');
        }
    }

    public function name(): string
    {
        return 'nats';
    }

    public function deliver(S3Event $event): NotificationDeliveryResult
    {
        $payload = S3EventDestinationPayload::toJson($event);

        try {
            $result = $this->publish($payload, $event);
        } catch (\Throwable $e) {
            return NotificationDeliveryResult::retryableFailure($e->getMessage(), [
                'subject' => $this->subject,
                'exception' => $e::class,
            ]);
        }

        return NotificationDeliveryResult::sent([
            'subject' => $this->subject,
            'result' => $result,
        ]);
    }

    private function publish(string $payload, S3Event $event): mixed
    {
        if (is_callable($this->publisher)) {
            return ($this->publisher)($this->subject, $payload, $event);
        }

        if (is_object($this->publisher) && method_exists($this->publisher, 'publish')) {
            return $this->publisher->publish($this->subject, $payload);
        }

        throw new \InvalidArgumentException('NATS adapter requires a callable or a publish() method.');
    }
}
