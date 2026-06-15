<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification\Destination;

use OpsFour\S3Server\Event\S3Event;

final class S3EventDestinationPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(S3Event $event): array
    {
        return $event->toArray();
    }

    public static function toJson(S3Event $event): string
    {
        return json_encode(self::toArray($event), \JSON_THROW_ON_ERROR);
    }
}
