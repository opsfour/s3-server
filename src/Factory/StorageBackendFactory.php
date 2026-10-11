<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Creates StorageBackend instances from configuration.
 */
final class StorageBackendFactory
{
    /**
     * @param  string  $driver  One of: filesystem, flysystem, memory.
     * @param array{path?: string, filesystem?: FilesystemOperator, filesystem_factory?: FlysystemFilesystemFactory, temp_dir?: string, temp_budget_bytes?: int|string, worker_pool_size?: int|string, pool_name?: string, read_first_byte_timeout_seconds?: int} $config
     */
    public static function create(
        string $driver,
        array $config = [],
        ?MetricsCollector $metrics = null,
        ?string $metricsDriver = null,
    ): StorageBackend {
        $backend = match ($driver) {
            'filesystem' => new FilesystemBackend(
                $config['path'] ?? throw new \InvalidArgumentException('Filesystem requires "path"'),
            ),
            'flysystem' => self::createFlysystem($config, $metrics),
            'memory' => new InMemoryBackend(),
            default => throw new \InvalidArgumentException("Unknown storage driver: {$driver}"),
        };

        return $metrics === null
            ? $backend
            : new ObservedStorageBackend($backend, $metrics, $metricsDriver ?? $driver);
    }

    /**
     * @param array{filesystem?: FilesystemOperator, filesystem_factory?: FlysystemFilesystemFactory, temp_dir?: string, temp_budget_bytes?: int|string, worker_pool_size?: int|string, pool_name?: string, read_first_byte_timeout_seconds?: int} $config
     */
    private static function createFlysystem(array $config, ?MetricsCollector $metrics): StorageBackend
    {
        $tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $tempBudgetBytes = filter_var(
            $config['temp_budget_bytes'] ?? 10_737_418_240,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($tempBudgetBytes === false) {
            throw new \InvalidArgumentException('Flysystem temp_budget_bytes must be a positive decimal integer.');
        }
        $workerPoolSize = filter_var(
            $config['worker_pool_size'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($workerPoolSize === false) {
            throw new \InvalidArgumentException('Flysystem worker_pool_size must be a decimal integer >= 0.');
        }
        if ($workerPoolSize > 0) {
            return new ParallelFlysystemBackend(
                $config['filesystem_factory'] ?? throw new \InvalidArgumentException(
                    'Flysystem worker mode requires a serializable "filesystem_factory".',
                ),
                $tempDir,
                $workerPoolSize,
                $metrics,
                poolName: (string) ($config['pool_name'] ?? 'flysystem'),
                tempBudgetBytes: $tempBudgetBytes,
                firstByteTimeoutSeconds: (int) ($config['read_first_byte_timeout_seconds'] ?? 20),
            );
        }

        return new FlysystemBackend(
            $config['filesystem'] ?? throw new \InvalidArgumentException(
                'Flysystem driver requires a "filesystem" key containing a League\Flysystem\FilesystemOperator instance. '
                . 'Register it in your service provider: $this->app->when(StorageBackendFactory::class)->give(["filesystem" => $operator]);',
            ),
            $tempDir,
            new StorageTempBudgetCoordinator($tempDir, $tempBudgetBytes),
        );
    }

    /**
     * @param array<string, mixed> $storageConfig
     */
    public static function createTierRegistry(
        array $storageConfig,
        ?MetricsCollector $metrics = null,
    ): StorageTierRegistry {
        $tiers = $storageConfig['tiers'] ?? null;
        if (is_array($tiers) && $tiers !== []) {
            $configured = [];
            $created = [];
            try {
                foreach ($tiers as $name => $tierConfig) {
                    if (!is_array($tierConfig)) {
                        throw new \InvalidArgumentException("Storage tier {$name} must be configured as an array.");
                    }

                    $tierName = is_string($name) ? $name : (string) ($tierConfig['name'] ?? '');
                    $driver = (string) ($tierConfig['driver'] ?? $storageConfig['driver'] ?? 'filesystem');
                    $tierConfig['pool_name'] ??= 'flysystem_' . strtolower($tierName);
                    $tierConfig['temp_budget_bytes'] ??= $storageConfig['temp_budget_bytes'] ?? 10_737_418_240;
                    $tierConfig['read_first_byte_timeout_seconds'] ??= $storageConfig['read_first_byte_timeout_seconds'] ?? 20;
                    $backend = self::create($driver, $tierConfig, $metrics, "{$driver}:{$tierName}");
                    $created[] = $backend;
                    $configured[] = new StorageTier(
                        name: $tierName,
                        backend: $backend,
                        restoreRequired: (bool) ($tierConfig['restore_required'] ?? false),
                        defaultWriteTier: (bool) ($tierConfig['default'] ?? false),
                    );
                }

                return new StorageTierRegistry($configured);
            } catch (\Throwable $error) {
                foreach (array_reverse($created) as $backend) {
                    if ($backend instanceof ShutdownAwareStorageBackend) {
                        try {
                            $backend->shutdown();
                        } catch (\Throwable) {
                        }
                    }
                }
                throw $error;
            }
        }

        $driver = (string) ($storageConfig['driver'] ?? 'filesystem');
        $backend = self::create($driver, $storageConfig, $metrics);

        try {
            return StorageTierRegistry::single(
                $backend,
                (string) ($storageConfig['tier'] ?? 'STANDARD'),
            );
        } catch (\Throwable $error) {
            if ($backend instanceof ShutdownAwareStorageBackend) {
                try {
                    $backend->shutdown();
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
    }
}
