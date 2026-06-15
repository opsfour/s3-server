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
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
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
     * @param list<array{pattern: string, listener: callable}> $notificationListeners
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

        $server = new S3Server(
            config: $config,
            metadata: $metadata,
            storage: $storage,
            logger: $logger,
            metrics: $metrics,
        );
        $server->setStorageTierRegistry($storageTiers);

        $server->addMiddleware(new AuthMiddleware(
            credentialProvider: $credentialProvider,
            region: $config->region,
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
        if ($config->encryptionWorkerPoolSize > 0) {
            $selectWorkerPool = new ContextWorkerPool($config->encryptionWorkerPoolSize);
            $server->addWorkerPool($selectWorkerPool, 'select', $config->encryptionWorkerPoolSize);
        }

        $notifications = new NotificationDispatcher(
            metadata: $metadata,
            logger: $logger,
            region: $config->region,
            metrics: $metrics,
        );
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
        $server->setNotificationDispatcher($notifications);

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

        return new S3ServerRuntime($server, $notifications, $encryption, $selectWorkerPool);
    }

    private function encryptionFromEnvironment(
        S3ServerConfig $config,
        MetricsCollector $metrics,
    ): ?EncryptionServiceInterface {
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
                $metrics,
            );
        }

        return new EncryptionService($masterKeyProvider);
    }
}
