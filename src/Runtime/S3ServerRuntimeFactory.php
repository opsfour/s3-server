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
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Storage\AmpLocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\AmpLocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use Psr\Log\LoggerInterface;

/**
 * Builds the framework-independent S3 runtime wiring.
 */
final readonly class S3ServerRuntimeFactory
{
    /**
     * @param  array<string, mixed>  $externalIamConfig
     * @param  list<array{pattern?: mixed, listener?: mixed}>  $notificationListeners
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
        $rawMetadata = $metadata;
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
            PostgresApiDriverGuard::assertAvailable($config);

            $server->addMiddleware(new AuthMiddleware(
                credentialProvider: $credentialProvider,
                region: $config->region,
                requestBodySizeLimit: $config->requestBodySizeLimit,
                postSpoolBudgetBytes: $config->postSpoolBudgetBytes,
                metadata: $metadata,
            ));

            $adminCredentialApi = AdminCredentialApiFactory::create($externalIamConfig, $credentialProvider);
            if ($adminCredentialApi !== null) {
                $server->setAdminCredentialApiHandler($adminCredentialApi);
            }

            if ($adminToken !== null && trim($adminToken) !== '') {
                $server->setAdminQuotaApiHandler(new AdminQuotaApiHandler($metadata, trim($adminToken)));
            }

            $encryption ??= $this->encryptionFromEnvironment($config, $metrics);
            $server->setEncryptionService($encryption);

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

            $localFencedWriter = null;
            $localLeaseFactory = null;
            if ($config->localObjectWriteTestScratchPath !== null) {
                [$localFencedWriter, $localLeaseFactory, $renewer] = $this->localObjectWriteTestWiring(
                    $config,
                    $rawMetadata,
                    $storageTiers,
                );
                $runtime->attachLocalObjectWriteTestResources($localFencedWriter, $renewer);
                $server->setLocalObjectWriteIntentCollector(new LocalObjectWriteIntentCollector(
                    $metadata,
                    $storageTiers,
                    $storageTiers->defaultTier()->name,
                    $logger,
                ));
                $server->setLocalObjectWriteTestWriter($localFencedWriter, $localLeaseFactory);
                $logger->warning('Isolated local write fence test profile active; shared-filesystem fault qualification remains open.');
            }

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
                localFencedWriter: $localFencedWriter,
                localLeaseFactory: $localLeaseFactory,
            );

            return $runtime;
        } catch (\Throwable $e) {
            if ($runtime !== null) {
                for ($attempt = 1; $attempt <= 2; ++$attempt) {
                    try {
                        $runtime->stop();
                        break;
                    } catch (\Throwable $cleanupError) {
                        self::logCleanupFailure($logger, 'Runtime factory shutdown failed.', $cleanupError);
                    }
                }
            } else {
                try {
                    $server->stop();
                } catch (\Throwable $cleanupError) {
                    self::logCleanupFailure($logger, 'Runtime factory server cleanup failed.', $cleanupError);
                }

                if ($encryption !== null && method_exists($encryption, 'shutdown')) {
                    try {
                        $encryption->shutdown();
                    } catch (\Throwable $cleanupError) {
                        self::logCleanupFailure($logger, 'Runtime factory encryption cleanup failed.', $cleanupError);
                    }
                }
            }

            throw $e;
        }
    }

    private static function logCleanupFailure(LoggerInterface $logger, string $message, \Throwable $error): void
    {
        try {
            $logger->warning($message, ['exception' => $error::class]);
        } catch (\Throwable) {
            // A broken logger must not replace the original bootstrap error.
        }
    }

    /**
     * @return array{LocalFencedObjectWriter, LocalObjectWriteLeaseGuardFactory, DedicatedAmpObjectWriteLeaseRenewer}
     */
    private function localObjectWriteTestWiring(
        S3ServerConfig $config,
        MetadataStore $rawMetadata,
        StorageTierRegistry $tiers,
    ): array {
        $scratchPath = $config->localObjectWriteTestScratchPath;
        if ($scratchPath === null || ! $config->allowUnfencedStorageForTests) {
            throw new \LogicException('Local object write test profile was not explicitly enabled.');
        }
        if (($config->metadataDriver === 'postgres' && ! $rawMetadata instanceof PostgresMetadataStore)
            || ($config->metadataDriver === 'mysql' && ! $rawMetadata instanceof MysqlMetadataStore)
            || ! in_array($config->metadataDriver, ['postgres', 'mysql'], true)
            || trim($config->metadataDsn) === '') {
            throw new \RuntimeException('Local object write test profile requires a raw PostgreSQL/MySQL metadata store and matching non-empty driver DSN.');
        }
        MetadataDriverStartupGuard::assertRawFencedSqlStore(
            $rawMetadata,
            $config->metadataDriver,
            $config->metadataDsn,
        );

        $backend = $tiers->defaultBackend();
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof FilesystemBackend || realpath($backend->getBasePath()) === false) {
            throw new \RuntimeException('Local object write test profile requires an existing filesystem default tier.');
        }

        // This scoped test profile accepts only a private directory beneath
        // the process temp root on its same device. It does not certify a
        // network filesystem or prove that the temp root is node-local.
        $scratch = realpath($scratchPath);
        $tempRoot = realpath(sys_get_temp_dir());
        $scratchStat = $scratch !== false ? @stat($scratch) : false;
        $tempStat = $tempRoot !== false ? @stat($tempRoot) : false;
        if ($scratch === false || $tempRoot === false || $scratchStat === false || $tempStat === false
            || ! str_starts_with($scratch, rtrim($tempRoot, '/') . '/')
            || $scratchStat['dev'] !== $tempStat['dev']) {
            throw new \RuntimeException('Local object write test scratch must be an existing directory on the process temporary volume.');
        }

        $renewer = $config->metadataDriver === 'postgres'
            ? DedicatedAmpObjectWriteLeaseRenewer::forPostgres($config->metadataDsn)
            : DedicatedAmpObjectWriteLeaseRenewer::forMysql($config->metadataDsn);
        try {
            $writer = new LocalFencedObjectWriter(
                $backend->getBasePath(),
                $scratch,
                $tiers->defaultTier()->name,
                workerIo: getenv('S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK') === '1'
                    ? new LocalObjectTestPublishPauseIo($scratch)
                    : null,
            );

            return [
                $writer,
                new AmpLocalObjectWriteLeaseGuardFactory(
                    new AmpLocalObjectWriteLeaseSql($rawMetadata),
                    $renewer,
                    new LocalObjectWriteLeaseAdmission(),
                ),
                $renewer,
            ];
        } catch (\Throwable $error) {
            $renewer->close();
            throw $error;
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
