<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

final class QueueLease
{
    public const int DURATION_SECONDS = 300;

    public const int RENEWAL_INTERVAL_SECONDS = 100;

    public static function expiresAt(?float $now = null): float
    {
        return ($now ?? microtime(true)) + self::DURATION_SECONDS;
    }
}
