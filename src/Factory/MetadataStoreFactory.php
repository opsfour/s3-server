<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use Psr\Log\LoggerInterface;

/**
 * Creates MetadataStore instances from configuration.
 *
 * All parameters are explicit — no defaults. The caller (CLI command
 * or ServiceProvider) resolves values from config/env.
 */
final class MetadataStoreFactory
{
    /**
     * @param  string  $driver  One of: sqlite, postgres, mysql.
     * @param  array{path?: string, dsn?: string, worker_pool_size?: int}  $config  Driver-specific configuration.
     */
    public static function create(string $driver, array $config = [], ?LoggerInterface $logger = null, ?MetricsCollector $metrics = null): MetadataStore
    {
        if ($driver === 'sqlite') {
            $logger?->warning(
                'SQLite metadata driver is intended for development/testing only. '
                .'Use Postgres or MySQL for production deployments.',
            );
        }

        $workerPoolSize = (int) ($config['worker_pool_size'] ?? 0);

        if ($driver === 'sqlite' && $workerPoolSize > 0) {
            $path = $config['path'] ?? throw new \InvalidArgumentException('SQLite requires "path"');
            $store = new ParallelSqliteMetadataStore($path, $workerPoolSize, $metrics);
            $store->initialize();

            return $store;
        }

        $store = match ($driver) {
            'sqlite' => new SqliteMetadataStore(
                $config['path'] ?? throw new \InvalidArgumentException('SQLite requires "path"'),
            ),
            'postgres' => PostgresMetadataStore::fromDsn(
                $config['dsn'] ?? throw new \InvalidArgumentException('Postgres requires "dsn"'),
            ),
            'mysql' => MysqlMetadataStore::fromDsn(
                $config['dsn'] ?? throw new \InvalidArgumentException('MySQL requires "dsn"'),
            ),
            default => throw new \InvalidArgumentException("Unknown metadata driver: {$driver}"),
        };

        $store->initialize();

        return $store;
    }
}
