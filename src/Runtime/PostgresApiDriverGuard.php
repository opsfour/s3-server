<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\S3ServerConfig;

/**
 * Reject the unsafe PostgreSQL event-loop driver before an API node connects.
 */
final class PostgresApiDriverGuard
{
    public static function assertAvailable(S3ServerConfig $config): void
    {
        if ($config->metadataDriver === 'postgres' && ! \extension_loaded('pq')) {
            throw new \RuntimeException('PostgreSQL API servers require the pecl-pq PHP extension.');
        }
    }
}
