<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\External\AdminCredentialApiFactory;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Encryption\RedisMasterKeyProvider;
use OpsFour\S3Server\Encryption\VaultMasterKeyProvider;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Psr\Log\LoggerInterface;

/**
 * Builds the framework-independent S3 runtime wiring.
 */
final readonly class S3ServerRuntimeFactory
{
    /**
     * @param array<string, mixed> $externalIamConfig
     * @param list<array{pattern?: mixed, listener?: mixed}> $notificationListeners
     */
    public function create(
        S3ServerConfig $config,
        MetadataStore $metadata,
        StorageBackend $storage,
        CredentialProvider $credentialProvider,
        LoggerInterface $logger,
        MetricsCollector $metrics,
        ?StorageTierRegistry $storageTiers = null,
        array $externalIamConfig = [],
        ?string $adminToken = null,
        ?EncryptionServiceInterface $encryption = null,
        array $notificationListeners = [],
    ): S3ServerRuntime {
        $storageTiers ??= StorageTierRegistry::single($storage);
        $storageTiers = $storageTiers->withObservability($metrics);
        $storage = $storageTiers->defaultBackend();
        if (! $metadata instanceof ObservedMetadataStore) {
            $metadata = new ObservedMetadataStore($metadata, $metrics, $config->metadataDriver);
        }

        $server = new S3Server(
            config: $config,
            metadata: $metadata,
            storage: $storage,
            logger: $logger,
            metrics: $metrics,
        );
        $server->setStorageTierRegistry($storageTiers);
        $runtime = null;

        try {
            $server->addMiddleware(new AuthMiddleware(
                credentialProvider: $credentialProvider,
                region: $config->region,
                requestBodySizeLimit: $config->requestBodySizeLimit,
            ));

            $adminCredentialApi = AdminCredentialApiFactory::create($externalIamConfig, $credentialProvider);
            if ($adminCredentialApi !== null) {
                $server->setAdminCredentialApiHandler($adminCredentialApi);
            }

            if ($adminToken !== null && trim($adminToken) !== '') {
                $server->setAdminQuotaApiHandler(new AdminQuotaApiHandler($metadata, trim($adminToken)));
            }

            $encryption ??= $this->encryptionFromEnvironment($config, $metrics);

            $selectWorkerPool = null;
            if ($config->selectWorkerPoolSize > 0) {
                $selectWorkerPool = new ContextWorkerPool($config->selectWorkerPoolSize);
                $server->addWorkerPool($selectWorkerPool, 'select', $config->selectWorkerPoolSize);
            }

            $notifications = new NotificationDispatcher(
                metadata: $metadata,
                logger: $logger,
                region: $config->region,
                metrics: $metrics,
            );
            $server->setNotificationDispatcher($notifications);
            $runtime = new S3ServerRuntime($server, $notifications, $encryption, $selectWorkerPool, $logger);

            foreach ($notificationListeners as $listenerConfig) {
                $pattern = $listenerConfig['pattern'] ?? null;
                $listener = $listenerConfig['listener'] ?? null;

                if (! is_string($pattern) || $pattern === '') {
                    throw new \InvalidArgumentException('Notification listener pattern must be a non-empty string.');
                }

                if (! is_callable($listener)) {
                    throw new \InvalidArgumentException("Notification listener for pattern {$pattern} must be callable.");
                }

                $notifications->listen($pattern, $listener);
            }

            HandlerRegistrar::registerAll(
                registry: $server->getHandlerRegistry(),
                metadata: $metadata,
                storage: $storage,
                config: $config,
                encryption: $encryption,
                notifications: $notifications,
                selectWorkerPool: $selectWorkerPool,
                credentialProvider: $credentialProvider,
                metrics: $metrics,
                storageTiers: $storageTiers,
            );

            return $runtime;
        } catch (\Throwable $e) {
            if ($runtime !== null) {
                $runtime->stop();
            } else {
                try {
                    $server->stop();
                } catch (\Throwable $cleanupError) {
                    $logger->warning('Runtime factory server cleanup error: {error}', ['error' => $cleanupError->getMessage()]);
                }

                if ($encryption !== null && method_exists($encryption, 'shutdown')) {
                    try {
                        $encryption->shutdown();
                    } catch (\Throwable $cleanupError) {
                        $logger->warning('Runtime factory encryption cleanup error: {error}', ['error' => $cleanupError->getMessage()]);
                    }
                }
            }

            throw $e;
        }
    }

    private function encryptionFromEnvironment(
        S3ServerConfig $config,
        MetricsCollector $metrics,
    ): ?EncryptionServiceInterface {
        $masterKeyProvider = $this->masterKeyProvider($config);
        if ($masterKeyProvider === null) {
            return null;
        }

        // Encryption workers intentionally read config keys from inherited
        // environment variables. External providers stay in-process so key
        // material is never serialized over worker IPC.
        if ($config->encryptionWorkerPoolSize > 0 && $config->masterKeyProvider === 'config') {
            return new ParallelEncryptionService(
                $masterKeyProvider,
                $config->encryptionWorkerPoolSize,
                $config->encryptionParallelThreshold,
                $metrics,
            );
        }

        return new EncryptionService($masterKeyProvider);
    }

    private function masterKeyProvider(S3ServerConfig $config): ?MasterKeyProvider
    {
        if ($config->masterKeyProvider === 'redis') {
            return new RedisMasterKeyProvider();
        }

        if ($config->masterKeyProvider === 'vault') {
            return new VaultMasterKeyProvider();
        }

        $masterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        $masterKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        if (($masterKey === false || $masterKey === '') && ($masterKeys === false || $masterKeys === '')) {
            return null;
        }

        return new ConfigMasterKeyProvider(
            $masterKey !== false && $masterKey !== '' ? $masterKey : null,
            $masterKeys !== false && $masterKeys !== '' ? $masterKeys : null,
        );
    }
}
