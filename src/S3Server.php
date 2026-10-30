<?php

declare(strict_types=1);

namespace OpsFour\S3Server;

use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\ClusterAuthenticationIdentityProvider;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartFenceAdmissionStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutCapability;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\BufferedReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\BucketOwnershipMiddleware;
use OpsFour\S3Server\Middleware\ChecksumValidationMiddleware;
use OpsFour\S3Server\Middleware\ClusterAdmissionMiddleware;
use OpsFour\S3Server\Middleware\ClusterRoleAdmissionMiddleware;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\CorsMiddleware;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\DrainAdmissionMiddleware;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Middleware\ExpectContinueMiddleware;
use OpsFour\S3Server\Middleware\LoggingMiddleware;
use OpsFour\S3Server\Middleware\ManagementBoundaryMiddleware;
use OpsFour\S3Server\Middleware\MetricsMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Middleware\RateLimitMiddleware;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\Middleware\RequestHeaderContractMiddleware;
use OpsFour\S3Server\Middleware\RequestIdMiddleware;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Notification\NotificationProcessor;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3DispatchHandler;
use OpsFour\S3Server\Routing\S3Router;
use OpsFour\S3Server\Runtime\MetadataDriverStartupGuard;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use OpsFour\S3Server\Runtime\ClusterWorkerIdentity;
use OpsFour\S3Server\Runtime\LocalStorageRootLocks;
use OpsFour\S3Server\Runtime\NodeDrainController;
use OpsFour\S3Server\Runtime\PostgresApiDriverGuard;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Runtime\AwsS3ProviderStartupException;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Contracts\CredentialProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main S3-compatible server.
 *
 * Composes an Amp HTTP server with S3-specific middleware, routing,
 * and handler dispatch. Designed for both standalone use (via bin/s3-server)
 * and embedded use within a larger PHP application.
 */
final class S3Server
{
    /** @return list<array{id: string, capability: string}> */
    public static function storageMutatorRegistrations(): array
    {
        return [
            ['id' => 'worker.access-log', 'capability' => StorageMutatorInventory::ATTEMPT],
            ['id' => 'worker.tier-transition', 'capability' => StorageMutatorInventory::ATTEMPT],
            ['id' => 'worker.restore-copy', 'capability' => StorageMutatorInventory::ATTEMPT],
            ['id' => 'worker.restore-expiry', 'capability' => StorageMutatorInventory::GARBAGE],
            ['id' => 'worker.storage-garbage', 'capability' => StorageMutatorInventory::GARBAGE],
            ['id' => 'worker.multipart-part-recovery', 'capability' => StorageMutatorInventory::UNSUPPORTED],
            ['id' => 'worker.multipart-completion-recovery', 'capability' => StorageMutatorInventory::UNSUPPORTED],
            ['id' => 'worker.lifecycle-multipart-abort', 'capability' => StorageMutatorInventory::UNSUPPORTED],
            ['id' => 'integration.generic-storage-mutation', 'capability' => StorageMutatorInventory::UNSUPPORTED],
            ['id' => 'integration.flysystem-worker-mutation', 'capability' => StorageMutatorInventory::UNSUPPORTED],
        ];
    }

    private ?SocketHttpServer $server = null;

    private bool $shutdown = false;

    private bool $shutdownComplete = false;

    private bool $stopInProgress = false;

    /**
     * Amp's HTTP stop future is retained after a drain timeout so a later stop retry can finish cleanup safely.
     *
     * @var Future<void>|null
     */
    private ?Future $httpStopFuture = null;

    private bool $httpDrainTimedOut = false;

    /** @var array<int, true> Successfully closed storage instances retained by this server. */
    private array $closedStorageBackends = [];

    /** @var array<int, true> Successfully closed registered worker pools. */
    private array $closedWorkerPools = [];

    /** @var array<int, true> Pools requiring forced termination on the next stop attempt. */
    private array $failedWorkerPoolShutdowns = [];

    /** @var array<int, true> Terminated pools awaiting final owner bookkeeping. */
    private array $terminatedWorkerPools = [];

    private readonly HandlerRegistry $handlerRegistry;

    private readonly LoggerInterface $logger;

    private ?LifecycleRunner $lifecycleRunner = null;

    private ?NotificationProcessor $notificationProcessor = null;

    private ?NotificationDispatcher $notifications = null;

    private readonly MetricsCollector $metrics;

    private ?AccessLogWriter $accessLogs;

    private ?EncryptionServiceInterface $encryption = null;

    private ?RequestHandler $adminCredentialApiHandler = null;

    private ?RequestHandler $adminQuotaApiHandler = null;

    private bool $cleanupRunning = false;

    private bool $tieringRunning = false;

    private bool $localObjectCollectionRunning = false;

    /** Latches an unexpected assigned durable-role loop exit until process shutdown. */
    private bool $durableRoleLoopFailed = false;

    private ?LocalObjectWriteIntentCollector $localObjectIntentCollector = null;

    private ?AwsS3ObjectWriteIntentCollector $awsObjectIntentCollector = null;

    private ?LocalFencedObjectWriter $localObjectWriter = null;

    private ?LocalObjectWriteLeaseGuardFactory $localObjectLeaseFactory = null;

    private ?LocalFencedBackgroundObjectWriter $localBackgroundWriter = null;

    private ?AwsS3FencedBackgroundObjectWriter $awsBackgroundWriter = null;

    private ?AwsS3ForegroundObjectWriter $awsAccessLogWriter = null;

    private ?AwsS3OperationalState $awsOperationalState = null;

    private ?DeferredCancellation $backgroundCancellation = null;

    private ?PostObjectSpoolCoordinator $postObjectSpoolCoordinator = null;

    private readonly StorageTempBudgetCoordinator $tempBudget;

    private ?WorkerPool $requestBodySpoolPool = null;

    /** @var list<Future<void>> */
    private array $backgroundFutures = [];

    private ?StorageTierRegistry $storageTiers = null;

    private ?ClusterAdmissionController $clusterAdmission = null;

    private ?ClusterRoleController $clusterRoles = null;

    private readonly NodeDrainController $drain;

    private ?ClusterWorkFencingStore $clusterWorkFencing = null;

    private ?ClusterWorkerIdentity $clusterWorkerIdentity = null;

    /** @var list<array{pool: WorkerPool, name: string, forceKillOnShutdown: bool}> Worker pools to shut down on stop. */
    private array $workerPools = [];

    /** @var list<Middleware> Additional middleware to insert into the stack. */
    private array $extraMiddleware = [];

    private bool $authenticationConfigured = false;

    private ?CredentialProvider $authenticationCredentialProvider = null;

    private bool $starting = false;

    private readonly DeferredCancellation $startupCancellation;

    private readonly LocalStorageRootLocks $localStorageRootLocks;

    private bool $ownsLocalStorageRootLockRelease = true;

