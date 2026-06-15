<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

/**
 * Contract for durable external notification destinations.
 *
 * Implementations can wrap Redis Streams, Kafka, AMQP, NATS, or another
 * producer. Transport-specific retry, acknowledgements, and buffering should
 * stay inside the adapter.
 */
interface NotificationDestinationAdapter
{
    /**
     * Stable adapter name used in logs and metrics.
     */
    public function name(): string;

    /**
     * Deliver or persist the event to the external destination.
     */
    public function deliver(S3Event $event): NotificationDeliveryResult;
}
