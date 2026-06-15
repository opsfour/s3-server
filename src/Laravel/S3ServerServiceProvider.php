<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

use Illuminate\Support\ServiceProvider;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Laravel service provider for the OpsFour S3 Server.
 *
 * Registers backend singletons (storage, metadata, credentials) via
 * factory classes and provides the s3:serve artisan command.
 *
 * Publish the config with:
 *   php artisan vendor:publish --tag=s3-server-config
 */
class S3ServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/s3-server.php', 's3-server');

        $this->app->singleton(MetricsCollector::class, fn() => new MetricsCollector());

        $this->app->singleton(S3ServerConfig::class, function ($app) {
            $config = $app['config']['s3-server'];

            return new S3ServerConfig(
                host: $config['host'] ?? '0.0.0.0',
                port: $config['port'] ?? 9000,
                region: $config['region'] ?? 'us-east-1',
                storagePath: $config['storage']['path'] ?? storage_path('s3'),
                storageTiers: $config['storage']['tiers'] ?? [],
                metadataDriver: $config['metadata']['driver'] ?? 'sqlite',
                metadataDsn: $config['metadata']['dsn'] ?? '',
                tlsCertPath: $config['tls_cert_path'] ?? null,
                tlsKeyPath: $config['tls_key_path'] ?? null,
                maxConcurrentConnections: $config['max_connections'] ?? 10000,
                connectionIdleTimeout: $config['connection_idle_timeout'] ?? 60,
                requestBodySizeLimit: $config['body_size_limit'] ?? 5_368_709_120,
                readTimeout: $config['read_timeout'] ?? 300,
                writeTimeout: $config['write_timeout'] ?? 300,
                perClientRateLimit: $config['rate_limit'] ?? 1000,
                baseDomain: $config['base_domain'] ?? null,
                strictBucketNaming: $config['strict_bucket_naming'] ?? true,
                websiteHostPattern: $config['website_host_pattern'] ?? null,
                masterKeyProvider: $config['master_key_provider'] ?? 'config',
                maxEncryptedObjectSize: $config['max_encrypted_object_size'] ?? 268_435_456,
                maxSelectObjectSize: $config['max_select_object_size'] ?? 268_435_456,
                shutdownDrainTimeout: $config['shutdown_drain_timeout'] ?? 30,
                sqliteWorkerPoolSize: $config['parallel']['sqlite_workers'] ?? 0,
                encryptionWorkerPoolSize: $config['parallel']['encryption_workers'] ?? 0,
                encryptionParallelThreshold: $config['parallel']['encryption_threshold'] ?? 65_536,
                quota: new QuotaConfig(
                    maxBucketsPerOwner: $config['quotas']['max_buckets_per_owner'] ?? 0,
                    maxObjectsPerBucket: $config['quotas']['max_objects_per_bucket'] ?? 0,
                    maxBytesPerBucket: $config['quotas']['max_bytes_per_bucket'] ?? 0,
                    maxBytesPerOwner: $config['quotas']['max_bytes_per_owner'] ?? 0,
                ),
                lifecycleIntervalSeconds: $config['lifecycle']['interval_seconds'] ?? 60.0,
                lifecycleBatchSize: $config['lifecycle']['batch_size'] ?? 1000,
                lifecycleMaxActionsPerRun: $config['lifecycle']['max_actions_per_run'] ?? 1000,
                lifecycleLockTtlSeconds: $config['lifecycle']['lock_ttl_seconds'] ?? 300,
            );
        });

        $this->app->singleton(StorageBackend::class, function ($app) {
            return $app->make(StorageTierRegistry::class)->defaultBackend();
        });

        $this->app->singleton(StorageTierRegistry::class, function ($app) {
            $config = $app['config']['s3-server'];

            return StorageBackendFactory::createTierRegistry($config['storage'] ?? []);
        });

        $this->app->singleton(MetadataStore::class, function ($app) {
            $config = $app['config']['s3-server'];
            $metadataConfig = $config['metadata'] ?? [];

            // For SQLite, derive path from storage path if no DSN given.
            if (($metadataConfig['driver'] ?? 'sqlite') === 'sqlite' && empty($metadataConfig['path'])) {
                $storagePath = $config['storage']['path'] ?? storage_path('s3');
                $metadataConfig['path'] = $storagePath . '/metadata.sqlite';
            }

            $parallelConfig = $config['parallel'] ?? [];
            $metadataConfig['worker_pool_size'] = $parallelConfig['sqlite_workers'] ?? 0;

            $store = MetadataStoreFactory::create(
                $metadataConfig['driver'] ?? 'sqlite',
                $metadataConfig,
                metrics: $app->make(MetricsCollector::class),
            );

            // Wrap with TTL cache decorator if configured (default 5s).
            $cacheTtl = (float) ($metadataConfig['cache_ttl'] ?? 5.0);
            if ($cacheTtl > 0) {
                $store = new CachedMetadataStoreDecorator($store, $cacheTtl);
            }

            return $store;
        });

        $this->app->singleton(CredentialProvider::class, function ($app) {
            $config = $app['config']['s3-server'];

            return CredentialProviderFactory::create(
                $config['credentials']['driver'] ?? 'memory',
                $config['credentials'] ?? [],
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/s3-server.php' => config_path('s3-server.php'),
            ], 's3-server-config');

            $this->commands([
                S3ServerCommand::class,
                S3CredentialsCommand::class,
            ]);
        }
    }
}
