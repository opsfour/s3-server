<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Parallel\ParallelDatabaseCredentialProvider;

/**
 * Creates CredentialProvider instances from configuration.
 *
 * No hardcoded defaults — the caller must provide all required values.
 */
final class CredentialProviderFactory
{
    /**
     * @param  string  $driver  One of: memory, database, file, chain.
     * @param  array<string, mixed>  $config  Driver-specific configuration.
     */
    public static function create(string $driver, array $config = [], bool $bootstrap = false): CredentialProvider
    {
        return match ($driver) {
            'memory' => self::createInMemory($config),
            'database' => self::createDatabase($config, $bootstrap),
            'file' => new ConfigFileCredentialProvider(
                $config['path'] ?? throw new \InvalidArgumentException('File credentials requires "path"'),
            ),
            'chain' => self::createChain($config, $bootstrap),
            default => throw new \InvalidArgumentException("Unknown credentials driver: {$driver}"),
        };
    }

    /**
     * Create a provider for the Amp HTTP runtime.
     *
     * Direct factory and management-command callers remain synchronous. Only
     * the database provider used by a serving runtime is moved to workers.
     *
     * @param array<string, mixed> $config
     */
    public static function createForServing(
        string $driver,
        array $config = [],
        int $databaseWorkerPoolSize = 4,
        float $databaseOperationTimeoutSeconds = 15.0,
    ): CredentialProvider {
        if ($databaseWorkerPoolSize < 0) {
            throw new \InvalidArgumentException('Credential database worker pool size must be >= 0.');
        }
        if (! is_finite($databaseOperationTimeoutSeconds) || $databaseOperationTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Credential database operation timeout must be finite and > 0.');
        }
        if ($driver === 'chain' && isset($config['providers']) && is_array($config['providers']) && array_is_list($config['providers'])) {
            foreach ($config['providers'] as $index => $provider) {
                if ($provider instanceof CredentialProvider
                    || ! is_array($provider)
                    || ! is_string($provider['driver'] ?? null)) {
                    continue;
                }
                $nestedDriver = $provider['driver'];
                unset($provider['driver']);
                $config['providers'][$index] = self::createForServing(
                    $nestedDriver,
                    $provider,
                    $databaseWorkerPoolSize,
                    $databaseOperationTimeoutSeconds,
                );
            }
        } elseif ($driver === 'database') {
            $config['worker_pool_size'] = $databaseWorkerPoolSize;
            $config['operation_timeout_seconds'] = $databaseOperationTimeoutSeconds;
        }

        return self::create($driver, $config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createInMemory(array $config): InMemoryCredentialProvider
    {
        $required = ['access_key', 'secret_key', 'owner_id', 'display_name'];

        foreach ($required as $key) {
            if (! isset($config[$key]) || ! is_string($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException("Memory credentials requires \"{$key}\"");
            }
        }

        return new InMemoryCredentialProvider(
            new Credential(
                accessKeyId: $config['access_key'],
                secretAccessKey: $config['secret_key'],
                ownerId: $config['owner_id'],
                displayName: $config['display_name'],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createDatabase(array $config, bool $bootstrap): DatabaseCredentialProvider|ParallelDatabaseCredentialProvider
    {
        $cacheTtl = $config['cache_ttl'] ?? 1.0;
        if (! is_numeric($cacheTtl) || ! is_finite((float) $cacheTtl) || (float) $cacheTtl < 0.0) {
            throw new \InvalidArgumentException('Database credentials "cache_ttl" must be a finite number >= 0.');
        }

        $workerPoolSize = filter_var(
            $config['worker_pool_size'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($workerPoolSize === false) {
            throw new \InvalidArgumentException('Database credentials "worker_pool_size" must be a decimal integer >= 0.');
        }
        $operationTimeout = $config['operation_timeout_seconds'] ?? 15.0;
        if (! is_numeric($operationTimeout)
            || ! is_finite((float) $operationTimeout)
            || (float) $operationTimeout <= 0.0) {
            throw new \InvalidArgumentException('Database credentials "operation_timeout_seconds" must be a finite number > 0.');
        }

        $dsn = $config['dsn'] ?? throw new \InvalidArgumentException('Database credentials requires "dsn"');
        $username = isset($config['username']) && is_string($config['username']) && $config['username'] !== ''
            ? $config['username']
            : null;
        $password = isset($config['password']) && is_string($config['password'])
            ? $config['password']
            : null;
        if (! $bootstrap && $workerPoolSize > 0) {
            return new ParallelDatabaseCredentialProvider(
                $dsn,
                $username,
                $password,
                (float) $cacheTtl,
                $workerPoolSize,
                (float) $operationTimeout,
            );
        }

        return DatabaseCredentialProvider::fromDsn(
            $dsn,
            $username,
            $password,
            (float) $cacheTtl,
            $bootstrap,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createChain(array $config, bool $bootstrap): ChainCredentialProvider
    {
        if (! isset($config['providers']) || ! is_array($config['providers'])
            || ! array_is_list($config['providers']) || count($config['providers']) === 0) {
            throw new \InvalidArgumentException('Chain credentials requires a non-empty provider list.');
        }

        $providers = [];
        foreach ($config['providers'] as $index => $provider) {
            if ($provider instanceof CredentialProvider) {
                $providers[] = $provider;

                continue;
            }
            if (! is_array($provider) || ! is_string($provider['driver'] ?? null) || $provider['driver'] === '') {
                throw new \InvalidArgumentException("Chain credentials provider {$index} requires a provider instance or non-empty driver.");
            }

            $driver = $provider['driver'];
            unset($provider['driver']);
            $providers[] = self::create($driver, $provider, $bootstrap);
        }

        return new ChainCredentialProvider(...$providers);
    }
}
