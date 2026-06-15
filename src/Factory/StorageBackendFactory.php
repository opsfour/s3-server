<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
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
     * @param  array{path?: string, filesystem?: FilesystemOperator, temp_dir?: string}  $config
     */
    public static function create(string $driver, array $config = []): StorageBackend
    {
        return match ($driver) {
            'filesystem' => new FilesystemBackend(
                $config['path'] ?? throw new \InvalidArgumentException('Filesystem requires "path"'),
            ),
            'flysystem' => new FlysystemBackend(
                $config['filesystem'] ?? throw new \InvalidArgumentException(
                    'Flysystem driver requires a "filesystem" key containing a League\Flysystem\FilesystemOperator instance. '
                    . 'Register it in your service provider: $this->app->when(StorageBackendFactory::class)->give(["filesystem" => $operator]);',
                ),
                $config['temp_dir'] ?? sys_get_temp_dir(),
            ),
            'memory' => new InMemoryBackend(),
            default => throw new \InvalidArgumentException("Unknown storage driver: {$driver}"),
        };
    }

    /**
     * @param array<string, mixed> $storageConfig
     */
    public static function createTierRegistry(array $storageConfig): StorageTierRegistry
    {
        $tiers = $storageConfig['tiers'] ?? null;
        if (is_array($tiers) && $tiers !== []) {
            $configured = [];
            foreach ($tiers as $name => $tierConfig) {
                if (!is_array($tierConfig)) {
                    throw new \InvalidArgumentException("Storage tier {$name} must be configured as an array.");
                }

                $tierName = is_string($name) ? $name : (string) ($tierConfig['name'] ?? '');
                $driver = (string) ($tierConfig['driver'] ?? $storageConfig['driver'] ?? 'filesystem');
                $configured[] = new StorageTier(
                    name: $tierName,
                    backend: self::create($driver, $tierConfig),
                    restoreRequired: (bool) ($tierConfig['restore_required'] ?? false),
                    defaultWriteTier: (bool) ($tierConfig['default'] ?? false),
                );
            }

            return new StorageTierRegistry($configured);
        }

        $driver = (string) ($storageConfig['driver'] ?? 'filesystem');

        return StorageTierRegistry::single(
            self::create($driver, $storageConfig),
            (string) ($storageConfig['tier'] ?? 'STANDARD'),
        );
    }
}
