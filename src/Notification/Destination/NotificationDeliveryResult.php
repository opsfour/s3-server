<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

final readonly class NotificationDeliveryResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public NotificationDeliveryStatus $status,
        public ?string $reason = null,
        public array $context = [],
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public static function sent(array $context = []): self
    {
        return new self(NotificationDeliveryStatus::Sent, context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function retryableFailure(string $reason, array $context = []): self
    {
        return new self(NotificationDeliveryStatus::RetryableFailure, $reason, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function deadLetter(string $reason, array $context = []): self
    {
        return new self(NotificationDeliveryStatus::DeadLetter, $reason, $context);
    }
}
