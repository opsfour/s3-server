<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider;
use OpsFour\S3Server\Auth\External\AdminCredentialApiFactory;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\CredentialWorkerPoolOwner;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Encryption\RedisMasterKeyProvider;
use OpsFour\S3Server\Encryption\VaultMasterKeyProvider;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutCapability;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ProviderWorkerProbe;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeBinding;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeRegistry;
use OpsFour\S3Server\Storage\ParallelAwsS3ReadExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Storage\MetadataLocalObjectWriteLeaseAdapter;
use OpsFour\S3Server\Storage\MetadataLocalObjectWriteLeaseGuardFactory;
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
        ObjectPartLayoutCapability::require($metadata);
        $storageTiers ??= StorageTierRegistry::single($storage);
        $storageTiers = $storageTiers->withObservability($metrics);
        $storage = $storageTiers->defaultBackend();
        if (! ObservedMetadataStore::isObservedBy($metadata, $metrics)) {
            $metadata = new ObservedMetadataStore($metadata, $metrics, $config->metadataDriver);
        }
        if (! $metadata instanceof ObjectWriteIntentStore) {
            throw new \LogicException('Runtime metadata observability must preserve object write intents.');
        }
        ObjectPartLayoutCapability::require($metadata);

        $server = new S3Server(
            config: $config,
            metadata: $metadata,
            storage: $storage,
            logger: $logger,
            metrics: $metrics,
        );
        $server->setStorageTierRegistry($storageTiers);
        $runtime = null;
        /** @var list<array{AwsS3StorageBackend, AwsS3OperationalState, string, object}> $attachedAws */
        $attachedAws = [];
        $localStorageRootLocks = $server->transferLocalStorageRootLockOwnership();

        try {
            PostgresApiDriverGuard::assertAvailable($config);

            if ($credentialProvider instanceof CredentialWorkerPoolOwner) {
                $poolNumber = 0;
                $credentialProvider->transferCredentialWorkerPools(
                    function (\Amp\Parallel\Worker\WorkerPool $pool, int $workers) use ($server, &$poolNumber): void {
                        ++$poolNumber;
                        $server->addWorkerPool(
                            $pool,
                            $poolNumber === 1 ? 'database_credentials' : "database_credentials_{$poolNumber}",
                            $workers,
                        );
                    },
                );
            }

            $postObjectSpoolCoordinator = $config->hasClusterRole(ClusterRoleSet::API)
                ? $server->preparePostObjectSpoolCoordinator()
                : null;

            $server->addMiddleware(new AuthMiddleware(
                credentialProvider: $credentialProvider,
                region: $config->region,
                requestBodySizeLimit: $config->requestBodySizeLimit,
                postSpoolBudgetBytes: $config->postSpoolBudgetBytes,
                postSpoolCoordinator: $postObjectSpoolCoordinator,
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
            if ($config->hasClusterRole(ClusterRoleSet::API) && $config->selectWorkerPoolSize > 0) {
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
            $runtime = new S3ServerRuntime(
                $server,
                $notifications,
                $encryption,
                $selectWorkerPool,
                $logger,
                $localStorageRootLocks,
            );

            $localFencedWriter = null;
            $localLeaseFactory = null;
            $localPartWriter = null;
            $awsObjectWriter = null;
            if ($config->localObjectWriteTestScratchPath !== null) {
                [$localFencedWriter, $localLeaseFactory, $renewer, $localPartWriter] = $this->localObjectWriteWiring(
                    $config,
                    $rawMetadata,
                    $metadata,
                    $storageTiers,
                    $this->testScratchPath($config),
                    true,
                );
                $logger->warning('Isolated local write fence fault profile active; its pause hook must never be enabled in production.');
            } elseif ($this->localFilesystemBackend($storageTiers) !== null) {
                [$localFencedWriter, $localLeaseFactory, $renewer, $localPartWriter] = $this->localObjectWriteWiring(
                    $config,
                    $rawMetadata,
                    $metadata,
                    $storageTiers,
                    $this->productionScratchPath($storageTiers),
                    false,
                );
            }

            if ($localFencedWriter !== null && $localLeaseFactory !== null && $localPartWriter !== null) {
                $runtime->attachLocalObjectWriteResources($localFencedWriter, $renewer, $localPartWriter);
                $server->setLocalObjectWriteIntentCollector(new LocalObjectWriteIntentCollector(
                    $metadata,
                    $storageTiers,
                    $storageTiers->defaultTier()->name,
                    $logger,
                ));
                $server->setLocalObjectWriter($localFencedWriter, $localLeaseFactory, $localPartWriter);
            }

            $awsBindings = $this->admittedAwsTiers($storageTiers);
            if ($awsBindings !== []) {
                if ($config->metadataDriver !== 'postgres' || ! $metadata instanceof AwsS3OperationalSummaryReader) {
                    throw new \RuntimeException('The qualified AWS S3 profile requires PostgreSQL metadata.');
                }
                $inventory = StorageMutatorInventory::forEnabledRuntime([
                    ...HandlerRegistrar::storageMutatorRegistrations(),
                    ...S3Server::storageMutatorRegistrations(),
                    ...\OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
                ]);
                $firstBinding = $awsBindings[0][1]->validatedBinding()
                    ?? throw new \RuntimeException('The admitted AWS S3 profile lost its validated binding.');
                $firstProfile = $firstBinding->profile
                    ?? throw new \RuntimeException('The admitted AWS S3 profile lost its provider profile.');
                $shared = $this->awsSharedResources($server, $config, $metrics, $firstProfile);
                $providerProbe = $shared['providerProbe'];
                $readExecutor = $shared['readExecutor'];
                $stagePool = $shared['stagePool'];
                $budgetPool = $shared['budgetPool'];
                $tempBudget = $shared['tempBudget'];
                $tierRuntimes = [];
                foreach ($awsBindings as [$awsTier, $awsStorage]) {
                    $binding = $awsStorage->validatedBinding()
                        ?? throw new \RuntimeException('The admitted AWS S3 profile lost its validated binding.');
                    $profile = $binding->profile
                        ?? throw new \RuntimeException('The admitted AWS S3 profile lost its provider profile.');
                    $awsOperationalState = new AwsS3OperationalState(
                        $binding,
                        $providerProbe,
                        $inventory,
                        $metadata,
                        $awsTier->name,
                    );
                    $awsStorage->attachOperationalState($awsOperationalState, $awsTier->name, $metadata);
                    $attachedAws[] = [$awsStorage, $awsOperationalState, $awsTier->name, $metadata];
                    $awsStorage->attachReadExecutor($readExecutor);
                    $awsTierWriter = $awsStorage->createForegroundObjectWriter(
                        $metadata,
                        $this->awsObjectScratchPath(),
                        $awsTier->name,
                        budgetBytes: $config->storageTempBudgetBytes,
                        metrics: $metrics,
                        filesystemReserveBytes: $config->storageTempReserveBytes,
                        tempBudget: $tempBudget,
                        stagePool: $stagePool,
                        budgetPool: $budgetPool,
                    );
                    $runtime->attachAwsObjectWriter($awsTierWriter);
                    $collector = $awsStorage->createObjectWriteIntentCollector(
                        $metadata,
                        $awsTier->name,
                        $logger,
                        $metrics,
                    );
                    $backgroundWriter = new AwsS3FencedBackgroundObjectWriter(
                        $awsTierWriter,
                        $awsStorage,
                        $awsTier->name,
                    );
                    $tierRuntimes[] = new AwsS3TierRuntimeBinding(
                        $awsTier->name,
                        $awsStorage,
                        $metadata,
                        $awsOperationalState,
                        $awsTierWriter,
                        $backgroundWriter,
                        $collector,
                    );
                    if ($awsTier->name === $storageTiers->defaultTier()->name) {
                        $awsObjectWriter = $awsTierWriter;
                        $server->setAwsAccessLogWriter($awsTierWriter);
                    }
                }
                $awsDefaultRuntimeTier = $awsObjectWriter !== null
                    ? $storageTiers->defaultTier()->name
                    : $tierRuntimes[0]->tier;
                $server->setAwsTierRuntimeRegistry(new AwsS3TierRuntimeRegistry(
                    $tierRuntimes,
                    $awsDefaultRuntimeTier,
                    $metadata,
                ));
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
                localPartWriter: $localPartWriter,
                awsObjectWriter: $awsObjectWriter,
                tempBudget: $server->storageTempBudgetCoordinator(),
            );

            if ($config->clusterEnabled()) {
                if ($config->allowUnfencedStorageForTests) {
                    throw new \RuntimeException('Cluster mode rejects unsafe storage test overrides.');
                }
                $clusterStore = MetadataDriverStartupGuard::clusterPostgresStore(
                    $rawMetadata,
                    $config->metadataDriver,
                    $config->metadataDsn,
                );
                // Structural storage validation must complete before the first
                // node can create the immutable PostgreSQL admission singleton.
                StorageWriteFenceStartupGuard::assertReady(
                    $storageTiers,
                    false,
                    $config->metadataDriver,
                    $metadata,
                );
                if (! $credentialProvider instanceof ClusterAuthenticationIdentityProvider) {
                    throw new \RuntimeException('Cluster mode requires one PostgreSQL/MySQL database credential store with a stable identity.');
                }
                $externalAuthenticationIdentity = $adminCredentialApi?->clusterAuthenticationIdentity();
                $fingerprintFactory = function () use (
                    $clusterStore,
                    $config,
                    $storageTiers,
                    $encryption,
                    $credentialProvider,
                    $externalAuthenticationIdentity,
                ): ClusterCapabilityFingerprint {
                    $clusterStore->assertCurrentSchema();
                    $databaseIdentity = $clusterStore->clusterDatabaseIdentity();
                    if ($config->clusterDatabaseId === null
                        || ! hash_equals($config->clusterDatabaseId, $databaseIdentity)) {
                        throw new \RuntimeException('Configured cluster database identity does not match the migrated PostgreSQL metadata database.');
                    }

                    return ClusterCapabilityFingerprint::fromRuntime(
                        $config,
                        $storageTiers,
                        $encryption,
                        $clusterStore->clusterDatabaseSchema(),
                        $databaseIdentity,
                        $credentialProvider,
                        $externalAuthenticationIdentity,
                    );
                };
                $fingerprint = $fingerprintFactory();
                $clusterAdmission = new ClusterAdmissionController(
                    $clusterStore,
                    $fingerprint,
                    fingerprintFactory: $fingerprintFactory,
                );
                $clusterAdmission->admitInitial();
                $server->setClusterAdmissionController($clusterAdmission);
                $server->setClusterWorkFencing(
                    $clusterStore,
                    ClusterWorkerIdentity::fromConfig($config),
                );
                $server->setClusterRoleController(new ClusterRoleController($clusterStore, $config));
            }

            return $runtime;
        } catch (\Throwable $e) {
            if ($runtime !== null) {
                $cleanupComplete = false;
                for ($attempt = 1; $attempt <= 2; ++$attempt) {
                    try {
                        $runtime->stop();
                        $cleanupComplete = true;
                        break;
                    } catch (\Throwable $cleanupError) {
                        self::logCleanupFailure($logger, 'Runtime factory shutdown failed.', $cleanupError);
                    }
                }
                if (! $cleanupComplete) {
                    $localStorageRootLocks->retainUntilProcessExit();
                }
            } else {
                $cleanupComplete = true;
                try {
                    $server->stop();
                } catch (\Throwable $cleanupError) {
                    $cleanupComplete = false;
                    self::logCleanupFailure($logger, 'Runtime factory server cleanup failed.', $cleanupError);
                }

                if ($encryption !== null && method_exists($encryption, 'shutdown')) {
                    try {
                        $encryption->shutdown();
                    } catch (\Throwable $cleanupError) {
                        $cleanupComplete = false;
                        self::logCleanupFailure($logger, 'Runtime factory encryption cleanup failed.', $cleanupError);
                    }
                }
                if ($cleanupComplete) {
                    $localStorageRootLocks->release();
                } else {
                    $localStorageRootLocks->retainUntilProcessExit();
                }
            }

            if ($cleanupComplete) {
                self::rollbackAwsOperationalAttachments($attachedAws, $logger);
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
     * @param list<array{AwsS3StorageBackend, AwsS3OperationalState, string, object}> $attachments
     */
    private static function rollbackAwsOperationalAttachments(array $attachments, LoggerInterface $logger): void
    {
        foreach (array_reverse($attachments) as [$storage, $state, $tier, $metadata]) {
            if ($storage->operationalState() !== $state || $storage->operationalAttachmentCommitted()) {
                continue;
            }
            try {
                $storage->rollbackOperationalStateAttachment($state, $tier, $metadata);
            } catch (\Throwable $cleanupError) {
                self::logCleanupFailure($logger, 'Runtime factory AWS state rollback failed.', $cleanupError);
            }
        }
    }

    /**
     * @return array{
     *     providerProbe: AwsS3ProviderWorkerProbe,
     *     probePool: ContextWorkerPool,
     *     readExecutor: ParallelAwsS3ReadExecutor,
     *     readPool: ContextWorkerPool,
     *     stagePool: ContextWorkerPool,
     *     budgetPool: ContextWorkerPool,
     *     tempBudget: StorageTempBudgetCoordinator
     * }
     */
    private function awsSharedResources(
        S3Server $server,
        S3ServerConfig $config,
        MetricsCollector $metrics,
        \OpsFour\S3Server\Storage\AwsS3ProviderProfile $profile,
    ): array {
        $probePool = new ContextWorkerPool(1);
        $server->addWorkerPool($probePool, 'aws_provider_probe', 1, forceKillOnShutdown: true);
        $readPool = new ContextWorkerPool($config->awsReadWorkerPoolSize);
        $server->addWorkerPool(
            $readPool,
            'aws_payload_reads',
            $config->awsReadWorkerPoolSize,
            forceKillOnShutdown: true,
        );
        $stagePool = new ContextWorkerPool(2);
        $server->addWorkerPool($stagePool, 'aws_payload_staging', 2, forceKillOnShutdown: true);
        $budgetPool = new ContextWorkerPool(2);
        $server->addWorkerPool($budgetPool, 'aws_temp_budget', 2, forceKillOnShutdown: true);

        return [
            'providerProbe' => new AwsS3ProviderWorkerProbe(
                $probePool,
                $profile->requestTimeoutSeconds * 2 + 5.0,
            ),
            'probePool' => $probePool,
            'readExecutor' => new ParallelAwsS3ReadExecutor(
                $readPool,
                $config->awsReadWorkerPoolSize,
                $config->awsReadWaiterLimit,
                $config->awsReadAdmissionTimeoutSeconds,
                $config->awsReadFirstByteTimeoutSeconds,
                $metrics,
            ),
            'readPool' => $readPool,
            'stagePool' => $stagePool,
            'budgetPool' => $budgetPool,
            'tempBudget' => $server->storageTempBudgetCoordinator(),
        ];
    }

    /**
     * @return array{LocalFencedObjectWriter, LocalObjectWriteLeaseGuardFactory, DedicatedAmpObjectWriteLeaseRenewer|null, LocalFencedMultipartPartWriter}
     */
    private function localObjectWriteWiring(
        S3ServerConfig $config,
        MetadataStore $rawMetadata,
        MetadataStore $metadata,
        StorageTierRegistry $tiers,
        string $scratch,
        bool $faultProfile,
    ): array {
        $backend = $this->localFilesystemBackend($tiers)
            ?? throw new \RuntimeException('Local object write fencing requires an existing filesystem default tier.');
        if (! $metadata instanceof ObjectWriteIntentStore
            || ! $metadata instanceof FencedMultipartPartWriteIntentStore) {
            throw new \RuntimeException('Local filesystem writes require durable object and multipart-part metadata intents.');
        }

        $renewer = null;
        $writer = null;
        $partWriter = null;
        $tempBudget = new StorageTempBudgetCoordinator(
            $config->storageTempDir,
            $config->storageTempBudgetBytes,
            $config->storageTempReserveBytes,
        );
        try {
            $writer = new LocalFencedObjectWriter(
                $backend->getBasePath(),
                $scratch,
                $tiers->defaultTier()->name,
                budgetBytes: $config->storageTempBudgetBytes,
                workerIo: $faultProfile && getenv('S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK') === '1'
                    ? new LocalObjectTestPublishPauseIo($scratch)
                    : null,
                filesystemReserveBytes: $config->storageTempReserveBytes,
                tempBudget: $tempBudget,
                workerTimeoutSeconds: $config->localWriteWorkerTimeoutSeconds,
            );
            $partWriter = new LocalFencedMultipartPartWriter(
                $backend->getBasePath(),
                $scratch,
                $tiers->defaultTier()->name,
                $metadata,
                budgetBytes: $config->storageTempBudgetBytes,
                filesystemReserveBytes: $config->storageTempReserveBytes,
                tempBudget: $tempBudget,
                workerTimeoutSeconds: $config->localWriteWorkerTimeoutSeconds,
            );

            if (in_array($config->metadataDriver, ['postgres', 'mysql'], true)) {
                $rawSqlStore = MetadataDriverStartupGuard::fencedSqlStore(
                    $rawMetadata,
                    $config->metadataDriver,
                    $config->metadataDsn,
                );
                $renewer = $config->metadataDriver === 'postgres'
                    ? DedicatedAmpObjectWriteLeaseRenewer::forPostgres($config->metadataDsn)
                    : DedicatedAmpObjectWriteLeaseRenewer::forMysql($config->metadataDsn);

                return [
                    $writer,
                    new AmpLocalObjectWriteLeaseGuardFactory(
                        new AmpLocalObjectWriteLeaseSql($rawSqlStore),
                        $renewer,
                        new LocalObjectWriteLeaseAdmission(2, waitForCapacity: true),
                    ),
                    $renewer,
                    $partWriter,
                ];
            }

            if ($config->metadataDriver !== 'sqlite') {
                throw new \RuntimeException('Unsupported metadata driver for local object write fencing.');
            }
            $adapter = new MetadataLocalObjectWriteLeaseAdapter($metadata);

            return [
                $writer,
                new MetadataLocalObjectWriteLeaseGuardFactory(
                    $adapter,
                    // SQLite's deferred write transactions can repeatedly
                    // lose snapshot promotion when two intent admissions run
                    // together. Serialize this short lease transaction while
                    // retaining the bounded request queue in the admission.
                    new LocalObjectWriteLeaseAdmission(1, waitForCapacity: true),
                ),
                null,
                $partWriter,
            ];
        } catch (\Throwable $error) {
            $partWriter?->shutdown();
            $writer?->shutdown();
            $renewer?->close();
            throw $error;
        }
    }

    private function localFilesystemBackend(StorageTierRegistry $tiers): ?FilesystemBackend
    {
        $backend = $tiers->defaultBackend();
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend instanceof FilesystemBackend && realpath($backend->getBasePath()) !== false
            ? $backend
            : null;
    }

    /** @return list<array{StorageTier, AwsS3StorageBackend}> */
    private function admittedAwsTiers(StorageTierRegistry $tiers): array
    {
        $bindings = [];
        foreach ($tiers->all() as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if ($backend instanceof AwsS3StorageBackend
                && $backend->awsS3ProviderAdmission()->providerAdmitted) {
                $bindings[] = [$tier, $backend];
            }
        }
        return $bindings;
    }

    private function productionScratchPath(StorageTierRegistry $tiers): string
    {
        $backend = $this->localFilesystemBackend($tiers)
            ?? throw new \LogicException('Local filesystem scratch requested for another backend.');
        $root = realpath($backend->getBasePath());
        if ($root === false) {
            throw new \RuntimeException('Local filesystem root does not exist.');
        }
        $temp = $root . '/.tmp';
        $scratch = $temp . '/object-writes';
        if ((is_link($temp) || is_link($scratch))
            || (! is_dir($scratch) && ! @mkdir($scratch, 0700, true) && ! is_dir($scratch))) {
            throw new \RuntimeException('Cannot create the private local object write scratch directory.');
        }
        if (realpath($scratch) !== $scratch) {
            throw new \RuntimeException('Local object write scratch must not traverse symbolic links.');
        }

        return $scratch;
    }

    private function awsObjectScratchPath(): string
    {
        $root = realpath(sys_get_temp_dir());
        if ($root === false) {
            throw new \RuntimeException('AWS object staging temporary root is unavailable.');
        }
        $scratch = $root . '/opsfour-aws-object-writes';
        if (is_link($scratch) || (! is_dir($scratch) && ! @mkdir($scratch, 0700) && ! is_dir($scratch))
            || realpath($scratch) !== $scratch) {
            throw new \RuntimeException('Cannot create private AWS object staging scratch.');
        }
        return $scratch;
    }

    private function testScratchPath(S3ServerConfig $config): string
    {
        $scratchPath = $config->localObjectWriteTestScratchPath;
        if ($scratchPath === null || ! $config->allowUnfencedStorageForTests) {
            throw new \LogicException('Local object write fault profile was not explicitly enabled.');
        }
        $scratch = realpath($scratchPath);
        $tempRoot = realpath(sys_get_temp_dir());
        if ($scratch === false || $tempRoot === false || ! str_starts_with($scratch, rtrim($tempRoot, '/') . '/')) {
            throw new \RuntimeException('Local object write fault scratch must be an existing directory below the process temporary directory.');
        }

        return $scratch;
    }

    private function encryptionFromEnvironment(
        S3ServerConfig $config,
        MetricsCollector $metrics,
    ): EncryptionServiceInterface {
        $masterKeyProvider = $this->masterKeyProvider($config);

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
        if ($masterKey === false && $masterKeys === false) {
            return null;
        }

        return new ConfigMasterKeyProvider();
    }
}
