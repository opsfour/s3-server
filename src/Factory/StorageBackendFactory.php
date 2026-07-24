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
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Creates StorageBackend instances from configuration.
 */
final class StorageBackendFactory
{
    /**
     * @param  string  $driver  One of: filesystem, flysystem, memory.
     * @param array{path?: string, filesystem?: FilesystemOperator, filesystem_factory?: FlysystemFilesystemFactory, temp_dir?: string, worker_pool_size?: int, pool_name?: string} $config
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
     * @param array{filesystem?: FilesystemOperator, filesystem_factory?: FlysystemFilesystemFactory, temp_dir?: string, worker_pool_size?: int, pool_name?: string} $config
     */
    private static function createFlysystem(array $config, ?MetricsCollector $metrics): StorageBackend
    {
        $tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $workerPoolSize = (int) ($config['worker_pool_size'] ?? 0);
        if ($workerPoolSize > 0) {
            return new ParallelFlysystemBackend(
                $config['filesystem_factory'] ?? throw new \InvalidArgumentException(
                    'Flysystem worker mode requires a serializable "filesystem_factory".',
                ),
                $tempDir,
                $workerPoolSize,
                $metrics,
                poolName: (string) ($config['pool_name'] ?? 'flysystem'),
            );
        }

        return new FlysystemBackend(
            $config['filesystem'] ?? throw new \InvalidArgumentException(
                'Flysystem driver requires a "filesystem" key containing a League\Flysystem\FilesystemOperator instance. '
                . 'Register it in your service provider: $this->app->when(StorageBackendFactory::class)->give(["filesystem" => $operator]);',
            ),
            $tempDir,
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
            foreach ($tiers as $name => $tierConfig) {
                if (!is_array($tierConfig)) {
                    throw new \InvalidArgumentException("Storage tier {$name} must be configured as an array.");
                }

                $tierName = is_string($name) ? $name : (string) ($tierConfig['name'] ?? '');
                $driver = (string) ($tierConfig['driver'] ?? $storageConfig['driver'] ?? 'filesystem');
                $tierConfig['pool_name'] ??= 'flysystem_' . strtolower($tierName);
                $configured[] = new StorageTier(
                    name: $tierName,
                    backend: self::create($driver, $tierConfig, $metrics, "{$driver}:{$tierName}"),
                    restoreRequired: (bool) ($tierConfig['restore_required'] ?? false),
                    defaultWriteTier: (bool) ($tierConfig['default'] ?? false),
                );
            }

            return new StorageTierRegistry($configured);
        }

        $driver = (string) ($storageConfig['driver'] ?? 'filesystem');

        return StorageTierRegistry::single(
            self::create($driver, $storageConfig, $metrics),
            (string) ($storageConfig['tier'] ?? 'STANDARD'),
        );
    }
}
