<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Laravel;

use Amp\ByteStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Illuminate\Console\Command;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\External\AdminCredentialApiFactory;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Artisan command to start the OpsFour S3-compatible server.
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

    protected $description = 'Start the OpsFour S3-compatible server';

    public function handle(): int
    {
        $config = app(S3ServerConfig::class);

        // Apply CLI overrides to server config.
        $overrides = [];
        if ($this->option('host') !== null) {
            $overrides['host'] = $this->option('host');
        }
        if ($this->option('port') !== null) {
            $overrides['port'] = (int) $this->option('port');
        }
        if ($this->option('storage-path') !== null) {
            $overrides['storagePath'] = $this->option('storage-path');
        }

        if (! empty($overrides)) {
            $config = $config->with($overrides);
        }

        // Ensure storage path exists.
        if (! is_dir($config->storagePath)) {
            mkdir($config->storagePath, 0o755, true);
        }

        // Build logger.
        $logHandler = new StreamHandler(ByteStream\getStdout());
        $logHandler->pushProcessor(new PsrLogMessageProcessor);
        $logHandler->setFormatter(new ConsoleFormatter);

        $logger = new Logger('s3-server');
        $logger->pushHandler($logHandler);

        // Resolve backends from container (or rebuild with CLI overrides).
        $metrics = app(MetricsCollector::class);
        $storageTierRegistry = app(StorageTierRegistry::class);
        $storage = $this->resolveStorage($config);
        $storage = new ObservedStorageBackend(
            $storage,
            $metrics,
            (string) config('s3-server.storage.driver', 'filesystem'),
        );
        $metadata = app(MetadataStore::class);
        $metadata = new ObservedMetadataStore(
            $metadata,
            $metrics,
            (string) config('s3-server.metadata.driver', 'sqlite'),
        );
        $credentialProvider = $this->resolveCredentials();

        // Build server.
        $server = new S3Server(
            config: $config,
            metadata: $metadata,
            storage: $storage,
            logger: $logger,
            metrics: $metrics,
        );
        $server->setStorageTierRegistry($storageTierRegistry);

        // Wire auth middleware.
        $server->addMiddleware(new AuthMiddleware(
            credentialProvider: $credentialProvider,
            region: $config->region,
        ));

        $adminCredentialApi = AdminCredentialApiFactory::create(
            config('s3-server.external_iam', []),
            $credentialProvider,
        );
        if ($adminCredentialApi !== null) {
            $server->setAdminCredentialApiHandler($adminCredentialApi);
        }

        $adminToken = config('s3-server.admin.token')
            ?: config('s3-server.external_iam.admin_token');
        if (is_string($adminToken) && trim($adminToken) !== '') {
            $server->setAdminQuotaApiHandler(new AdminQuotaApiHandler($metadata, trim($adminToken)));
        }

        // Build encryption service (parallel if configured).
        $encryption = $this->resolveEncryption($config);

        // Build S3 Select worker pool (reuses encryption pool size config).
        $selectWorkerPool = null;
        if ($config->encryptionWorkerPoolSize > 0) {
            $selectWorkerPool = new ContextWorkerPool($config->encryptionWorkerPoolSize);
            $server->addWorkerPool($selectWorkerPool, 'select', $config->encryptionWorkerPoolSize);
        }

        // Build notification dispatcher.
        $notifications = new NotificationDispatcher($metadata, $logger, $config->region, metrics: $metrics);
        $server->setNotificationDispatcher($notifications);

        HandlerRegistrar::registerAll(
            $server->getHandlerRegistry(),
            $metadata,
            $storage,
            $config,
            $encryption,
            $notifications,
            $selectWorkerPool,
            $credentialProvider,
            $metrics,
            $storageTierRegistry,
        );

        $this->info("OpsFour S3 Server starting on {$config->host}:{$config->port}");
        $this->info("Storage: {$config->storagePath}");
        if ($config->sqliteWorkerPoolSize > 0 && $config->metadataDriver === 'sqlite') {
            $this->info("SQLite worker pool: {$config->sqliteWorkerPoolSize} workers");
        }
        if ($config->encryptionWorkerPoolSize > 0) {
            $this->info("Encryption worker pool: {$config->encryptionWorkerPoolSize} workers (threshold: {$config->encryptionParallelThreshold} bytes)");
        }
        $this->info('Press Ctrl+C to stop.');

        $server->start();

        // Wait for termination signal.
        $signal = \Amp\trapSignal([\SIGINT, \SIGTERM]);

        $logger->info('Received signal {signal}, shutting down...', [
            'signal' => $signal === \SIGINT ? 'SIGINT' : 'SIGTERM',
        ]);

        $server->stop();

        $this->info('Server stopped.');

        return self::SUCCESS;
    }

    /**
     * Resolve storage backend, rebuilding if --storage-path was given.
     */
    private function resolveStorage(S3ServerConfig $config): StorageBackend
    {
        if ($this->option('storage-path') !== null) {
            $driver = config('s3-server.storage.driver', 'filesystem');

            return StorageBackendFactory::create($driver, [
                'path' => $config->storagePath,
            ]);
        }

        return app(StorageBackend::class);
    }

    /**
     * Resolve encryption service, using parallel workers if configured.
     */
    private function resolveEncryption(S3ServerConfig $config): ?EncryptionServiceInterface
    {
        $masterKeyEnv = getenv('S3_ENCRYPTION_MASTER_KEY');
        $masterKeysEnv = getenv('S3_ENCRYPTION_MASTER_KEYS');
        if (($masterKeyEnv === false || $masterKeyEnv === '') && ($masterKeysEnv === false || $masterKeysEnv === '')) {
            return null;
        }

        $masterKeyProvider = new ConfigMasterKeyProvider(
            $masterKeyEnv !== false && $masterKeyEnv !== '' ? $masterKeyEnv : null,
        );

        if ($config->encryptionWorkerPoolSize > 0) {
            return new ParallelEncryptionService(
                $masterKeyProvider,
                $config->encryptionWorkerPoolSize,
                $config->encryptionParallelThreshold,
                app(MetricsCollector::class),
            );
        }

        return new EncryptionService($masterKeyProvider);
    }

    /**
     * Resolve credential provider, rebuilding if --access-key/--secret-key were given.
     */
    private function resolveCredentials(): CredentialProvider
    {
        $accessKey = $this->option('access-key');
        $secretKey = $this->option('secret-key');

        if ($accessKey !== null || $secretKey !== null) {
            $creds = config('s3-server.credentials', []);

            return CredentialProviderFactory::create('memory', [
                'access_key' => $accessKey ?? $creds['access_key'],
                'secret_key' => $secretKey ?? $creds['secret_key'],
                'owner_id' => $creds['owner_id'] ?? 'default-owner',
                'display_name' => $creds['display_name'] ?? 'Default User',
            ]);
        }

        return app(CredentialProvider::class);
    }
}
