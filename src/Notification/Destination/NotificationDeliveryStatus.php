<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

enum NotificationDeliveryStatus: string
{
    case Sent = 'sent';
    case RetryableFailure = 'retryable_failure';
    case DeadLetter = 'dead_letter';
}
