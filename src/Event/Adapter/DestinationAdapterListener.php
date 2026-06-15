<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Event\Adapter;

use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Notification\Destination\NotificationDeliveryStatus;
use OpsFour\S3Server\Notification\Destination\NotificationDestinationAdapter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Bridges internal S3 events to a durable destination adapter.
 */
final readonly class DestinationAdapterListener
{
    public function __construct(
        private NotificationDestinationAdapter $adapter,
        private LoggerInterface $logger = new NullLogger,
    ) {}

    public function __invoke(S3Event $event): void
    {
        try {
            $result = $this->adapter->deliver($event);
        } catch (\Throwable $e) {
            $this->logger->error('Notification destination adapter failed.', $this->context($event, [
                'event' => 'destination_adapter_failed',
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }

        $context = $this->context($event, [
            'event' => 'destination_delivery_result',
            'status' => $result->status->value,
            'reason' => $result->reason,
        ] + $result->context);

        match ($result->status) {
            NotificationDeliveryStatus::Sent => $this->logger->info('Notification destination delivery completed.', $context),
            NotificationDeliveryStatus::RetryableFailure => $this->logger->warning('Notification destination delivery failed retryably.', $context),
            NotificationDeliveryStatus::DeadLetter => $this->logger->error('Notification destination delivery moved to dead letter.', $context),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function context(S3Event $event, array $context): array
    {
        return [
            'component' => 'notification',
            'adapter' => $this->adapter->name(),
            'event_name' => $event->name,
            'bucket' => $event->bucket,
            'key' => $event->key,
        ] + $context;
    }
}
