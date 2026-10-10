<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Illuminate\Console\Command;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Runtime\BootstrapResourceCleanup;
use OpsFour\S3Server\Runtime\PostgresApiDriverGuard;
use OpsFour\S3Server\Runtime\ServeFailureMessage;
use OpsFour\S3Server\Runtime\ServeRuntimeShutdown;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Artisan command to start the opsfour S3-compatible server.
 *
 * Resolves backends from the container (registered by S3ServerServiceProvider)
 * with optional CLI overrides.
 *
 * Usage:
 *   php artisan s3:serve
 *   php artisan s3:serve --port=9001 --storage-path=/custom/path
 */
class S3ServerCommand extends Command
{
    protected $signature = 's3:serve
        {--host= : Listen address}
        {--port= : Listen port}
        {--storage-path= : Root storage directory (overrides config, filesystem driver only)}
        {--access-key= : Access key ID (overrides config, memory driver only)}
        {--secret-key= : Secret access key (overrides config, memory driver only)}';

    protected $description = 'Start the opsfour S3-compatible server';

    public function handle(): int
    {
        // Build logger.
        $logHandler = new StreamHandler(ByteStream\getStdout());
        $logHandler->pushProcessor(new PsrLogMessageProcessor());
        $logHandler->setFormatter(new ConsoleFormatter());

        $logger = new Logger('s3-server');
        $logger->pushHandler($logHandler);
        $logger = new FailureIsolatingLogger($logger);

        $ownedStorageTierRegistry = null;
        try {
            $config = app(S3ServerConfig::class);

            // Apply CLI overrides to server config.
            $overrides = [];
            if ($this->option('host') !== null) {
                $overrides['host'] = $this->option('host');
            }
            if ($this->option('port') !== null) {
                $port = $this->option('port');
                if ((! is_string($port) && ! is_int($port))
                    || ($parsedPort = filter_var($port, FILTER_VALIDATE_INT)) === false) {
                    throw new \InvalidArgumentException('--port must be a decimal integer within the supported range.');
                }
                $overrides['port'] = $parsedPort;
            }
            if ($this->option('storage-path') !== null) {
                $overrides['storagePath'] = $this->option('storage-path');
            }

            if (! empty($overrides)) {
                $config = $config->with($overrides);
            }

            PostgresApiDriverGuard::assertAvailable($config);

            $storageDriver = (string) config('s3-server.storage.driver', 'filesystem');
            if ($this->option('storage-path') !== null && $storageDriver !== 'filesystem') {
                $this->error('--storage-path can only override the filesystem storage driver.');

                return self::FAILURE;
            }

            // Remote backends only create their configured local staging directory.
            if ($storageDriver === 'filesystem' && ! is_dir($config->storagePath)) {
                if (! @mkdir($config->storagePath, 0o755, true) && ! is_dir($config->storagePath)) {
                    $this->error("Cannot create storage directory: {$config->storagePath}");

                    return self::FAILURE;
                }
            }

            // Resolve backends from container (or rebuild with CLI overrides).
            $metrics = app(MetricsCollector::class);
            $storageTierRegistry = $this->resolveStorageTiers($config, $metrics);
            if ($this->option('storage-path') !== null) {
                $ownedStorageTierRegistry = $storageTierRegistry;
            }
            $storage = $storageTierRegistry->defaultBackend();
            $metadata = app(MetadataStore::class);
            $metadata = new ObservedMetadataStore(
                $metadata,
                $metrics,
                (string) config('s3-server.metadata.driver', 'sqlite'),
            );
            $credentialProvider = $this->resolveCredentials();

            $runtime = (new S3ServerRuntimeFactory())->create(
                config: $config,
                metadata: $metadata,
                storage: $storage,
                credentialProvider: $credentialProvider,
                logger: $logger,
                metrics: $metrics,
                storageTiers: $storageTierRegistry,
                externalIamConfig: config('s3-server.external_iam', []),
                adminToken: $this->adminTokenFromConfig(),
            );
            $ownedStorageTierRegistry = null;
        } catch (\InvalidArgumentException $e) {
            BootstrapResourceCleanup::release(null, $ownedStorageTierRegistry);
            $this->error(ServeFailureMessage::format($e, 'bootstrap'));

            return self::FAILURE;
        } catch (\Throwable $e) {
            BootstrapResourceCleanup::release(null, $ownedStorageTierRegistry);
            $logger->error('S3 server bootstrap failed.', ['exception' => $e::class]);
            $this->error(ServeFailureMessage::format($e, 'bootstrap'));

            return self::FAILURE;
        }

        $failed = false;
        try {
            $this->info("opsfour S3 Server starting on {$config->host}:{$config->port}");
            $this->info("Storage: {$config->storagePath}");
            if ($config->sqliteWorkerPoolSize > 0 && $config->metadataDriver === 'sqlite') {
                $this->info("SQLite worker pool: {$config->sqliteWorkerPoolSize} workers");
            }
            if ($config->encryptionWorkerPoolSize > 0) {
                $this->info("Encryption worker pool: {$config->encryptionWorkerPoolSize} workers (threshold: {$config->encryptionParallelThreshold} bytes)");
            }
            if ($config->selectWorkerPoolSize > 0) {
                $this->info("S3 Select worker pool: {$config->selectWorkerPoolSize} workers");
            }
            $this->info('Press Ctrl+C to stop.');
            $runtime->server->start();

            // Wait for termination signal.
            $signal = \Amp\trapSignal([\SIGINT, \SIGTERM]);
            $logger->info('Received signal {signal}, shutting down...', [
                'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
            ]);
        } catch (\Throwable $e) {
            $logger->error('S3 server runtime failed.', ['exception' => $e::class]);
            $this->error(ServeFailureMessage::format($e, 'runtime'));
            $failed = true;
        } finally {
            $shutdownError = ServeRuntimeShutdown::stop($runtime, $logger);
            if ($shutdownError !== null) {
                $this->error($shutdownError);
                $failed = true;
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('Server stopped.');

        return self::SUCCESS;
    }

    private function adminTokenFromConfig(): ?string
    {
        $adminToken = config('s3-server.admin.token')
            ?? config('s3-server.external_iam.admin_token');

        return is_string($adminToken) ? $adminToken : null;
    }

    /**
     * Resolve storage backend, rebuilding if --storage-path was given.
     */
    private function resolveStorageTiers(
        S3ServerConfig $config,
        MetricsCollector $metrics,
    ): StorageTierRegistry {
        if ($this->option('storage-path') !== null) {
            $storageConfig = config('s3-server.storage', []);
            $storageConfig['path'] = $config->storagePath;
            $storageConfig['tiers'] = [];

            return StorageBackendFactory::createTierRegistry($storageConfig, $metrics);
        }

        return app(StorageTierRegistry::class);
    }

    /**
     * Resolve credential provider, rebuilding if --access-key/--secret-key were given.
     */
    private function resolveCredentials(): CredentialProvider
    {
        $accessKey = $this->option('access-key');
        $secretKey = $this->option('secret-key');

        if ($accessKey !== null || $secretKey !== null) {
            if (config('s3-server.credentials.driver', 'memory') !== 'memory') {
                throw new \InvalidArgumentException('--access-key and --secret-key require the memory credentials driver.');
            }

            $creds = config('s3-server.credentials', []);

            return CredentialProviderFactory::create('memory', [
                'access_key' => $accessKey ?? ($creds['access_key'] ?? null),
                'secret_key' => $secretKey ?? ($creds['secret_key'] ?? null),
                'owner_id' => $creds['owner_id'] ?? 'default-owner',
                'display_name' => $creds['display_name'] ?? 'Default User',
            ]);
        }

        return app(CredentialProvider::class);
    }
}