    /**
     * @param  S3ServerConfig  $config  Server configuration.
     * @param  MetadataStore|null  $metadata  Metadata store for middleware wiring (CORS, policy, ACL enforcement).
     * @param  StorageBackend|null  $storage  Storage backend for website hosting middleware.
     * @param  LoggerInterface|null  $logger  PSR-3 logger instance.
     */
    public function __construct(
        private readonly S3ServerConfig $config,
        private readonly ?MetadataStore $metadata = null,
        private readonly ?StorageBackend $storage = null,
        ?LoggerInterface $logger = null,
        ?MetricsCollector $metrics = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger ?? new NullLogger());
        $this->startupCancellation = new DeferredCancellation();
        $this->handlerRegistry = new HandlerRegistry();
        $this->metrics = $metrics ?? new MetricsCollector();
        $this->drain = new NodeDrainController();
        $this->localStorageRootLocks = LocalStorageRootLocks::create();
        $this->tempBudget = new StorageTempBudgetCoordinator(
            $config->storageTempDir,
            $config->storageTempBudgetBytes,
            $config->storageTempReserveBytes,
        );
        $this->bindStorageTempBudget($storage);
        $this->accessLogs = $metadata !== null && $storage !== null
            ? new AccessLogWriter(
                $metadata,
                $storage,
                $this->logger,
                maxEncryptedObjectSize: $config->maxEncryptedObjectSize,
                quotas: QuotaManager::fromGlobalConfig($metadata, $config->quota),
                localFencedWriter: $this->localObjectWriter,
                localLeaseFactory: $this->localObjectLeaseFactory,
            )
            : null;
    }

    public function getMetricsCollector(): MetricsCollector
    {
        return $this->metrics;
    }

    /**
     * Start the HTTP server and begin accepting requests.
     *
     * This method creates the Amp SocketHttpServer, wires the full middleware
     * stack and router, exposes on the configured host:port, and starts
     * serving. It returns immediately -- the event loop keeps the server alive.
     */
    public function start(): void
    {
        if ($this->shutdown) {
            throw new \LogicException('S3 server cannot be restarted after shutdown.');
        }

        if ($this->server !== null) {
            throw new \LogicException('S3 server is already started.');
        }

        if ($this->starting) {
            throw new \LogicException('S3 server startup is already in progress.');
        }

        if (! $this->drain->acceptsNewWork()) {
            throw new \LogicException('S3 server cannot start after drain begins.');
        }

        if (! $this->authenticationConfigured) {
            throw new \LogicException('Refusing to start without authentication middleware.');
        }

        if ($this->config->clusterEnabled() && $this->clusterAdmission === null) {
            throw new \LogicException('Refusing to start cluster mode without an admitted cluster controller.');
        }
        if ($this->config->clusterEnabled()) {
            $this->clusterAdmission?->assertBoundToConfig($this->config);
        }
        if ($this->config->clusterEnabled()
            && ! $this->authenticationCredentialProvider instanceof ClusterAuthenticationIdentityProvider) {
            throw new \LogicException('Refusing to start cluster mode without an authentication credential provider that exposes an explicit cluster identity.');
        }
        if ($this->config->clusterEnabled()
            && ($this->clusterWorkFencing === null || $this->clusterWorkerIdentity === null)) {
            throw new \LogicException('Refusing to start cluster mode without PostgreSQL work fencing.');
        }
        if ($this->config->clusterEnabled() && $this->clusterRoles === null) {
            throw new \LogicException('Refusing to start cluster mode without PostgreSQL role membership.');
        }
        if ($this->config->clusterEnabled()) {
            $this->clusterRoles?->assertBoundToConfig($this->config);
            $this->clusterRoles?->assertStore(MetadataDriverStartupGuard::clusterPostgresStore(
                $this->metadata,
                $this->config->metadataDriver,
                $this->config->metadataDsn,
            ));
        }
        if ($this->config->clusterEnabled()
            && ! $this->config->hasClusterRole(ClusterRoleSet::API)
            && $this->config->metricsBearerToken === null) {
            throw new \LogicException('Worker-only cluster processes require an authenticated metrics bearer token.');
        }

        if ($this->config->perClientRateLimit > 0 && $this->metadata === null) {
            throw new \RuntimeException(
                'Refusing to start with rate limiting enabled but no metadata store; set perClientRateLimit to 0 to disable rate limiting.',
            );
        }

        if (! extension_loaded('openssl')) {
            throw new \RuntimeException('S3 API nodes require ext-openssl for encrypted request spools.');
        }

        MetadataDriverStartupGuard::assertMatches($this->metadata, $this->config->metadataDriver);
        PostgresApiDriverGuard::assertAvailable($this->config);
        MetadataDriverStartupGuard::assertSchemaCurrent($this->metadata);

        // Freeze before metadata or logger callbacks can yield to another
        // fiber and change the handlers after the storage preflight.
        $registryFreezeToken = $this->handlerRegistry->freeze();
        $this->starting = true;
        try {
            $directStorage = $this->storage;
            $configuredTiers = $this->storageTiers
                ?? ($directStorage !== null ? StorageTierRegistry::single($directStorage) : null);
            $startupTierRegistries = $configuredTiers !== null ? [$configuredTiers] : [];
            if ($directStorage !== null && ($configuredTiers === null || $directStorage !== $configuredTiers->defaultBackend())) {
                $startupTierRegistries[] = StorageTierRegistry::single($directStorage);
            }
            $this->assertClusterHandlerStorageBindings($configuredTiers);
            foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
                $startupTierRegistries[] = $registration['tiers'];
                if ($registration['storage'] !== $registration['tiers']->defaultBackend()) {
                    $startupTierRegistries[] = StorageTierRegistry::single($registration['storage']);
                }
            }
            $this->assertAwsOperationalGraph($startupTierRegistries);
            if ($configuredTiers !== null) {
                $unsafeTiers = StorageWriteFenceStartupGuard::assertReady(
                    $configuredTiers,
                    $this->config->allowUnfencedStorageForTests,
                    $this->config->metadataDriver,
                    $this->metadata,
                );
                if ($this->storage !== null && $this->storage !== $configuredTiers->defaultBackend()) {
                    // Directly constructed servers may retain a separate storage
                    // backend even after a tier registry is assigned.
                    $unsafeTiers = array_merge($unsafeTiers, StorageWriteFenceStartupGuard::assertReady(
                        StorageTierRegistry::single($this->storage),
                        $this->config->allowUnfencedStorageForTests,
                        $this->config->metadataDriver,
                        $this->metadata,
                    ));
                }
                if ($unsafeTiers !== []) {
                    $this->logger->warning('UNSAFE TEST-ONLY storage override active; storage durability is not guaranteed.', [
                        'tiers' => $unsafeTiers,
                    ]);
                }
            }

            foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
                $unsafeTiers = StorageWriteFenceStartupGuard::assertReady(
                    $registration['tiers'],
                    $this->config->allowUnfencedStorageForTests,
                    $this->config->metadataDriver,
                    $this->metadata,
                );
                if ($registration['storage'] !== $registration['tiers']->defaultBackend()) {
                    $unsafeTiers = array_merge($unsafeTiers, StorageWriteFenceStartupGuard::assertReady(
                        StorageTierRegistry::single($registration['storage']),
                        $this->config->allowUnfencedStorageForTests,
                        $this->config->metadataDriver,
                        $this->metadata,
                    ));
                }
                if ($unsafeTiers !== []) {
                    $this->logger->warning('UNSAFE TEST-ONLY handler storage override active; storage durability is not guaranteed.', [
                        'tiers' => $unsafeTiers,
                    ]);
                }
            }

            if ($this->metadata !== null) {
                $tierRegistries = $configuredTiers !== null ? [$configuredTiers] : [];
                foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
                    $tierRegistries[] = $registration['tiers'];
                }

                if ($tierRegistries !== []) {
                    $referencedTiers = $this->metadata->listReferencedStorageTiers();
                    $missingTiers = [];
                    foreach ($tierRegistries as $tierRegistry) {
                        foreach ($referencedTiers as $name) {
                            if (! $tierRegistry->has($name)) {
                                $missingTiers[$name] = true;
                            }
                        }
                    }
                    if ($missingTiers !== []) {
                        throw new \RuntimeException('Refusing to start with unconfigured storage tiers referenced by metadata: ' . implode(', ', array_keys($missingTiers)));
                    }
                }
            }

            if ($this->localObjectWriter !== null) {
                if ($this->metadata === null) {
                    throw new \RuntimeException('Local object write fencing requires metadata.');
                }
                if (! $this->metadata instanceof ObjectWriteIntentStore) {
                    throw new \RuntimeException('Local object write fencing requires durable object-write metadata intents.');
                }
                if (in_array($this->config->metadataDriver, ['postgres', 'mysql'], true)) {
                    MetadataDriverStartupGuard::assertRawFencedSqlStore(
                        $this->metadata,
                        $this->config->metadataDriver,
                        $this->config->metadataDsn,
                    );
                }
                if (! $this->localObjectIntentCollector instanceof LocalObjectWriteIntentCollector) {
                    throw new \RuntimeException('Local object write fencing requires its intent collector.');
                }
                $this->localObjectIntentCollector->assertBoundTo($this->metadata, $this->localObjectWriter);
                if ($configuredTiers === null
                    || $configuredTiers->defaultTier()->name !== $this->localObjectWriter->storageTierName()
                    || $this->storage !== $configuredTiers->defaultBackend()) {
                    throw new \RuntimeException('Local object write fencing must match the default filesystem tier.');
                }
                $fencedBackend = $configuredTiers->defaultBackend();
                while ($fencedBackend instanceof ObservedStorageBackend) {
                    $fencedBackend = $fencedBackend->innerBackend();
                }
                if (! $fencedBackend instanceof FilesystemBackend
                    || realpath($fencedBackend->getBasePath()) !== $this->localObjectWriter->storageRoot()) {
                    throw new \RuntimeException('Local object write fencing filesystem root differs from its writer.');
                }
                foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
                    if ($registration['storage'] !== $this->storage
                        || $registration['tiers']->defaultBackend() !== $this->storage
                        || $registration['tiers']->defaultTier()->name !== $this->localObjectWriter->storageTierName()) {
                        throw new \RuntimeException('Local object write fencing handlers must use the same fenced filesystem tier.');
                    }
                }
                if (! $this->metadata instanceof LocalMultipartFenceAdmissionStore
                    || ! $this->metadata instanceof FencedMultipartPartWriteIntentStore
                    || ! $this->metadata instanceof LocalMultipartCompletionIntentStore) {
                    throw new \RuntimeException('Local object write fencing requires multipart legacy-intent admission.');
                }
                $this->metadata->assertNoLegacyLocalMultipartIntents($configuredTiers->defaultTier()->name);
            }

            if ($this->metadata !== null) {
                ObjectPartLayoutCapability::require($this->metadata);
            }

            // Storage admission above is side-effect free. Take exclusive
            // ownership after every storage preflight, but before TLS setup,
            // worker creation, or listener binding.
            $this->localStorageRootLocks->acquireBackends($this->registeredStorageBackends());

            // Validate TLS files before creating the request-body worker pool or
            // assigning an HTTP server. A failed preflight must leave start clean.
            if ($this->config->isTlsEnabled()) {
                $certPath = $this->config->tlsCertPath;
                $keyPath = $this->config->tlsKeyPath;
                \assert($certPath !== null && $keyPath !== null);
                if (! is_file($certPath) || ! is_readable($certPath)) {
                    throw new \InvalidArgumentException("TLS certificate is not readable: {$certPath}");
                }
                if (! is_file($keyPath) || ! is_readable($keyPath)) {
                    throw new \InvalidArgumentException("TLS private key is not readable: {$keyPath}");
                }
            }

            $this->logger->info('Starting S3 server on {host}:{port}', [
                'host' => $this->config->host,
                'port' => $this->config->port,
            ]);

            // 1. Create the Amp HTTP server for direct access.
            // Use a custom HttpDriverFactory to set body size limit from config
            // (default 5GB for S3-compatible uploads, vs Amp's default 128KB).
            $httpDriverFactory = new DefaultHttpDriverFactory(
                logger: $this->logger,
                bodySizeLimit: $this->config->requestBodySizeLimit,
                connectionTimeout: self::timeoutValue($this->config->connectionIdleTimeout),
                // Amp's default factory passes streamTimeout to Http1Driver as its
                // connection timeout. HTTP/1 therefore uses this single inactivity
                // value for keep-alive, request reads, and response writes. The
                // separate connectionTimeout parameter is used by HTTP/2.
                streamTimeout: self::timeoutValue(max($this->config->readTimeout, $this->config->writeTimeout)),
            );

            /** @var int<1, max> $connectionLimit */
            $connectionLimit = $this->config->maxConcurrentConnections;

            // Amp rejects new remote connections above this per-IP limit. Loopback
            // clients are exempt; the total connection limit still applies to them.
            /** @var int<1, max> $perIpLimit */
            $perIpLimit = min($connectionLimit, 200);

            if ($this->startupCancellation->isCancelled()) {
                throw new \LogicException('S3 server shutdown during startup.');
            }

            $startupPoolCount = count($this->workerPools);
            try {
                $this->server = SocketHttpServer::createForDirectAccess(
                    logger: $this->logger,
                    connectionLimit: $connectionLimit,
                    connectionLimitPerIp: $perIpLimit,
                    concurrencyLimit: $connectionLimit,
                    allowedMethods: null, // Allow all HTTP methods -- S3 uses GET, PUT, POST, DELETE, HEAD.
                    enableCompression: false, // S3 responses are typically binary; compression is counterproductive.
                    httpDriverFactory: $httpDriverFactory,
                );

                // 2. Build the S3 dispatch handler (innermost layer).
                // Reads s3.operation attribute (set by S3AttributeMiddleware) and dispatches.
                $httpSurfaces = $this->runtimeHttpSurfaces();
                $stack = $this->roleAwareS3Fallback(
                    new S3DispatchHandler(handlerRegistry: $this->handlerRegistry),
                );

                // 4. Wire Amp Router for reserved management paths. All other
                // paths use the S3 fallback so arbitrary HTTP methods reach the
                // S3 operation resolver instead of Amp's generic 405 response.
                $errorHandler = new DefaultErrorHandler();
                $router = new Router($this->server, $this->logger, $errorHandler);

                // Probe endpoints bypass client rate limits so aggressive probes or a
                // degraded metadata backend cannot hide the process/dependency state.
                $router->addRoute('GET', '/.live', $this->managementHandler(
                    new HealthCheckHandler(logger: $this->logger),
                    rateLimited: false,
                    bypassForwardedForValidation: true,
                ));
                $router->addRoute('GET', '/.health', $this->managementHandler(
                    new HealthCheckHandler(
                        metadata: $this->metadata,
                        logger: $this->logger,
                        rateLimitEnabled: $this->config->perClientRateLimit > 0,
                        storageTiers: $this->storageTiers ?? (
                            $this->storage !== null ? StorageTierRegistry::single($this->storage) : null
                        ),
                        credentialHealthProbe: $this->authenticationCredentialProvider instanceof CredentialHealthProbe
                            ? $this->authenticationCredentialProvider
                            : null,
                        awsOperationalState: $this->awsOperationalState,
                        clusterAdmission: $this->clusterAdmission,
                        clusterRoles: $this->clusterRoles,
                        drain: $this->drain,
                        tempBudget: $this->tempBudget,
                    ),
                    rateLimited: false,
                    bypassForwardedForValidation: true,
                ));
                $router->addRoute('GET', '/.metrics', $this->managementHandler(new MetricsHandler(
                    $this->metrics,
                    $this->metadata,
                    $this->config->metricsBearerToken,
                    $this->awsOperationalState,
                    $this->tempBudget,
                    $this->clusterRoles,
                ), rateLimited: false));
                if (in_array('admin', $httpSurfaces, true) && $this->adminCredentialApiHandler !== null) {
                    $adminCredentials = $this->managementHandler(
                        $this->adminCredentialApiHandler,
                        clusterGated: true,
                        drainGated: true,
                    );
                    $router->addRoute('POST', '/.admin/credentials', $adminCredentials);
                    $router->addRoute('DELETE', '/.admin/credentials/{accessKeyId}', $adminCredentials);
                }
                if (in_array('admin', $httpSurfaces, true) && $this->adminQuotaApiHandler !== null) {
                    $adminQuotas = $this->managementHandler(
                        $this->adminQuotaApiHandler,
                        clusterGated: true,
                        drainGated: true,
                    );
                    $router->addRoute('GET', '/.admin/quotas', $adminQuotas);
                    $router->addRoute('GET', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                    $router->addRoute('PUT', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                    $router->addRoute('DELETE', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                }

                // Service, bucket, and object requests all use this fallback.
                $router->setFallback($stack);

                // Prepare every background dependency before opening the
                // listener. Their constructors are inert; timers and fibers
                // are activated only after HTTP startup succeeds.
                $background = $this->prepareBackgroundComponents();
                $preparedLifecycleRunner = $background['lifecycleRunner'];
                $preparedNotificationProcessor = $background['notificationProcessor'];
                $objectCollectors = $background['objectCollectors'];
                $transitionExecutor = $background['transitionExecutor'];
                $restoreExecutor = $background['restoreExecutor'];
                $restoreGc = $background['restoreGarbageCollector'];
                $storageGc = $background['storageGarbageCollector'];
                $multipartIntentGc = $background['multipartIntentCollector'];
                $preparedBackgroundCancellation = new DeferredCancellation();
                $this->logger->debug('Background resources prepared.');
                if ($this->startupCancellation->isCancelled()) {
                    throw new \LogicException('S3 server shutdown during startup.');
                }

                // 5. Expose on the configured host:port, with TLS when configured.
                $bindContext = (new BindContext())->withTcpNoDelay();
                if ($this->config->isTlsEnabled()) {
                    $certPath = $this->config->tlsCertPath;
                    $keyPath = $this->config->tlsKeyPath;
                    \assert($certPath !== null && $keyPath !== null);
                    $tlsContext = (new ServerTlsContext())
                        ->withMinimumVersion(ServerTlsContext::TLSv1_2)
                        ->withDefaultCertificate(new Certificate($certPath, $keyPath));
                    $bindContext = $bindContext->withTlsContext($tlsContext);
                }
                $this->server->expose(
                    $this->config->host . ':' . $this->config->port,
                    $bindContext,
                );

                // 6. Start the server.
                if ($this->startupCancellation->isCancelled()) {
                    throw new \LogicException('S3 server shutdown during startup.');
                }
                // Framing validation must wrap the router itself: management
                // routes and router-generated 405 responses share the same
                // listener and therefore the same desynchronization boundary.
                $globalHandler = Middleware\stackMiddleware(
                    $router,
                    new RequestIdMiddleware(),
                    new ErrorHandlingMiddleware($this->logger),
                    new RequestHeaderContractMiddleware(),
                    new ManagementBoundaryMiddleware(
                        $this->adminCredentialApiHandler !== null,
                        $this->adminQuotaApiHandler !== null,
                        $this->logger,
                    ),
                );
                $this->server->start($globalHandler, $errorHandler);
                if ($this->startupCancellation->isCancelled()) {
                    throw new \LogicException('S3 server shutdown during startup.');
                }
            } catch (\Throwable $e) {
                // A failed HTTP stop may leave requests using workers and storage.
                // Keep the server and every dependency owned for a later stop retry.
                try {
                    $this->server?->stop();
                } catch (\Throwable $cleanupError) {
                    $this->logShutdown('warning', 'Startup HTTP server cleanup failed.', ['exception' => $cleanupError::class]);
                    $this->shutdown = true;
                    $this->startupCancellation->cancel();
                    $this->abortIncompleteStartup();
                }

                $this->server = null;
                $poolCleanupFailed = false;
                // Do not detach a pool until every new pool has stopped. A
                // failed pool remains available to stop() for a safe retry.
                foreach (array_slice($this->workerPools, $startupPoolCount) as $entry) {
                    $id = spl_object_id($entry['pool']);
                    try {
                        if ($entry['forceKillOnShutdown']) {
                            $entry['pool']->kill();
                        } else {
                            $entry['pool']->shutdown();
                        }
                        if ($entry['pool']->isRunning()) {
                            throw new \RuntimeException('Worker pool remained running after shutdown.');
                        }
                        $this->closedWorkerPools[$id] = true;
                    } catch (\Throwable $cleanupError) {
                        $this->failedWorkerPoolShutdowns[$id] = true;
                        $poolCleanupFailed = true;
                        $this->metrics->recordWorkerPoolShutdownFailure($entry['name'], $cleanupError::class);
                        $this->logShutdown('warning', 'Startup worker pool cleanup failed.', [
                            'pool' => $entry['name'],
                            'exception' => $cleanupError::class,
                        ]);
                    }
                }
                if ($poolCleanupFailed) {
                    $this->shutdown = true;
                    $this->startupCancellation->cancel();
                    $this->abortIncompleteStartup();
                }

                foreach (array_slice($this->workerPools, $startupPoolCount) as $entry) {
                    $id = spl_object_id($entry['pool']);
                    unset(
                        $this->closedWorkerPools[$id],
                        $this->failedWorkerPoolShutdowns[$id],
                        $this->terminatedWorkerPools[$id],
                    );
                }
                array_splice($this->workerPools, $startupPoolCount);
                throw $e;
            }
        } catch (\Throwable $error) {
            $this->starting = false;
            $this->clusterRoles?->withdraw();
            $this->awsOperationalState?->invalidateStartupValidation();
            if (! $this->shutdown) {
                $this->handlerRegistry->rollbackFreeze($registryFreezeToken);
                if ($this->ownsLocalStorageRootLockRelease) {
                    $this->localStorageRootLocks->release();
                }
            } elseif ($this->ownsLocalStorageRootLockRelease) {
                $this->localStorageRootLocks->retainUntilProcessExit();
            }
            throw $error;
        }
        $this->starting = false;

        // A failure in any background service must not leave the HTTP listener
        // running after start() reports an error.
        try {
            // 7. Activate the resources prepared before listener binding.
            if ($preparedLifecycleRunner !== null) {
                $this->lifecycleRunner = $preparedLifecycleRunner;
                $preparedLifecycleRunner->start();
                $this->logger->info('Lifecycle runner started.');
            }

            // 8. Start notification queue processor.
            if ($preparedNotificationProcessor !== null) {
                $this->notificationProcessor = $preparedNotificationProcessor;
                $this->notificationProcessor->start();
                $this->logger->info('Notification processor started.');
            }

            // 9. Start background cleanup fiber for rate limit + notification queue.
            $this->backgroundCancellation = $preparedBackgroundCancellation;
            $backgroundCancellation = $this->backgroundCancellation->getCancellation();
            if ($this->clusterRoles !== null) {
                $this->backgroundFutures[] = \Amp\async(function () use ($backgroundCancellation): void {
                    try {
                        while (! $backgroundCancellation->isRequested()) {
                            \Amp\delay(5.0, cancellation: $backgroundCancellation);
                            $backgroundCancellation->throwIfRequested();
                            if ($this->durableRoleLoopFailed
                                || ($this->config->hasClusterRole(ClusterRoleSet::LIFECYCLE)
                                    && ! ($this->lifecycleRunner?->isRunning() ?? false))
                                || ($this->config->hasClusterRole(ClusterRoleSet::WEBHOOK)
                                    && ! ($this->notificationProcessor?->isRunning() ?? false))) {
                                $this->clusterRoles?->withdraw();
                                continue;
                            }
                            if (! $this->backgroundWorkAdmitted()) {
                                continue;
                            }
                            $this->clusterRoles?->maintainReady();
                        }
                    } catch (\Amp\CancelledException) {
                        // Normal shutdown; the row is withdrawn or expires by database time.
                    }
                });
            }
            $spoolCoordinator = $this->postObjectSpoolCoordinator;
            if ($this->metadata !== null || $spoolCoordinator !== null) {
                $metadata = $this->metadata;
                $this->cleanupRunning = true;
                $this->backgroundFutures[] = \Amp\async(function () use ($backgroundCancellation, $metadata, $spoolCoordinator): void {
                    try {
                        while ($this->cleanupRunning) {
                            // Every 5 minutes (reduced from 60s to minimize DB contention).
                            \Amp\delay(300, cancellation: $backgroundCancellation);
                            $backgroundCancellation->throwIfRequested();

                            if ($spoolCoordinator !== null && $this->config->hasClusterRole(ClusterRoleSet::API)) {
                                try {
                                    $spoolCoordinator->recoverAbandoned();
                                } catch (\Throwable $e) {
                                    $this->logger->warning('Background cleanup failed.', [
                                        'component' => 'post_object_spool_recovery',
                                        'exception' => $e::class,
                                    ]);
                                }
                            }
                            if ($metadata !== null) {
                                try {
                                    if ($this->roleWorkAdmitted(ClusterRoleSet::API)) {
                                        $metadata->rateLimitCleanup(3600);
                                    }
                                    if ($this->roleWorkAdmitted(ClusterRoleSet::WEBHOOK)) {
                                        $metadata->cleanupOldNotifications(86400);
                                    }
                                    if ($this->config->hasClusterRole(ClusterRoleSet::API)) {
                                        $this->flushAccessLogsIfAdmitted();
                                    }
                                } catch (\Throwable $e) {
                                    $this->logger->warning('Background cleanup failed.', [
                                        'component' => 'metadata_cleanup',
                                        'exception' => $e::class,
                                    ]);
                                }
                            }
                        }
                    } catch (\Amp\CancelledException) {
                        // Normal shutdown.
                    }
                });
            }

            if ($objectCollectors !== []) {
                $this->localObjectCollectionRunning = true;
                // Keep attempt recovery independent of Lifecycle,
                // Restore and other garbage I/O, which may block for longer
                // than one object's claim lease.
                foreach ($objectCollectors as $collector) {
                    $this->backgroundFutures[] = \Amp\async(function () use ($collector, $backgroundCancellation): void {
                        try {
                            while ($this->localObjectCollectionRunning) {
                                try {
                                    if ($this->clusterAdmission === null) {
                                        $collector->collect(100, $backgroundCancellation);
                                        \Amp\delay(min(5.0, $this->config->lifecycleIntervalSeconds), cancellation: $backgroundCancellation);
                                        continue;
                                    }
                                    if (! $this->roleWorkAdmitted(ClusterRoleSet::RECONCILE)) {
                                        \Amp\delay(min(5.0, $this->config->lifecycleIntervalSeconds), cancellation: $backgroundCancellation);
                                        continue;
                                    }
                                    for ($claim = 0; $claim < 100; ++$claim) {
                                        if (! $this->roleWorkAdmitted(ClusterRoleSet::RECONCILE)) {
                                            break;
                                        }
                                        if ($collector->collect(1, $backgroundCancellation) === 0) {
                                            break;
                                        }
                                    }
                                } catch (\Amp\CancelledException $error) {
                                    throw $error;
                                } catch (\Throwable $error) {
                                    $this->logger->warning('Object intent collection failed.', [
                                        'component' => 'object_intent_reconciler',
                                        'exception' => $error::class,
                                    ]);
                                }
                                \Amp\delay(min(5.0, $this->config->lifecycleIntervalSeconds), cancellation: $backgroundCancellation);
                            }
                        } catch (\Amp\CancelledException) {
                            // Normal shutdown; unresolved attempts remain in SQL.
                        } finally {
                            $this->handleAssignedRoleLoopExit('object_intent_reconciler', $backgroundCancellation);
                        }
                    });
                }
            }

            // 10. Start only the physical worker roles assigned to this process.
            if ($transitionExecutor !== null || $restoreExecutor !== null || $restoreGc !== null
                || $storageGc !== null || $multipartIntentGc !== null) {
                $this->tieringRunning = true;
                $this->backgroundFutures[] = \Amp\async(function () use (
                    $transitionExecutor,
                    $restoreExecutor,
                    $restoreGc,
                    $storageGc,
                    $multipartIntentGc,
                    $backgroundCancellation,
                ): void {
                    try {
                        while ($this->tieringRunning) {
                            try {
                                if ($this->clusterAdmission === null) {
                                    \assert($transitionExecutor !== null);
                                    \assert($restoreExecutor !== null);
                                    \assert($restoreGc !== null);
                                    \assert($storageGc !== null);
                                    $transitionStats = $transitionExecutor->processNext(
                                        $this->config->lifecycleBatchSize,
                                        $backgroundCancellation,
                                    );
                                    $restoreStats = $restoreExecutor->processNext(
                                        $this->config->lifecycleBatchSize,
                                        $backgroundCancellation,
                                    );
                                    $gcStats = $restoreGc->collect($this->config->lifecycleBatchSize);
                                    $storageGc->collect($this->config->lifecycleBatchSize, $backgroundCancellation);
                                    $multipartIntentGc?->collect($this->config->lifecycleBatchSize, $backgroundCancellation);
                                } else {
                                    $transitionStats = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'deadLetter' => 0];
                                    $restoreStats = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'deadLetter' => 0];
                                    $gcStats = ['scanned' => 0, 'expired' => 0, 'failed' => 0];
                                    if ($transitionExecutor !== null) {
                                        for ($claim = 0; $claim < $this->config->lifecycleBatchSize; ++$claim) {
                                            if (! $this->roleWorkAdmitted(ClusterRoleSet::TRANSITION)) {
                                                break;
                                            }
                                            $result = $transitionExecutor->processNext(1, $backgroundCancellation);
                                            foreach ($result as $name => $count) {
                                                $transitionStats[$name] += $count;
                                            }
                                            if ($result['processed'] === 0) {
                                                break;
                                            }
                                        }
                                    }
                                    if ($restoreExecutor !== null) {
                                        for ($claim = 0; $claim < $this->config->lifecycleBatchSize; ++$claim) {
                                            if (! $this->roleWorkAdmitted(ClusterRoleSet::RESTORE)) {
                                                break;
                                            }
                                            $result = $restoreExecutor->processNext(1, $backgroundCancellation);
                                            foreach ($result as $name => $count) {
                                                $restoreStats[$name] += $count;
                                            }
                                            if ($result['processed'] === 0) {
                                                break;
                                            }
                                        }
                                    }
                                    if ($restoreGc !== null && $this->roleWorkAdmitted(ClusterRoleSet::GARBAGE)) {
                                        $gcStats = $restoreGc->collect(
                                            $this->config->lifecycleBatchSize,
                                            beforeEach: fn(): bool => $this->roleWorkAdmitted(ClusterRoleSet::GARBAGE),
                                        );
                                    }
                                    if ($storageGc !== null) {
                                        for ($claim = 0; $claim < $this->config->lifecycleBatchSize; ++$claim) {
                                            if (! $this->roleWorkAdmitted(ClusterRoleSet::GARBAGE)
                                                || $storageGc->collect(1, $backgroundCancellation) === 0) {
                                                break;
                                            }
                                        }
                                    }
                                    if ($multipartIntentGc !== null) {
                                        for ($claim = 0; $claim < $this->config->lifecycleBatchSize; ++$claim) {
                                            if (! $this->roleWorkAdmitted(ClusterRoleSet::RECONCILE)
                                                || $multipartIntentGc->collect(1, $backgroundCancellation) === 0) {
                                                break;
                                            }
                                        }
                                    }
                                }

                                $this->recordTieringWorkerStats('transition', $transitionStats);
                                $this->recordTieringWorkerStats('restore', $restoreStats);
                                $this->recordTieringWorkerStats('restore_gc', $gcStats);

                                if ($transitionStats['processed'] > 0 || $restoreStats['processed'] > 0 || $gcStats['scanned'] > 0) {
                                    $this->logger->debug('Tiering background tick completed.', [
                                        'transition' => $transitionStats,
                                        'restore' => $restoreStats,
                                        'restore_gc' => $gcStats,
                                    ]);
                                }
                            } catch (\Throwable $e) {
                                $this->logger->warning('Tiering background processing error.', ['exception' => $e::class]);
                            }

                            \Amp\delay(
                                $this->config->lifecycleIntervalSeconds,
                                cancellation: $backgroundCancellation,
                            );
                        }
                    } catch (\Amp\CancelledException) {
                        // Normal shutdown.
                    } finally {
                        $this->handleAssignedRoleLoopExit('physical_role_worker', $backgroundCancellation);
                    }
                });
                $this->logger->info('Assigned physical worker roles started.');
            }

            $this->lifecycleRunner?->assertRunning();
            if ($this->notificationProcessor !== null && ! $this->notificationProcessor->isRunning()) {
                throw new \RuntimeException('Notification processor exited before role membership became ready.');
            }
            if ($this->durableRoleLoopFailed) {
                throw new \RuntimeException('Assigned durable role loop exited before role membership became ready.');
            }
            if (! $this->backgroundWorkAdmitted()) {
                throw new \RuntimeException('Cluster admission changed before role membership became ready.');
            }
            $this->clusterRoles?->registerReady();

            $this->commitAwsOperationalAttachments();
            $this->logger->info('S3 server listening on {host}:{port}', [
                'host' => $this->config->host,
                'port' => $this->config->port,
            ]);
        } catch (\Throwable $error) {
            $this->awsOperationalState?->invalidateStartupValidation();
            try {
                $this->stop();
            } catch (\Throwable $cleanupError) {
                try {
                    $this->logger->warning('Startup cleanup failed.', ['exception' => $cleanupError::class]);
                } catch (\Throwable) {
                    // Preserve the original startup failure even if the logger
                    // itself is the dependency that failed during cleanup.
                }
            }
            throw $error;
        }
    }

    public function setNotificationDispatcher(NotificationDispatcher $notifications): void
    {
        $this->assertConfigurationMutable('Notification dispatcher');
        $this->notifications = $notifications;
        $this->accessLogs?->setNotificationDispatcher($notifications);
    }

    public function setStorageTierRegistry(StorageTierRegistry $storageTiers): void
    {
        $this->assertConfigurationMutable('Storage tier registry');
        foreach ($storageTiers->all() as $tier) {
            $this->bindStorageTempBudget($tier->backend);
        }
        $this->storageTiers = $storageTiers;
        if ($this->metadata !== null && $this->storage !== null) {
            $this->accessLogs = new AccessLogWriter(
                $this->metadata,
                $storageTiers->defaultBackend(),
                $this->logger,
                $storageTiers->defaultTier()->name,
                $this->encryption,
                $this->config->maxEncryptedObjectSize,
                QuotaManager::fromGlobalConfig($this->metadata, $this->config->quota),
                $this->notifications,
                $this->localObjectWriter,
                $this->localObjectLeaseFactory,
                awsWriter: $this->awsAccessLogWriter,
            );
        }
    }

    /** Attach one tier-scoped collector for the local object write fencing. */
    public function setLocalObjectWriteIntentCollector(LocalObjectWriteIntentCollector $collector): void
    {
        $this->assertConfigurationMutable('Local object intent collector');
        if ($this->localObjectIntentCollector !== null) {
            throw new \LogicException('Local object intent collector is already configured.');
        }
        $this->localObjectIntentCollector = $collector;
        if ($this->clusterWorkFencing !== null && $this->clusterWorkerIdentity !== null) {
            $collector->enableClusterFencing($this->clusterWorkFencing, $this->clusterWorkerIdentity->forKind('object-local'));
        }
    }

    public function setAwsObjectWriteIntentCollector(AwsS3ObjectWriteIntentCollector $collector): void
    {
        $this->assertConfigurationMutable('AWS object intent collector');
        if ($this->awsObjectIntentCollector !== null) {
            throw new \LogicException('AWS object intent collector is already configured.');
        }
        $this->awsObjectIntentCollector = $collector;
        if ($this->clusterWorkFencing !== null && $this->clusterWorkerIdentity !== null) {
            $collector->enableClusterFencing($this->clusterWorkFencing, $this->clusterWorkerIdentity->forKind('object-aws'));
        }
    }

    public function setAwsOperationalState(AwsS3OperationalState $state): void
    {
        $this->assertConfigurationMutable('AWS operational state');
        if ($this->awsOperationalState !== null) {
            throw new \LogicException('AWS operational state is already configured.');
        }
        $this->awsOperationalState = $state;
    }

    public function setAwsBackgroundObjectWriter(AwsS3FencedBackgroundObjectWriter $writer): void
    {
        $this->assertConfigurationMutable('AWS background object writer');
        if ($this->awsBackgroundWriter !== null) {
            throw new \LogicException('AWS background object writer is already configured.');
        }
        $this->awsBackgroundWriter = $writer;
    }

    public function setAwsAccessLogWriter(AwsS3ForegroundObjectWriter $writer): void
    {
        $this->assertConfigurationMutable('AWS access log writer');
        if ($this->awsAccessLogWriter !== null) {
            throw new \LogicException('AWS access log writer is already configured.');
        }
        $this->awsAccessLogWriter = $writer;
        $this->setEncryptionService($this->encryption);
    }

    /** Attach the local object write fencing to internal access-log delivery. */
    public function setLocalObjectWriter(
        LocalFencedObjectWriter $writer,
        LocalObjectWriteLeaseGuardFactory $leaseFactory,
    ): void {
        $this->assertConfigurationMutable('Local object write writer');
        if ($this->localObjectWriter !== null || $this->metadata === null || $this->storage === null) {
            throw new \LogicException('Local object write writer is already attached or server dependencies are incomplete.');
        }
        $this->localObjectWriter = $writer;
        $this->localObjectLeaseFactory = $leaseFactory;
        $this->localBackgroundWriter = new LocalFencedBackgroundObjectWriter($writer, $leaseFactory);
        $this->setEncryptionService($this->encryption);
    }

    public function setEncryptionService(?EncryptionServiceInterface $encryption): void
    {
        $this->assertConfigurationMutable('Encryption service');
        $this->encryption = $encryption;
        if ($this->metadata !== null && $this->storage !== null) {
            $tiers = $this->storageTiers;
            $this->accessLogs = new AccessLogWriter(
                $this->metadata,
                $tiers?->defaultBackend() ?? $this->storage,
                $this->logger,
                $tiers?->defaultTier()->name ?? 'STANDARD',
                $encryption,
                $this->config->maxEncryptedObjectSize,
                QuotaManager::fromGlobalConfig($this->metadata, $this->config->quota),
                $this->notifications,
                $this->localObjectWriter,
                $this->localObjectLeaseFactory,
                awsWriter: $this->awsAccessLogWriter,
            );
        }
    }

    public function setClusterAdmissionController(ClusterAdmissionController $admission): void
    {
        $this->assertConfigurationMutable('Cluster admission');
        if ($this->config->clusterEnabled()) {
            $admission->assertBoundToConfig($this->config);
        }
        $this->clusterAdmission = $admission;
    }

    public function setClusterRoleController(ClusterRoleController $roles): void
    {
        $this->assertConfigurationMutable('Cluster role membership');
        if (! $this->config->clusterEnabled()) {
            throw new \LogicException('Cluster role membership requires enabled cluster mode.');
        }
        if ($this->clusterRoles !== null) {
            throw new \LogicException('Cluster role membership is already configured.');
        }
        $roles->assertBoundToConfig($this->config);
        $roles->assertStore(MetadataDriverStartupGuard::clusterPostgresStore(
            $this->metadata,
            $this->config->metadataDriver,
            $this->config->metadataDsn,
        ));
        $this->clusterRoles = $roles;
    }

    public function setClusterWorkFencing(ClusterWorkFencingStore $fencing, ClusterWorkerIdentity $identity): void
    {
        $this->assertConfigurationMutable('Cluster work fencing');
        if (! $this->config->clusterEnabled()) {
            throw new \LogicException('Cluster work fencing requires enabled cluster mode.');
        }
        if ($this->clusterWorkFencing !== null || $this->clusterWorkerIdentity !== null) {
            throw new \LogicException('Cluster work fencing is already configured.');
        }
        $resolved = MetadataDriverStartupGuard::clusterPostgresStore(
            $this->metadata,
            $this->config->metadataDriver,
            $this->config->metadataDsn,
        );
        if ($fencing !== $resolved) {
            throw new \LogicException('Cluster work fencing must use this server\'s exact configured PostgreSQL metadata store.');
        }
        $identity->assertBoundToConfig($this->config);
        $this->localObjectIntentCollector?->enableClusterFencing($fencing, $identity->forKind('object-local'));
        $this->awsObjectIntentCollector?->enableClusterFencing($fencing, $identity->forKind('object-aws'));
        $this->clusterWorkFencing = $fencing;
        $this->clusterWorkerIdentity = $identity;
    }

    public function setAdminCredentialApiHandler(RequestHandler $handler): void
    {
        $this->assertConfigurationMutable('Admin credential handler');
        $this->adminCredentialApiHandler = $handler;
    }

    public function setAdminQuotaApiHandler(RequestHandler $handler): void
    {
        $this->assertConfigurationMutable('Admin quota handler');
        $this->adminQuotaApiHandler = $handler;
    }

    /**
     * Gracefully stop the server, waiting for in-flight requests to drain.
     *
     * A drain timeout fails the stop attempt while retaining every dependency
     * that an in-flight request may still use. Calling stop() again completes
     * cleanup once the retained HTTP stop future has finished.
     */
    public function stop(): void
    {
        if ($this->shutdownComplete) {
            return;
        }
        if ($this->stopInProgress) {
            throw new \LogicException('S3 server shutdown is already in progress.');
        }
        $this->stopInProgress = true;
        try {
            $this->beginDrain();
            $this->stopOwnedResources();
            $this->shutdownComplete = true;
        } finally {
            $this->stopInProgress = false;
        }
    }

    /** Make readiness and request admission close before transport shutdown. */
    public function beginDrain(): void
    {
        if (! $this->drain->beginDrain()) {
            return;
        }

        try {
            $this->clusterRoles?->beginDrain();
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Cluster membership demotion failed during drain.', [
                'exception' => $e::class,
            ]);
        } finally {
            $this->drain->startPropagationWindow();
        }
    }

    /** @internal Runtime shutdown waits this phase while probes remain reachable. */
    public function awaitReadinessPropagation(): void
    {
        if ($this->server === null) {
            return;
        }
        $remaining = $this->drain->remainingPropagationDelay($this->config->shutdownReadinessDelay);
        if ($remaining <= 0.0) {
            return;
        }
        $this->logShutdown('info', 'Waiting {delay}s for drain readiness to propagate...', [
            'delay' => round($remaining, 3),
        ]);
        \Amp\delay($remaining);
    }

    private function stopOwnedResources(): void
    {
        $this->shutdown = true;
        $this->startupCancellation->cancel();

        $timeout = $this->config->shutdownDrainTimeout;
        if ($this->server !== null) {
            $this->logShutdown('info', 'Stopping S3 server (drain timeout: {timeout}s)...', [
                'timeout' => $timeout,
            ]);
        }

        $this->cleanupRunning = false;
        $this->tieringRunning = false;
        $this->localObjectCollectionRunning = false;
        $this->clusterRoles?->withdraw();
        $this->backgroundCancellation?->cancel();
        // Stop accepting new connections and drain in-flight requests before
        // shutting down worker pools or metadata services they may still use.
        if ($this->server !== null) {
            if ($this->httpStopFuture === null) {
                $server = $this->server;
                $this->httpStopFuture = \Amp\async(static function () use ($server): void {
                    $server->stop();
                });
                $this->httpStopFuture->ignore();
            }

            if ($this->httpDrainTimedOut && ! $this->httpStopFuture->isComplete()) {
                $this->abortIncompleteShutdown();
            }

            try {
                $this->httpStopFuture->await(new \Amp\TimeoutCancellation($timeout));
            } catch (\Amp\CancelledException) {
                $this->httpDrainTimedOut = true;
                $this->logShutdown('warning', 'Shutdown drain timeout exceeded ({timeout}s); active-request dependencies remain owned.', [
                    'timeout' => $timeout,
                ]);
                // Amp has closed all listening sockets, but an active request
                // can still be using storage or metadata. Return control to the
                // supervisor without tearing those dependencies down. A later
                // stop() retry observes the retained future and can finish.
                $this->abortIncompleteShutdown();
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'HTTP server shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
            $this->httpStopFuture = null;
            $this->httpDrainTimedOut = false;
            $this->server = null;
        }
        $this->drain->stop();

        try {
            $this->notificationProcessor?->stop($timeout);
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Notification processor shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        $this->notificationProcessor = null;
        try {
            $this->lifecycleRunner?->stop($timeout);
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Lifecycle runner shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        $this->lifecycleRunner = null;

        $this->awaitBackgroundFutures($timeout);
        try {
            $this->localObjectIntentCollector?->shutdown();
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Local object intent collector shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        $this->localObjectIntentCollector = null;
        try {
            $this->awsObjectIntentCollector?->shutdown();
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'AWS object intent collector shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        $this->awsObjectIntentCollector = null;
        try {
            $this->flushAccessLogsIfAdmitted();
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Access log flush failed during shutdown.', ['exception' => $e::class]);
        }
        try {
            $this->notifications?->shutdown($timeout);
        } catch (\Throwable $e) {
            $this->logShutdown('warning', 'Notification shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        $this->notifications = null;

        // A runtime that never completed startup must release its exact
        // provisional attachment so the backend can be adopted elsewhere.
        $this->rollbackUncommittedAwsOperationalAttachments();

        $shutdownStorage = [];
        $storageBackends = [];
        foreach ($this->storageTiers?->all() ?? [] as $tier) {
            $storageBackends[] = $tier->backend;
        }
        if ($this->storage !== null) {
            $storageBackends[] = $this->storage;
        }
        foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
            foreach ($registration['tiers']->all() as $tier) {
                $storageBackends[] = $tier->backend;
            }
            $storageBackends[] = $registration['storage'];
        }
        foreach ($storageBackends as $backend) {
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof ShutdownAwareStorageBackend) {
                continue;
            }
            $id = spl_object_id($backend);
            if (isset($shutdownStorage[$id])) {
                continue;
            }
            $shutdownStorage[$id] = true;
            if (isset($this->closedStorageBackends[$id])) {
                continue;
            }
            try {
                $backend->shutdown();
                $this->closedStorageBackends[$id] = true;
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Storage worker shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
        }

        $this->shutdownWorkerPools();
        $this->postObjectSpoolCoordinator?->close();
        $this->postObjectSpoolCoordinator = null;
        $this->tempBudget->close();

        // Shut down ParallelSqliteMetadataStore if applicable, even when wrapped by decorators.
        $parallelSqlite = $this->parallelSqliteMetadataStore($this->metadata);
        if ($parallelSqlite !== null) {
            try {
                $parallelSqlite->shutdown();
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Metadata store shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
        }

        $this->server = null;
        $this->backgroundCancellation = null;
        $this->backgroundFutures = [];
        if ($this->ownsLocalStorageRootLockRelease) {
            $this->localStorageRootLocks->release();
        }
        $this->logShutdown('info', 'S3 server stopped.');
    }

    /**
     * Let the encompassing runtime release root ownership after its additional
     * writer and encryption resources have stopped.
     */
    public function transferLocalStorageRootLockOwnership(): LocalStorageRootLocks
    {
        $this->assertConfigurationMutable('Local storage root lock ownership');
        if (! $this->ownsLocalStorageRootLockRelease) {
            throw new \LogicException('Local storage root lock ownership was already transferred.');
        }

        $this->ownsLocalStorageRootLockRelease = false;

        return $this->localStorageRootLocks;
    }

    /** @return iterable<StorageBackend> */
    private function registeredStorageBackends(): iterable
    {
        foreach ($this->storageTiers?->all() ?? [] as $tier) {
            yield $tier->backend;
        }
        if ($this->storage !== null) {
            yield $this->storage;
        }
        foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
            foreach ($registration['tiers']->all() as $tier) {
                yield $tier->backend;
            }
            yield $registration['storage'];
        }
    }

    /** @param list<StorageTierRegistry> $registries */
    private function assertAwsOperationalGraph(array $registries): void
    {
        $aws = $this->awsOperationalRegistrations($registries);

        if ($aws === []) {
            $hasAwsProbePool = array_any(
                $this->workerPools,
                static fn(array $entry): bool => $entry['name'] === 'aws_provider_probe',
            );
            if ($this->awsOperationalState !== null || $this->awsObjectIntentCollector !== null
                || $this->awsBackgroundWriter !== null || $this->awsAccessLogWriter !== null || $hasAwsProbePool) {
                throw new \LogicException('AWS runtime components are configured without an exposed AWS backend.');
            }
            return;
        }
        if ($this->awsOperationalState === null || $this->metadata === null) {
            $backend = array_values($aws)[0]['backend'];
            throw new AwsS3ProviderStartupException($backend->awsS3ProviderAdmission());
        }
        if (count($aws) !== 1) {
            throw new \LogicException('The qualified AWS runtime requires one exact exposed backend graph.');
        }
        foreach ($aws as $registration) {
            $backend = $registration['backend'];
            if ($backend->operationalState() !== $this->awsOperationalState) {
                throw new AwsS3ProviderStartupException($backend->awsS3ProviderAdmission());
            }
            $binding = $backend->validatedBinding()
                ?? throw new AwsS3ProviderStartupException($backend->awsS3ProviderAdmission());
            $this->awsOperationalState->assertQualifiedExecutionBinding(
                $binding,
                $registration['tier'],
                $this->metadata,
            );
            $profile = $binding->profile
                ?? throw new \LogicException('Exposed AWS backend lost its admitted profile.');
            if (! $this->metadata instanceof ObjectWriteIntentStore
                || $this->awsBackgroundWriter?->isBoundToGraph(
                    $backend,
                    $profile,
                    $this->metadata,
                    $registration['tier'],
                    $this->awsOperationalState,
                ) !== true) {
                throw new \LogicException('The AWS background writer does not match the exposed graph.');
            }
            if ($this->awsObjectIntentCollector?->isBoundTo(
                $this->metadata,
                $registration['tier'],
                $this->awsOperationalState,
                $binding,
            ) !== true) {
                throw new \LogicException('The AWS object-intent collector does not match the exposed graph.');
            }
            $defaultTier = $this->storageTiers?->defaultTier();
            $defaultBackend = $defaultTier?->backend;
            while ($defaultBackend instanceof ObservedStorageBackend) {
                $defaultBackend = $defaultBackend->innerBackend();
            }
            $implicitDefaultBackend = $this->storageTiers === null ? $this->storage : null;
            while ($implicitDefaultBackend instanceof ObservedStorageBackend) {
                $implicitDefaultBackend = $implicitDefaultBackend->innerBackend();
            }
            $isPrimaryDefaultAwsTier = ($defaultTier !== null
                    && $defaultBackend === $backend
                    && $defaultTier->name === $registration['tier'])
                || ($defaultTier === null && $implicitDefaultBackend === $backend);
            $foregroundWriter = $this->awsBackgroundWriter->foregroundWriter();
            if ($isPrimaryDefaultAwsTier) {
                $accessLogWriter = $this->awsAccessLogWriter
                    ?? throw new \LogicException('The default AWS tier requires its exact foreground/access-log writer.');
                if ($accessLogWriter !== $foregroundWriter
                    || ! $accessLogWriter->isBoundToOperationalGraph(
                        $backend,
                        $profile,
                        $this->metadata,
                        $this->awsOperationalState,
                    )
                    || ! hash_equals($accessLogWriter->storageTierName(), $registration['tier'])
                    || $this->accessLogs?->usesAwsS3ForegroundObjectWriter($accessLogWriter) !== true) {
                    throw new \LogicException('The AWS foreground and access-log writers do not match the exposed graph.');
                }
            }
            $isHandlerDefaultAwsTier = false;
            foreach ($this->handlerRegistry->storageRegistrations() as $handlerRegistration) {
                $handlerDefault = $handlerRegistration['tiers']->defaultTier();
                $handlerBackend = $handlerDefault->backend;
                while ($handlerBackend instanceof ObservedStorageBackend) {
                    $handlerBackend = $handlerBackend->innerBackend();
                }
                if ($handlerBackend === $backend && $handlerDefault->name === $registration['tier']) {
                    $isHandlerDefaultAwsTier = true;
                    break;
                }
            }
            if ($isPrimaryDefaultAwsTier || $isHandlerDefaultAwsTier) {
                $this->handlerRegistry->assertAwsS3ForegroundWriterCoverage($foregroundWriter);
            }
            $boundProbePools = array_filter(
                $this->workerPools,
                fn(array $entry): bool => $this->awsOperationalState->usesProviderProbePool($entry['pool']),
            );
            $probeEntry = array_values($boundProbePools)[0] ?? null;
            if (count($boundProbePools) !== 1 || $probeEntry === null
                || $probeEntry['name'] !== 'aws_provider_probe'
                || ! $probeEntry['forceKillOnShutdown']) {
                throw new \LogicException('The AWS provider probe worker is not exactly owned by this runtime.');
            }
        }
    }

    /**
     * @param list<StorageTierRegistry> $registries
     * @return array<int, array{backend: AwsS3StorageBackend, tier: string}>
     */
    private function awsOperationalRegistrations(array $registries): array
    {
        $aws = [];
        foreach ($registries as $registry) {
            foreach ($registry->all() as $tier) {
                $backend = $tier->backend;
                while ($backend instanceof ObservedStorageBackend) {
                    $backend = $backend->innerBackend();
                }
                if (! $backend instanceof AwsS3StorageBackend) {
                    continue;
                }
                $id = spl_object_id($backend);
                if (isset($aws[$id]) && ! hash_equals($aws[$id]['tier'], $tier->name)) {
                    throw new \LogicException('One AWS backend cannot be registered under multiple operational tiers.');
                }
                $aws[$id] = ['backend' => $backend, 'tier' => $tier->name];
            }
        }

        return $aws;
    }

    private function commitAwsOperationalAttachments(): void
    {
        if ($this->awsOperationalState === null || $this->metadata === null) {
            return;
        }
        foreach ($this->awsOperationalRegistrations($this->configuredTierRegistries()) as $registration) {
            $registration['backend']->commitOperationalStateAttachment(
                $this->awsOperationalState,
                $registration['tier'],
                $this->metadata,
            );
        }
    }

    private function rollbackUncommittedAwsOperationalAttachments(): void
    {
        if ($this->awsOperationalState === null || $this->metadata === null) {
            return;
        }
        foreach ($this->awsOperationalRegistrations($this->configuredTierRegistries()) as $registration) {
            if ($registration['backend']->operationalAttachmentCommitted()) {
                continue;
            }
            $registration['backend']->rollbackOperationalStateAttachment(
                $this->awsOperationalState,
                $registration['tier'],
                $this->metadata,
            );
        }
    }

    /** @return list<StorageTierRegistry> */
    private function configuredTierRegistries(): array
    {
        $registries = $this->storageTiers !== null ? [$this->storageTiers] : [];
        if ($this->storage !== null
            && ($this->storageTiers === null || $this->storage !== $this->storageTiers->defaultBackend())) {
            $registries[] = StorageTierRegistry::single($this->storage);
        }
        foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
            $registries[] = $registration['tiers'];
            if ($registration['storage'] !== $registration['tiers']->defaultBackend()) {
                $registries[] = StorageTierRegistry::single($registration['storage']);
            }
        }

        return $registries;
    }

    public function __destruct()
    {
        if ($this->ownsLocalStorageRootLockRelease) {
            $this->localStorageRootLocks->retainUntilProcessExit();
        }
    }

    private function shutdownWorkerPools(): void
    {
        foreach ($this->workerPools as $entry) {
            $id = spl_object_id($entry['pool']);
            if (isset($this->closedWorkerPools[$id])) {
                continue;
            }
            try {
                if (! isset($this->terminatedWorkerPools[$id])) {
                    if ($entry['forceKillOnShutdown'] || isset($this->failedWorkerPoolShutdowns[$id])) {
                        $entry['pool']->kill();
                    } else {
                        $entry['pool']->shutdown();
                    }
                    if ($entry['pool']->isRunning()) {
                        throw new \RuntimeException('Worker pool remained running after shutdown.');
                    }
                    $this->terminatedWorkerPools[$id] = true;
                }
                $this->closedWorkerPools[$id] = true;
                unset($this->failedWorkerPoolShutdowns[$id], $this->terminatedWorkerPools[$id]);
            } catch (\Throwable $e) {
                if (! isset($this->terminatedWorkerPools[$id])) {
                    $this->failedWorkerPoolShutdowns[$id] = true;
                }
                $this->metrics->recordWorkerPoolShutdownFailure($entry['name'], $e::class);
                $this->logShutdown('warning', 'Worker pool shutdown failed.', ['pool' => $entry['name'], 'exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
        }
    }

    private function awaitBackgroundFutures(int $timeout): void
    {
        if ($this->backgroundFutures === []) {
            return;
        }

        $cancellation = new \Amp\TimeoutCancellation(max(1, $timeout));
        foreach ($this->backgroundFutures as $future) {
            try {
                $future->await($cancellation);
            } catch (\Amp\CancelledException) {
                $this->logShutdown('warning', 'Background task did not stop within {timeout}s; dependencies remain owned.', ['timeout' => $timeout]);
                $this->abortIncompleteShutdown();
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Background task shutdown failed.', ['exception' => $e::class]);
            }
        }
    }

    private function flushAccessLogsIfAdmitted(): void
    {
        $accessLogs = $this->accessLogs;
        if ($accessLogs === null) {
            return;
        }
        if ($this->config->clusterEnabled()) {
            if (! $this->roleWorkAdmitted(ClusterRoleSet::API)
                || ! ($this->clusterRoles?->hasLiveRole(ClusterRoleSet::RECONCILE) ?? false)) {
                return;
            }
        } elseif (! $this->backgroundWorkAdmitted()) {
            return;
        }
        $accessLogs->flush();
    }

    /** @param array<string, mixed> $context */
    private function logShutdown(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // A failing logger must not leave sockets or worker pools running.
        }
    }

    private function abortIncompleteShutdown(): never
    {
        // Do not put an inner exception or its message in the public error
        // chain: storage and worker failures may contain credentials.
        throw new \RuntimeException('S3 server shutdown incomplete; dependencies remain owned.');
    }

    private function abortIncompleteStartup(): never
    {
        throw new \RuntimeException('S3 server startup cleanup incomplete; dependencies remain owned.');
    }

    private static function timeoutValue(int $seconds): int
    {
        // Amp treats zero as an immediate timeout. Configuration uses zero to
        // disable a timeout, represented here by a practical 68-year value.
        return $seconds === 0 ? 2_147_483_647 : $seconds;
    }

    /**
     * Get the handler registry for registering custom operation handlers.
     *
     * Call this before start() to register or override handlers.
     */
    public function getHandlerRegistry(): HandlerRegistry
    {
        return $this->handlerRegistry;
    }

    public function storageTempBudgetCoordinator(): StorageTempBudgetCoordinator
    {
        return $this->tempBudget;
    }

    /**
     * Get the server configuration.
     */
    public function getConfig(): S3ServerConfig
    {
        return $this->config;
    }

    /**
     * Add a middleware to the stack. Must be called before start().
     *
     * Middleware is inserted between the built-in middleware and the S3Router.
     * Use this to add auth, rate-limiting, or custom middleware.
     *
     * @param  Middleware  $middleware  The middleware to add.
     */
    public function addMiddleware(Middleware $middleware): void
    {
        $this->assertConfigurationMutable('Middleware');
        if ($middleware instanceof AuthMiddleware) {
            $credentialProvider = $middleware->credentialProvider();
            if ($this->authenticationCredentialProvider !== null
                && $this->authenticationCredentialProvider !== $credentialProvider) {
                throw new \LogicException('Authentication middleware must share one credential provider.');
            }
        }
        $this->extraMiddleware[] = $middleware;
        if ($middleware instanceof AuthenticationMiddleware) {
            $this->authenticationConfigured = true;
        }
        if ($middleware instanceof AuthMiddleware) {
            $this->authenticationCredentialProvider = $credentialProvider;
        }
    }

    /**
     * Return the server-owned coordinator shared by authentication and checksum spooling.
     *
     * Programmatic integrations should inject this coordinator into AuthMiddleware so
     * all request-body work uses the server's bounded, lifecycle-managed worker pool.
     */
    public function preparePostObjectSpoolCoordinator(): PostObjectSpoolCoordinator
    {
        if ($this->postObjectSpoolCoordinator !== null) {
            return $this->postObjectSpoolCoordinator;
        }
        if (! $this->starting) {
            $this->assertConfigurationMutable('Browser POST spool coordinator');
        }

        $workers = $this->config->requestBodySpoolWorkerPoolSize;
        $this->requestBodySpoolPool = new ContextWorkerPool($workers);
        $this->registerWorkerPool($this->requestBodySpoolPool, 'request_body_spool', $workers);
        $this->postObjectSpoolCoordinator = new PostObjectSpoolCoordinator(
            $this->config->storageTempDir,
            $this->requestBodySpoolPool,
            budgetBytes: $this->config->storageTempBudgetBytes,
            filesystemReserveBytes: $this->config->storageTempReserveBytes,
        );

        return $this->postObjectSpoolCoordinator;
    }

    /**
     * Transfer shutdown ownership of a worker pool to this server.
     *
     * A later stop retry force-kills the pool if its graceful shutdown fails.
     */
    public function addWorkerPool(
        WorkerPool $pool,
        string $name = 'registered',
        ?int $configuredWorkers = null,
        bool $forceKillOnShutdown = false,
    ): void {
        $this->assertConfigurationMutable('Worker pools');
        $this->registerWorkerPool($pool, $name, $configuredWorkers, $forceKillOnShutdown);
    }

    private function registerWorkerPool(
        WorkerPool $pool,
        string $name,
        ?int $configuredWorkers,
        bool $forceKillOnShutdown = false,
    ): void {
        $name = $name !== '' ? $name : 'registered';
        $this->workerPools[] = [
            'pool' => $pool,
            'name' => $name,
            'forceKillOnShutdown' => $forceKillOnShutdown,
        ];
        if ($configuredWorkers !== null) {
            $this->metrics->registerWorkerPool($name, $configuredWorkers);
        }
    }

    private function assertConfigurationMutable(string $component): void
    {
        if ($this->server !== null || $this->starting || $this->shutdown) {
            throw new \LogicException("{$component} must be configured before the S3 server starts.");
        }
    }

    private function parallelSqliteMetadataStore(?MetadataStore $metadata): ?ParallelSqliteMetadataStore
    {
        while ($metadata !== null) {
            if ($metadata instanceof ParallelSqliteMetadataStore) {
                return $metadata;
            }

            if (! method_exists($metadata, 'innerStore')) {
                return null;
            }

            $inner = $metadata->innerStore();
            if (! $inner instanceof MetadataStore || $inner === $metadata) {
                return null;
            }

            $metadata = $inner;
        }

        return null;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function recordTieringWorkerStats(string $worker, array $stats): void
    {
        foreach ($stats as $status => $count) {
            $status = preg_replace('/(?<!^)[A-Z]/', '_$0', $status) ?? $status;
            $this->metrics->recordTieringWorkerResult($worker, strtolower($status), $count);
        }
    }

    /**
     * Build the complete middleware stack wrapping the S3DispatchHandler.
     *
     * Middleware is applied outermost-first. Request ID, metrics and error
     * handling precede proxy, logging, header-contract validation, rate-limit,
     * website, CORS, auth and S3 handler work. Management routes use a separate
     * stack.
     *
     * @param  RequestHandler  $innerHandler  The S3DispatchHandler to wrap.
     * @return RequestHandler The fully stacked handler.
     */
    private function buildMiddlewareStack(RequestHandler $innerHandler): RequestHandler
    {
        // stackMiddleware() makes the first middleware the outermost wrapper.
        // Order: outermost first → innermost last.
        $middlewares = [
            new RequestIdMiddleware(),
            new MetricsMiddleware($this->metrics),
            new ErrorHandlingMiddleware(logger: $this->logger),
            new DrainAdmissionMiddleware($this->drain),
        ];
        if ($this->clusterAdmission !== null) {
            $middlewares[] = new ClusterAdmissionMiddleware($this->clusterAdmission, $this->clusterRoles);
        }
        array_push(
            $middlewares,
            new TrustedProxyMiddleware($this->config->trustedProxyCidrs),
            new LoggingMiddleware(logger: $this->logger, accessLogs: $this->accessLogs),
            new RequestHeaderContractMiddleware(),
            new ExpectContinueMiddleware(),
            new RateLimitMiddleware($this->metadata, $this->config->perClientRateLimit, $this->logger),
        );

        // Website hosting resolves its bucket from Host and handles public
        // GET/HEAD before API route resolution or credential auth.
        if ($this->metadata !== null && $this->storage !== null) {
            $middlewares[] = new WebsiteHostingMiddleware(
                $this->metadata,
                $this->storageTiers ?? StorageTierRegistry::single($this->storage),
                $this->config->websiteHostPattern,
                $this->encryption,
                $this->config->maxEncryptedObjectSize,
            );
        }

        // API requests still set attributes before CORS, auth, and policy checks.
        $middlewares[] = new S3AttributeMiddleware($this->config->baseDomain);
        if ($this->clusterRoles !== null) {
            // Operation resolution reads only the request target and headers;
            // reject reconciliation-dependent writes before auth/body work.
            $middlewares[] = new ClusterRoleAdmissionMiddleware($this->clusterRoles);
        }

        $middlewares[] = new CorsMiddleware($this->metadata);

        // Insert user-supplied middleware (e.g., AuthMiddleware).
        $postObjectSpoolCoordinator = $this->preparePostObjectSpoolCoordinator();
        foreach ($this->extraMiddleware as $extra) {
            if ($extra instanceof AuthMiddleware) {
                $extra->bindServerSpoolCoordinator($postObjectSpoolCoordinator);
            }
            $middlewares[] = $extra;
        }

        // Policy and ACL enforcement: after auth, before content checks.
        if ($this->metadata !== null) {
            $middlewares[] = new PolicyEnforcementMiddleware($this->metadata);
            $middlewares[] = new BucketOwnershipMiddleware($this->metadata);
            $middlewares[] = new AclEnforcementMiddleware($this->metadata);
            $middlewares[] = new BufferedReadAuthorizationMiddleware($this->metadata);
            $middlewares[] = new CurrentObjectReadAuthorizationMiddleware($this->metadata);
        }

        // Innermost: integrity checks right before the handler. Stateless
        // spool tasks avoid reserving amphp/file workers for request lifetime.
        $spool = $this->requestBodySpoolForStack();
        if ($this->metadata !== null) {
            $middlewares[] = new \OpsFour\S3Server\Middleware\ObjectLockChecksumMiddleware($this->metadata);
        }
        $middlewares[] = new ContentMd5Middleware($spool);
        $middlewares[] = new ChecksumValidationMiddleware($spool);

        return Middleware\stackMiddleware($innerHandler, ...$middlewares);
    }

    private function requestBodySpoolForStack(): RequestBodySpool
    {
        $coordinator = $this->preparePostObjectSpoolCoordinator();
        $coordinator->recoverAbandoned();

        return new RequestBodySpool(
            $this->requestBodySpoolPool ?? throw new \LogicException('Request-body spool pool was not prepared.'),
            $this->config->storageTempDir,
            budgetBytes: $this->config->postSpoolBudgetBytes,
            coordinator: $coordinator,
        );
    }

    private function bindStorageTempBudget(?StorageBackend $backend): void
    {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if ($backend instanceof FilesystemBackend
            || $backend instanceof FlysystemBackend
            || $backend instanceof ParallelFlysystemBackend) {
            $backend->bindTempBudget($this->tempBudget);
        }
    }

    /**
     * Choose background dependencies before a listener can be exposed. The
     * qualified graph owns its instances; choosing them never starts workers
     * or transfers their shutdown ownership to the generic server fields.
     *
     * @return array{
     *     lifecycleRunner: ?LifecycleRunner,
     *     notificationProcessor: ?NotificationProcessor,
     *     objectCollectors: list<LocalObjectWriteIntentCollector|AwsS3ObjectWriteIntentCollector>,
     *     transitionExecutor: ?TierTransitionExecutor,
     *     restoreExecutor: ?RestoreExecutor,
     *     restoreGarbageCollector: ?RestoreGarbageCollector,
     *     storageGarbageCollector: ?StorageGarbageCollector,
     *     multipartIntentCollector: ?MultipartWriteIntentCollector
     * }
     */
    private function prepareBackgroundComponents(): array
    {
        $lifecycleRunner = null;
        $notificationProcessor = null;
        $transitionExecutor = null;
        $restoreExecutor = null;
        $restoreGc = null;
        $storageGc = null;
        $multipartIntentGc = null;
        if ($this->metadata !== null && $this->storage !== null) {
            $storage = $this->storage;
            $tiers = $this->storageTiers ?? StorageTierRegistry::single($storage);
            if ($this->config->hasClusterRole(ClusterRoleSet::LIFECYCLE)) {
                $executor = new LifecycleExecutor(
                    $this->metadata,
                    $storage,
                    $this->logger,
                    $this->config->lifecycleBatchSize,
                    $this->config->lifecycleMaxActionsPerRun,
                    $this->config->lifecycleLockTtlSeconds,
                    metrics: $this->metrics,
                    notifications: $this->notifications,
                    storageTiers: $tiers,
                    multipartMaxAgeSeconds: $this->config->multipartMaxAgeSeconds,
                    clusterAdmission: $this->clusterAdmission,
                    clusterFencing: $this->clusterWorkFencing,
                    clusterWorkerId: $this->clusterWorkerIdentity?->forKind('lifecycle'),
                );
                $lifecycleRunner = new LifecycleRunner(
                    $executor,
                    $this->config->lifecycleIntervalSeconds,
                    $this->logger,
                    $this->clusterAdmission,
                    $this->clusterRoles,
                );
            }
            $transitionExecutor = $this->config->hasClusterRole(ClusterRoleSet::TRANSITION)
                ? new TierTransitionExecutor(
                    $this->metadata,
                    $tiers,
                    $this->logger,
                    localFencedWriter: $this->localBackgroundWriter,
                    awsFencedWriter: $this->awsBackgroundWriter,
                    clusterFencing: $this->clusterWorkFencing,
                    clusterWorkerId: $this->clusterWorkerIdentity?->forKind('transition'),
                )
                : null;
            $restoreExecutor = $this->config->hasClusterRole(ClusterRoleSet::RESTORE)
                ? new RestoreExecutor(
                    $this->metadata,
                    $tiers,
                    $this->logger,
                    $this->notifications,
                    localFencedWriter: $this->localBackgroundWriter,
                    awsFencedWriter: $this->awsBackgroundWriter,
                    clusterFencing: $this->clusterWorkFencing,
                    clusterWorkerId: $this->clusterWorkerIdentity?->forKind('restore'),
                )
                : null;
            if ($this->config->hasClusterRole(ClusterRoleSet::GARBAGE)) {
                $restoreGc = new RestoreGarbageCollector($this->metadata, $tiers, $this->logger);
                $storageGc = new StorageGarbageCollector(
                    $this->metadata,
                    $tiers,
                    $this->logger,
                    awsOperationalState: $this->awsOperationalState,
                    clusterFencing: $this->clusterWorkFencing,
                    clusterWorkerId: $this->clusterWorkerIdentity?->forKind('garbage'),
                );
            }
            $multipartIntentGc = $this->config->hasClusterRole(ClusterRoleSet::RECONCILE)
                && $this->metadata instanceof MultipartWriteIntentStore
                ? new MultipartWriteIntentCollector(
                    $this->metadata,
                    $tiers,
                    $this->logger,
                    awsOperationalState: $this->awsOperationalState,
                    clusterFencing: $this->clusterWorkFencing,
                    clusterWorkerId: $this->clusterWorkerIdentity?->forKind('multipart'),
                )
                : null;
        }
        $webhookEnabled = $this->config->clusterEnabled()
            ? $this->config->hasClusterRole(ClusterRoleSet::WEBHOOK)
            : $this->config->notificationWorkerEnabled;
        if ($this->metadata !== null && $webhookEnabled) {
            $notificationProcessor = new NotificationProcessor(
                $this->metadata,
                $this->logger,
                metrics: $this->metrics,
                requireHttps: $this->config->notificationRequireHttps,
                clusterAdmission: $this->clusterAdmission,
                clusterFencing: $this->clusterWorkFencing,
                clusterWorkerId: $this->clusterWorkerIdentity?->forKind('notification'),
                clusterRoles: $this->clusterRoles,
            );
        }

        return [
            'lifecycleRunner' => $lifecycleRunner,
            'notificationProcessor' => $notificationProcessor,
            'objectCollectors' => $this->config->hasClusterRole(ClusterRoleSet::RECONCILE)
                ? array_values(array_filter([$this->localObjectIntentCollector, $this->awsObjectIntentCollector]))
                : [],
            'transitionExecutor' => $transitionExecutor,
            'restoreExecutor' => $restoreExecutor,
            'restoreGarbageCollector' => $restoreGc,
            'storageGarbageCollector' => $storageGc,
            'multipartIntentCollector' => $multipartIntentGc,
        ];
    }

    /** @return list<'live'|'health'|'metrics'|'s3'|'admin'> */
    private function runtimeHttpSurfaces(): array
    {
        $surfaces = ['live', 'health', 'metrics'];
        if ($this->config->hasClusterRole(ClusterRoleSet::API)) {
            $surfaces[] = 's3';
            $surfaces[] = 'admin';
        }

        return $surfaces;
    }

    private function roleAwareS3Fallback(RequestHandler $apiHandler): RequestHandler
    {
        if ($this->config->hasClusterRole(ClusterRoleSet::API)) {
            return $this->buildMiddlewareStack($apiHandler);
        }

        return new class implements RequestHandler {
            public function handleRequest(\Amp\Http\Server\Request $request): \Amp\Http\Server\Response
            {
                return new \Amp\Http\Server\Response(404, ['Cache-Control' => 'no-store']);
            }
        };
    }

    /** @phpstan-impure Revalidates live shared state and may change between claims. */
    private function backgroundWorkAdmitted(): bool
    {
        $admitted = $this->clusterAdmission?->admitNewClaim() ?? true;
        if (! $admitted) {
            $this->clusterRoles?->withdraw();
        }

        return $admitted;
    }

    /** @phpstan-impure Revalidates membership and admission before each durable claim. */
    private function roleWorkAdmitted(string $role): bool
    {
        return $this->backgroundWorkAdmitted() && ($this->clusterRoles?->canClaim($role) ?? true);
    }

    /** Withdraw every advertised role when one assigned durable loop dies. */
    private function handleAssignedRoleLoopExit(string $component, \Amp\Cancellation $cancellation): void
    {
        if ($this->shutdown || $cancellation->isRequested()) {
            return;
        }

        $this->durableRoleLoopFailed = true;
        $this->clusterRoles?->withdraw();
        $this->logger->error('Assigned durable role loop exited unexpectedly; cluster membership withdrawn.', [
            'component' => $component,
        ]);
    }

    private function assertClusterHandlerStorageBindings(?StorageTierRegistry $configuredTiers): void
    {
        if (! $this->config->clusterEnabled()) {
            return;
        }
        if ($configuredTiers === null) {
            throw new \LogicException('Cluster mode requires one configured storage tier registry.');
        }
        foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
            if ($registration['tiers'] !== $configuredTiers
                || $registration['storage'] !== $configuredTiers->defaultBackend()) {
                throw new \LogicException('Cluster handlers must use the server configured storage tier registry and default backend.');
            }
        }
    }

    private function managementHandler(
        RequestHandler $handler,
        bool $rateLimited = true,
        bool $bypassForwardedForValidation = false,
        bool $clusterGated = false,
        bool $drainGated = false,
    ): RequestHandler {
        $middlewares = [
            new RequestIdMiddleware(),
            new MetricsMiddleware($this->metrics),
            new ErrorHandlingMiddleware($this->logger),
        ];
        if ($drainGated) {
            $middlewares[] = new DrainAdmissionMiddleware($this->drain, managementRequest: true);
        }
        if ($clusterGated && $this->clusterAdmission !== null) {
            $middlewares[] = new ClusterAdmissionMiddleware($this->clusterAdmission, $this->clusterRoles);
        }
        $middlewares[] = new TrustedProxyMiddleware(
            $this->config->trustedProxyCidrs,
            enforceForwardedFor: ! $bypassForwardedForValidation,
        );
        if ($rateLimited) {
            $middlewares[] = new RateLimitMiddleware($this->metadata, $this->config->perClientRateLimit, $this->logger);
        }

        return Middleware\stackMiddleware($handler, ...$middlewares);
    }
}
