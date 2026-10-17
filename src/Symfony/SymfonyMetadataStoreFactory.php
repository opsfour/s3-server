<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use OpsFour\S3Server\Observability\MetricsCollector;

/** @internal Creates metadata after runtime mounts are available. */
final class SymfonyMetadataStoreFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config, string $storagePath, MetricsCollector $metrics): MetadataStore
    {
        $driver = (string) ($config['driver'] ?? 'sqlite');
        if ($driver === 'sqlite' && ($config['path'] ?? null) === null) {
            $config['path'] = SqliteMetadataPathResolver::resolve($storagePath);
        }

        return MetadataStoreFactory::create(
            $driver,
            $config,
            metrics: $metrics,
            migrate: $driver === 'sqlite',
        );
    }
}
