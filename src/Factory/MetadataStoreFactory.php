<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaGuard;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use Psr\Log\LoggerInterface;

/**
 * Creates MetadataStore instances from configuration.
 *
 * Callers resolve driver configuration from config/env. The migration-default
 * remains for existing programmatic callers; serving integrations must pass
 * migrate: false for PostgreSQL/MySQL until a future major API removes the
 * ambiguous default.
 */
final class MetadataStoreFactory
{
    /**
     * @param  string  $driver  One of: sqlite, postgres, mysql.
     * @param  array{path?: string, dsn?: string, worker_pool_size?: int|string}  $config  Driver-specific configuration.
     */
    public static function create(string $driver, array $config = [], ?LoggerInterface $logger = null, ?MetricsCollector $metrics = null, bool $migrate = true): MetadataStore
    {
        $sqlitePath = null;
        if ($driver === 'sqlite') {
            $sqlitePath = $config['path'] ?? throw new \InvalidArgumentException('SQLite requires "path"');
            self::ensureSqliteDirectory($sqlitePath);
        }

        $workerPoolSize = filter_var(
            $config['worker_pool_size'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($workerPoolSize === false) {
            throw new \InvalidArgumentException('Metadata worker_pool_size must be a decimal integer >= 0.');
        }

        if (! $migrate) {
            if (! in_array($driver, ['postgres', 'mysql'], true)) {
                throw new \InvalidArgumentException('Read-only schema admission requires postgres or mysql.');
            }
            MetadataSchemaGuard::assertCurrent($driver, $config['dsn'] ?? throw new \InvalidArgumentException('Metadata DSN is required.'));
        }

        if ($driver === 'sqlite' && $workerPoolSize > 0) {
            if ($sqlitePath === null) {
                throw new \LogicException('Resolved SQLite path is unavailable.');
            }
            $store = new ParallelSqliteMetadataStore($sqlitePath, $workerPoolSize, $metrics);
            try {
                $store->initialize();
            } catch (\Throwable $error) {
                try {
                    $store->shutdown();
                } catch (\Throwable) {
                }
                throw $error;
            }

            return $store;
        }

        $store = match ($driver) {
            'sqlite' => new SqliteMetadataStore(
                $sqlitePath ?? throw new \LogicException('Resolved SQLite path is unavailable.'),
            ),
            'postgres' => PostgresMetadataStore::fromDsn(
                $config['dsn'] ?? throw new \InvalidArgumentException('Postgres requires "dsn"'),
            ),
            'mysql' => MysqlMetadataStore::fromDsn(
                $config['dsn'] ?? throw new \InvalidArgumentException('MySQL requires "dsn"'),
            ),
            default => throw new \InvalidArgumentException("Unknown metadata driver: {$driver}"),
        };

        if ($migrate) {
            $store->initialize();
        }

        return $store;
    }

    private static function ensureSqliteDirectory(string $path): void
    {
        if ($path === ':memory:') {
            return;
        }

        $directory = dirname($path);
        if (is_dir($directory)) {
            return;
        }
        if (! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create the SQLite metadata directory.');
        }
    }
}
