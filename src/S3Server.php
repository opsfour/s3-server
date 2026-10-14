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
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\BufferedReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\BucketOwnershipMiddleware;
use OpsFour\S3Server\Middleware\ChecksumValidationMiddleware;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\CorsMiddleware;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\EfsApiAdmissionMiddleware;
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
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3DispatchHandler;
use OpsFour\S3Server\Routing\S3Router;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Runtime\EfsCandidateIngressResources;
use OpsFour\S3Server\Runtime\EfsCandidateManagementResources;
use OpsFour\S3Server\Runtime\EfsCandidateMetadataStartupAdmission;
use OpsFour\S3Server\Runtime\EfsCandidateRuntimeResources;
use OpsFour\S3Server\Runtime\EfsRecursiveCleanupAdmission;
use OpsFour\S3Server\Runtime\MetadataDriverStartupGuard;
use OpsFour\S3Server\Runtime\PostgresApiDriverGuard;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Storage\FilesystemBackend;
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
    private ?SocketHttpServer $server = null;

    private bool $shutdown = false;

    private bool $shutdownComplete = false;

    private bool $stopInProgress = false;

    /** @var array<int, true> Successfully closed storage instances retained by this server. */
    private array $closedStorageBackends = [];

    /** @var array<int, true> Successfully closed registered worker pools. */
    private array $closedWorkerPools = [];

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

    private ?LocalObjectWriteIntentCollector $localObjectIntentCollector = null;

    private ?LocalFencedObjectWriter $localObjectTestWriter = null;

    private ?LocalObjectWriteLeaseGuardFactory $localObjectTestLeaseFactory = null;

    private ?LocalFencedBackgroundObjectWriter $localBackgroundTestWriter = null;

    private ?DeferredCancellation $backgroundCancellation = null;

    /** @var list<Future<void>> */
    private array $backgroundFutures = [];

    private ?StorageTierRegistry $storageTiers = null;

    /** @var list<array{pool: WorkerPool, name: string}> Worker pools to shut down on stop. */
    private array $workerPools = [];

    /** @var list<Middleware> Additional middleware to insert into the stack. */
    private array $extraMiddleware = [];

    private bool $authenticationConfigured = false;

    private ?CredentialProvider $authenticationCredentialProvider = null;

    private ?EfsApiAdmissionMiddleware $efsApiAdmissionForTests = null;

    private bool $starting = false;

    private bool $qualifiedEfsCandidateSealed = false;

    private ?EfsCandidateIngressResources $qualifiedEfsIngress = null;

    private ?EfsCandidateRuntimeResources $pendingQualifiedEfsGraph = null;

    private ?EfsCandidateRuntimeResources $qualifiedEfsGraph = null;

    private ?EfsCandidateManagementResources $qualifiedEfsManagement = null;

    private bool $qualifiedEfsPreBindAdmissionPrepared = false;

    private ?ContextWorkerPool $qualifiedEfsSelectPool = null;

    private readonly DeferredCancellation $startupCancellation;

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
        ?EfsCandidateRuntimeResources $candidateGraph = null,
    ) {
        if ($candidateGraph !== null && ($candidateGraph->buildConfig !== $config
            || $candidateGraph->metadata !== $metadata || $candidateGraph->backend !== $storage)) {
            throw new \LogicException('EFS candidate server constructor requires its exact resource graph.');
        }
        $this->logger = new FailureIsolatingLogger($logger ?? new NullLogger());
        $this->startupCancellation = new DeferredCancellation();
        $this->handlerRegistry = new HandlerRegistry();
        $this->metrics = $metrics ?? new MetricsCollector();
        $this->pendingQualifiedEfsGraph = $candidateGraph;
        $this->accessLogs = $candidateGraph !== null ? $candidateGraph->accessLogs : ($metadata !== null && $storage !== null
            ? new AccessLogWriter(
                $metadata,
                $storage,
                $this->logger,
                maxEncryptedObjectSize: $config->maxEncryptedObjectSize,
                quotas: QuotaManager::fromGlobalConfig($metadata, $config->quota),
                localFencedWriter: $this->localObjectTestWriter,
                localLeaseFactory: $this->localObjectTestLeaseFactory,
            )
            : null);
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

        // The candidate seal freezes configuration but does not yet bind all
        // physical writers, handlers, recovery workers and admission to one
        // EFS qualification. Reject before any metadata or hard-NFS syscall.
        if ($this->qualifiedEfsCandidateSealed || $this->qualifiedEfsIngress !== null
            || $this->pendingQualifiedEfsGraph !== null) {
            throw new \RuntimeException('Qualified EFS candidate serving is not yet admitted.');
        }

        if (! $this->authenticationConfigured) {
            throw new \LogicException('Refusing to start without authentication middleware.');
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

            $configuredTiers = $this->storageTiers
                ?? ($this->storage !== null ? StorageTierRegistry::single($this->storage) : null);
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

            if ($this->localObjectTestWriter !== null) {
                if ($this->localObjectIntentCollector === null) {
                    throw new \RuntimeException('Local object write test profile requires its intent collector.');
                }
                if ($this->metadata === null) {
                    throw new \RuntimeException('Local object write test profile requires metadata.');
                }
                MetadataDriverStartupGuard::assertRawFencedSqlStore(
                    $this->metadata,
                    $this->config->metadataDriver,
                    $this->config->metadataDsn,
                );
                $this->localObjectIntentCollector->assertBoundTo($this->metadata, $this->localObjectTestWriter);
                if ($configuredTiers === null || count($configuredTiers->all()) !== 1
                    || $configuredTiers->defaultTier()->name !== $this->localObjectTestWriter->storageTierName()
                    || $this->storage !== $configuredTiers->defaultBackend()) {
                    throw new \RuntimeException('Local object write test profile requires exactly its fenced filesystem tier.');
                }
                $fencedBackend = $configuredTiers->defaultBackend();
                while ($fencedBackend instanceof ObservedStorageBackend) {
                    $fencedBackend = $fencedBackend->innerBackend();
                }
                if (! $fencedBackend instanceof FilesystemBackend
                    || realpath($fencedBackend->getBasePath()) !== $this->localObjectTestWriter->storageRoot()) {
                    throw new \RuntimeException('Local object write test profile filesystem root differs from its writer.');
                }
                foreach ($this->handlerRegistry->storageRegistrations() as $registration) {
                    if ($registration['storage'] !== $this->storage
                        || count($registration['tiers']->all()) !== 1
                        || $registration['tiers']->defaultBackend() !== $this->storage) {
                        throw new \RuntimeException('Local object write test profile handlers must use the same fenced filesystem tier.');
                    }
                }
                if (! $this->metadata instanceof LocalMultipartFenceAdmissionStore
                    || ! $this->metadata instanceof FencedMultipartPartWriteIntentStore
                    || ! $this->metadata instanceof LocalMultipartCompletionIntentStore) {
                    throw new \RuntimeException('Local object write test profile requires multipart legacy-intent admission.');
                }
                $this->metadata->assertNoLegacyLocalMultipartIntents($configuredTiers->defaultTier()->name);
            }

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
                $dispatchHandler = new S3DispatchHandler(
                    handlerRegistry: $this->handlerRegistry,
                );

                // 3. Stack middleware (outermost first).
                // Order: RequestId -> Metrics -> ErrorHandling -> Logging ->
                //        ExpectContinue -> RateLimit -> WebsiteHosting ->
                //        S3Attribute -> Cors -> [Auth] ->
                //        Policy -> ACL -> ContentMd5 -> ChecksumValidation -> S3Dispatch
                $stack = $this->buildMiddlewareStack($dispatchHandler);

                // 4. Wire Amp Router with three catch-all path patterns.
                $errorHandler = new DefaultErrorHandler();
                $router = new Router($this->server, $this->logger, $errorHandler);

                // Probe endpoints bypass client rate limits so aggressive probes or a
                // degraded metadata backend cannot hide the process/dependency state.
                $router->addRoute('GET', '/.live', $this->managementHandler(
                    new HealthCheckHandler(logger: $this->logger),
                    rateLimited: false,
                    bypassForwardedForValidation: true,
                    requireEfsAdmission: false,
                ));
                $router->addRoute('GET', '/.health', $this->managementHandler(
                    new HealthCheckHandler(
                        metadata: $this->metadata,
                        logger: $this->logger,
                        rateLimitEnabled: $this->config->perClientRateLimit > 0,
                        storageTiers: $this->storageTiers ?? (
                            $this->storage !== null ? StorageTierRegistry::single($this->storage) : null
                        ),
                        admissionEvidence: $this->efsApiAdmissionForTests,
                        credentialHealthProbe: $this->authenticationCredentialProvider instanceof CredentialHealthProbe
                            ? $this->authenticationCredentialProvider
                            : null,
                    ),
                    rateLimited: false,
                    bypassForwardedForValidation: true,
                    requireEfsAdmission: false,
                ));
                $router->addRoute('GET', '/.metrics', $this->managementHandler(new MetricsHandler(
                    $this->metrics,
                    $this->metadata,
                    $this->config->metricsBearerToken,
                ), requireEfsAdmission: false));
                if ($this->adminCredentialApiHandler !== null) {
                    $adminCredentials = $this->managementHandler($this->adminCredentialApiHandler);
                    $router->addRoute('POST', '/.admin/credentials', $adminCredentials);
                    $router->addRoute('DELETE', '/.admin/credentials/{accessKeyId}', $adminCredentials);
                }
                if ($this->adminQuotaApiHandler !== null) {
                    $adminQuotas = $this->managementHandler($this->adminQuotaApiHandler);
                    $router->addRoute('GET', '/.admin/quotas', $adminQuotas);
                    $router->addRoute('GET', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                    $router->addRoute('PUT', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                    $router->addRoute('DELETE', '/.admin/quotas/{ownerId:.+}', $adminQuotas);
                }

                // Service-level GET / lists buckets; HEAD / also reaches website
                // hosting before the API resolver rejects it on non-website hosts.
                // POST / accepts browser uploads to a virtual-hosted bucket;
                // OPTIONS / handles that bucket's root preflight.
                $router->addRoute('GET', '/', $stack);
                $router->addRoute('HEAD', '/', $stack);
                $router->addRoute('POST', '/', $stack);
                $router->addRoute('OPTIONS', '/', $stack);

                // Bucket-level: all methods on /{bucket}
                foreach (['GET', 'PUT', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
                    $router->addRoute($method, '/{bucket}', $stack);
                }

                // Object-level: all methods on /{bucket}/{key:.+}
                foreach (['GET', 'PUT', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
                    $router->addRoute($method, '/{bucket}/{key:.+}', $stack);
                }

                // Set the router as the fallback handler too, so unmatched routes
                // still produce proper S3 error responses.
                $router->setFallback($stack);

                // Prepare every background dependency before opening the
                // listener. Their constructors are inert; timers and fibers
                // are activated only after HTTP startup succeeds.
                $background = $this->prepareBackgroundComponents();
                $preparedLifecycleRunner = $background['lifecycleRunner'];
                $preparedNotificationProcessor = $background['notificationProcessor'];
                $objectCollector = $background['objectCollector'];
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
                        $entry['pool']->shutdown();
                        $this->closedWorkerPools[$id] = true;
                    } catch (\Throwable $cleanupError) {
                        $poolCleanupFailed = true;
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
                    unset($this->closedWorkerPools[spl_object_id($entry['pool'])]);
                }
                array_splice($this->workerPools, $startupPoolCount);
                throw $e;
            }
        } catch (\Throwable $error) {
            $this->starting = false;
            if (! $this->shutdown) {
                $this->handlerRegistry->rollbackFreeze($registryFreezeToken);
            }
            throw $error;
        }
        $this->starting = false;

        // A failure in any background service must not leave the HTTP listener
        // running after start() reports an error.
        try {
            // 7. Activate the resources prepared before listener binding.
            if ($preparedLifecycleRunner !== null) {
                // The qualified graph remains the sole lifecycle owner.
                if ($this->qualifiedEfsGraph === null) {
                    $this->lifecycleRunner = $preparedLifecycleRunner;
                }
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
            if ($this->metadata !== null) {
                $metadata = $this->metadata;
                $this->cleanupRunning = true;
                $this->backgroundFutures[] = \Amp\async(function () use ($backgroundCancellation, $metadata): void {
                    try {
                        while ($this->cleanupRunning) {
                            // Every 5 minutes (reduced from 60s to minimize DB contention).
                            \Amp\delay(300, cancellation: $backgroundCancellation);
                            $backgroundCancellation->throwIfRequested();

                            try {
                                $metadata->rateLimitCleanup(3600);
                                $metadata->cleanupOldNotifications(86400);
                                $this->flushAccessLogsIfAdmitted();
                            } catch (\Throwable $e) {
                                $this->logger->warning('Background cleanup failed.', [
                                    'component' => 'background_cleanup',
                                    'exception' => $e::class,
                                ]);
                            }
                        }
                    } catch (\Amp\CancelledException) {
                        // Normal shutdown.
                    }
                });
            }

            if ($objectCollector !== null) {
                $collector = $objectCollector;
                $this->localObjectCollectionRunning = true;
                // Keep local attempt recovery independent of Lifecycle,
                // Restore and other garbage I/O, which may block for longer
                // than one object's claim lease.
                $this->backgroundFutures[] = \Amp\async(function () use ($collector, $backgroundCancellation): void {
                    try {
                        while ($this->localObjectCollectionRunning) {
                            try {
                                $collector->collect(100, $backgroundCancellation);
                            } catch (\Amp\CancelledException $error) {
                                throw $error;
                            } catch (\Throwable $error) {
                                $this->logger->warning('Local object intent collection failed.', [
                                    'component' => 'local_object_intent_gc',
                                    'exception' => $error::class,
                                ]);
                            }
                            \Amp\delay(min(5.0, $this->config->lifecycleIntervalSeconds), cancellation: $backgroundCancellation);
                        }
                    } catch (\Amp\CancelledException) {
                        // Normal shutdown; unresolved attempts remain in SQL.
                    }
                });
            }

            // 10. Start physical tier transition / restore background processing.
            if ($transitionExecutor !== null && $restoreExecutor !== null && $restoreGc !== null && $storageGc !== null) {
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
                                $transitionStats = $transitionExecutor->processNext($this->config->lifecycleBatchSize, $backgroundCancellation);
                                $restoreStats = $restoreExecutor->processNext($this->config->lifecycleBatchSize, $backgroundCancellation);
                                $gcStats = $restoreGc->collect($this->config->lifecycleBatchSize);
                                $storageGc->collect($this->config->lifecycleBatchSize, $backgroundCancellation);
                                $multipartIntentGc?->collect($this->config->lifecycleBatchSize, $backgroundCancellation);

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
                    }
                });
                $this->logger->info('Tiering background processor started.');
            }

            $this->logger->info('S3 server listening on {host}:{port}', [
                'host' => $this->config->host,
                'port' => $this->config->port,
            ]);
        } catch (\Throwable $error) {
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
                $this->localObjectTestWriter,
                $this->localObjectTestLeaseFactory,
            );
        }
    }

    /** Attach one tier-scoped collector for the isolated local write test profile. */
    public function setLocalObjectWriteIntentCollector(LocalObjectWriteIntentCollector $collector): void
    {
        $this->assertConfigurationMutable('Local object intent collector');
        if ($this->localObjectIntentCollector !== null) {
            throw new \LogicException('Local object intent collector is already configured.');
        }
        $this->localObjectIntentCollector = $collector;
    }

    /** Attach the isolated local write test profile to internal access-log delivery. */
    public function setLocalObjectWriteTestWriter(
        LocalFencedObjectWriter $writer,
        LocalObjectWriteLeaseGuardFactory $leaseFactory,
    ): void {
        $this->assertConfigurationMutable('Local object write test writer');
        if (! $this->config->allowUnfencedStorageForTests || $this->config->localObjectWriteTestScratchPath === null) {
            throw new \LogicException('Local object write test writer requires the explicit unsafe test profile.');
        }
        if ($this->localObjectTestWriter !== null || $this->metadata === null || $this->storage === null) {
            throw new \LogicException('Local object write test writer is already attached or server dependencies are incomplete.');
        }
        $this->localObjectTestWriter = $writer;
        $this->localObjectTestLeaseFactory = $leaseFactory;
        $this->localBackgroundTestWriter = new LocalFencedBackgroundObjectWriter($writer, $leaseFactory);
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
                $this->localObjectTestWriter,
                $this->localObjectTestLeaseFactory,
            );
        }
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
     * Warns after `shutdownDrainTimeout` seconds, but keeps dependencies alive
     * until in-flight requests and background work have stopped.
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
            $this->stopOwnedResources();
            $this->shutdownComplete = true;
        } finally {
            $this->stopInProgress = false;
        }
    }

    private function stopOwnedResources(): void
    {
        $this->shutdown = true;
        $this->qualifiedEfsPreBindAdmissionPrepared = false;
        $this->startupCancellation->cancel();
        if ($this->pendingQualifiedEfsGraph !== null && $this->qualifiedEfsGraph === null) {
            // The caller still owns every candidate resource if attachment did
            // not commit, including after a failed registry construction.
            return;
        }

        $timeout = $this->config->shutdownDrainTimeout;
        if ($this->server !== null) {
            $this->logShutdown('info', 'Stopping S3 server (drain timeout: {timeout}s)...', [
                'timeout' => $timeout,
            ]);
        }

        $this->cleanupRunning = false;
        $this->tieringRunning = false;
        $this->localObjectCollectionRunning = false;
        $this->backgroundCancellation?->cancel();
        // Stop accepting new connections and drain in-flight requests before
        // shutting down worker pools or metadata services they may still use.
        if ($this->server !== null) {
            $stopFuture = \Amp\async(function (): void {
                $this->server?->stop();
            });

            try {
                $stopFuture->await(new \Amp\TimeoutCancellation($timeout));
            } catch (\Amp\CancelledException) {
                $this->logShutdown('warning', 'Shutdown drain timeout exceeded ({timeout}s); waiting for Amp to close active streams.', [
                    'timeout' => $timeout,
                ]);
                // Amp has already closed all listening sockets. Do not tear down
                // dependencies while request fibers are still active.
                try {
                    $stopFuture->await();
                } catch (\Throwable $e) {
                    $this->logShutdown('warning', 'HTTP server shutdown failed.', ['exception' => $e::class]);
                    $this->abortIncompleteShutdown();
                }
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'HTTP server shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
            $this->server = null;
        }

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
        $qualifiedGraph = $this->qualifiedEfsGraph;
        if ($qualifiedGraph === null) {
            try {
                $this->flushAccessLogsIfAdmitted();
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Access log flush failed during shutdown.', ['exception' => $e::class]);
            }
        }
        if ($qualifiedGraph !== null) {
            // Candidate pools must be quiescent and closed while their graph
            // still retains storage, SQL and the read pool for a stop retry.
            $this->shutdownWorkerPools();
            try {
                $qualifiedGraph->shutdownForServer($this);
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'EFS candidate graph shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
            $this->notifications = null;
        } else {
            try {
                $this->notifications?->shutdown($timeout);
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Notification shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
            $this->notifications = null;
        }

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
        foreach ($this->qualifiedEfsGraph === null ? $storageBackends : [] as $backend) {
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

        if ($qualifiedGraph === null) {
            $this->shutdownWorkerPools();
        }

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
        $this->logShutdown('info', 'S3 server stopped.');
    }

    private function shutdownWorkerPools(): void
    {
        foreach ($this->workerPools as $entry) {
            $id = spl_object_id($entry['pool']);
            if (isset($this->closedWorkerPools[$id])) {
                continue;
            }
            try {
                if ($this->qualifiedEfsIngress !== null && $entry['pool'] === $this->qualifiedEfsIngress->workerPool) {
                    $this->qualifiedEfsIngress->assertDrainedForServerStop($this);
                }
                $entry['pool']->shutdown();
                $this->closedWorkerPools[$id] = true;
                if ($this->qualifiedEfsIngress !== null && $entry['pool'] === $this->qualifiedEfsIngress->workerPool) {
                    $this->qualifiedEfsIngress->markServerPoolClosed($this);
                }
            } catch (\Throwable $e) {
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
                $this->logShutdown('warning', 'Background task did not stop within {timeout}s; waiting before dependency shutdown.', ['timeout' => $timeout]);
                try {
                    $future->await();
                } catch (\Throwable $e) {
                    $this->logShutdown('warning', 'Background task shutdown failed.', ['exception' => $e::class]);
                }
            } catch (\Throwable $e) {
                $this->logShutdown('warning', 'Background task shutdown failed.', ['exception' => $e::class]);
            }
        }
    }

    /** Never start a best-effort physical log write after test EFS admission is revoked. */
    private function flushAccessLogsIfAdmitted(): void
    {
        if ($this->accessLogs === null) {
            return;
        }
        if ($this->efsApiAdmissionForTests !== null) {
            try {
                $this->efsApiAdmissionForTests->assertFresh();
            } catch (\Throwable) {
                $this->logShutdown('warning', 'Access log flush skipped after EFS admission loss.');

                return;
            }
        }
        $this->accessLogs->flush();
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
        if ($this->pendingQualifiedEfsGraph !== null && $this->qualifiedEfsGraph === null) {
            throw new \LogicException('EFS candidate handler registry is reserved for graph attachment.');
        }
        return $this->handlerRegistry;
    }

    /**
     * Get the server configuration.
     */
    public function getConfig(): S3ServerConfig
    {
        return $this->config;
    }

    /** @internal Construct one listenerless candidate without a second access-log writer. */
    public static function forQualifiedEfsCandidate(
        EfsCandidateRuntimeResources $resources,
        ?LoggerInterface $logger = null,
        ?MetricsCollector $metrics = null,
    ): self {
        $config = $resources->buildConfig
            ?? throw new \LogicException('EFS candidate requires a factory-built configuration.');

        return new self($config, $resources->metadata, $resources->backend, $logger, $metrics, $resources);
    }

    /**
     * Attach exactly one qualified graph and ingress to this prelistener server.
     * Registry construction is the last fallible step before ownership transfer.
     * Production serving remains refused by start().
     *
     * @internal
     */
    public function attachQualifiedEfsCandidateGraph(
        EfsCandidateRuntimeResources $resources,
        EfsCandidateIngressResources $ingress,
        ?ContextWorkerPool $selectWorkerPool = null,
    ): void {
        $this->assertConfigurationMutable('EFS candidate graph', allowPendingGraph: true);
        if ($this->pendingQualifiedEfsGraph !== $resources || $this->qualifiedEfsGraph !== null
            || $this->metadata !== $resources->metadata || $this->storage !== $resources->backend
            || $this->accessLogs !== $resources->accessLogs || $this->storageTiers !== null
            || $this->notifications !== null || $this->encryption !== null
            || $this->extraMiddleware !== [] || $this->workerPools !== []
            || $this->authenticationConfigured || $this->adminCredentialApiHandler !== null
            || $this->adminQuotaApiHandler !== null || $this->efsApiAdmissionForTests !== null
            || $this->handlerRegistry->storageRegistrations() !== []) {
            throw new \LogicException('EFS candidate graph requires its unmodified server shell.');
        }
        foreach (S3Operation::cases() as $operation) {
            if ($this->handlerRegistry->has($operation)) {
                throw new \LogicException('EFS candidate graph requires an empty handler registry.');
            }
        }
        if (($this->config->selectWorkerPoolSize === 0) !== ($selectWorkerPool === null)
            || ($selectWorkerPool !== null && (! $selectWorkerPool->isRunning()
                || $selectWorkerPool->getWorkerLimit() !== $this->config->selectWorkerPoolSize
                || $selectWorkerPool->getWorkerCount() !== 0
                || $selectWorkerPool === $ingress->workerPool || $selectWorkerPool === $resources->readPool))) {
            throw new \LogicException('EFS candidate Select worker pool does not match its configuration.');
        }
        $resources->qualification->assertCandidateRuntimeInputs(
            $this->config,
            $resources->metadata,
            $resources->backend,
            $resources->tiers,
            $resources->scratch,
        );
        $ingress->assertBoundTo($resources->qualification, $resources->scratch);
        $ingress->assertAttachableTo($this->config);
        $ingress->assertMetadataBinding($resources->metadata);
        $resources->assertAttachableTo($this);

        // Register directly into the registry dispatched by this server. A
        // failed registration leaves all resources with their caller and this
        // listenerless shell permanently unsuitable for another attachment.
        HandlerRegistrar::registerQualifiedEfsCandidate(
            $this->handlerRegistry,
            $resources,
            $this->config,
            encryption: $resources->accessLogEncryption,
            notifications: $resources->notifications,
            selectWorkerPool: $selectWorkerPool,
            metrics: $this->metrics,
        );

        $this->storageTiers = $resources->tiers;
        $this->notifications = $resources->notifications;
        $this->encryption = $resources->accessLogEncryption;
        $this->qualifiedEfsSelectPool = $selectWorkerPool;
        $this->extraMiddleware[] = $ingress->auth;
        $this->workerPools[] = ['pool' => $ingress->workerPool, 'name' => 'request_body_spool'];
        if ($selectWorkerPool !== null) {
            $this->workerPools[] = ['pool' => $selectWorkerPool, 'name' => 'select'];
        }
        $this->authenticationConfigured = true;
        $this->authenticationCredentialProvider = $ingress->credentialProvider();
        $this->qualifiedEfsIngress = $ingress;
        $this->qualifiedEfsGraph = $resources;
        $this->qualifiedEfsCandidateSealed = true;
        $ingress->transferOwnershipTo($this);
        $resources->transferOwnershipTo($this);
    }

    /**
     * @internal Verify that the attached graph is the one used by dispatch and shutdown.
     * @phpstan-impure Host evidence and shutdown ownership may change between calls.
     */
    public function ownsQualifiedEfsCandidateGraph(EfsCandidateRuntimeResources $resources): bool
    {
        return $this->qualifiedEfsCandidateSealed
            && $this->qualifiedEfsGraph === $resources
            && $this->pendingQualifiedEfsGraph === $resources
            && $this->config === $resources->buildConfig
            && $this->metadata === $resources->metadata
            && $this->storage === $resources->backend
            && $this->storageTiers === $resources->tiers
            && $this->accessLogs === $resources->accessLogs
            && $this->notifications === $resources->notifications
            && $this->encryption === $resources->accessLogEncryption
            && $this->qualifiedEfsIngress !== null
            && $this->ownsQualifiedEfsCandidateIngress($this->qualifiedEfsIngress)
            && $this->handlerRegistry->storageRegistrations() === [[
                'storage' => $resources->backend,
                'tiers' => $resources->tiers,
            ]];
    }

    /**
     * Bind one graph's inert readiness handler and admission latch to this
     * listenerless candidate. This does not register routes or enable start().
     *
     * @internal
     */
    public function attachQualifiedEfsCandidateManagement(EfsCandidateManagementResources $management): void
    {
        $graph = $this->qualifiedEfsGraph;
        if ($graph === null || $this->qualifiedEfsManagement !== null || $this->shutdown
            || $this->starting || $this->server !== null || ! $this->ownsQualifiedEfsCandidateGraph($graph)) {
            throw new \LogicException('EFS management requires one attached, open candidate graph.');
        }
        $management->assertBoundTo($graph, $this->config);
        $this->qualifiedEfsManagement = $management;
    }

    /** @internal Exact graph-owned management bundle; no route is attached. */
    public function qualifiedEfsCandidateManagement(): EfsCandidateManagementResources
    {
        $management = $this->qualifiedEfsManagement;
        if ($management === null || ! $this->ownsQualifiedEfsCandidateManagement($management)) {
            throw new \LogicException('EFS management candidate is not attached to an open graph.');
        }

        return $management;
    }

    /** @internal Verify the readiness handler and admission latch came from this graph's bundle. */
    public function ownsQualifiedEfsCandidateManagement(EfsCandidateManagementResources $management): bool
    {
        $graph = $this->qualifiedEfsGraph;
        if ($graph === null || $this->qualifiedEfsManagement !== $management
            || $this->shutdown || ! $this->ownsQualifiedEfsCandidateGraph($graph)) {
            return false;
        }
        try {
            $management->assertBoundTo($graph, $this->config);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Prepare an inert, exact candidate admission. This never creates or opens
     * an HTTP server; the production start latch remains closed.
     *
     * @internal
     */
    public function prepareQualifiedEfsCandidatePreBindAdmission(): void
    {
        if ($this->qualifiedEfsPreBindAdmissionPrepared) {
            throw new \LogicException('EFS candidate pre-bind admission was already prepared.');
        }

        // Commit the prepared bit only after every identity, dependency and
        // freshness check succeeds. A failure leaves this shell unadmitted.
        $this->assertQualifiedEfsCandidatePreBindBindings();
        $this->qualifiedEfsPreBindAdmissionPrepared = true;
    }

    /**
     * Repeat the complete local admission immediately before a future expose().
     * No current production path calls expose for an EFS candidate.
     *
     * @internal
     */
    public function assertQualifiedEfsCandidatePreBindAdmissionFresh(): void
    {
        if (! $this->qualifiedEfsPreBindAdmissionPrepared) {
            throw new \LogicException('EFS candidate pre-bind admission was not prepared.');
        }
        try {
            $this->assertQualifiedEfsCandidatePreBindBindings();
        } catch (\Throwable $error) {
            // A failed recheck invalidates the earlier admission. A caller
            // must repeat the complete preparation before considering bind.
            $this->qualifiedEfsPreBindAdmissionPrepared = false;
            throw $error;
        }
    }

    /** @internal Read-only state for ownership and rollback tests. */
    public function isQualifiedEfsCandidatePreBindAdmissionPrepared(): bool
    {
        return $this->qualifiedEfsPreBindAdmissionPrepared;
    }

    private function assertQualifiedEfsCandidatePreBindBindings(): void
    {
        $graph = $this->qualifiedEfsGraph;
        $ingress = $this->qualifiedEfsIngress;
        $management = $this->qualifiedEfsManagement;
        if ($graph === null || $ingress === null || $management === null
            || $this->shutdown || $this->starting || $this->server !== null
            || ! $this->ownsQualifiedEfsCandidateGraph($graph)
            || ! $this->ownsQualifiedEfsCandidateIngress($ingress)
            || ! $this->ownsQualifiedEfsCandidateManagement($management)) {
            throw new \LogicException('EFS pre-bind admission requires one exact, open candidate graph.');
        }

        $graph->qualification->assertCandidateRuntimeInputs(
            $this->config,
            $graph->metadata,
            $graph->backend,
            $graph->tiers,
            $graph->scratch,
        );
        $ingress->assertBoundTo($graph->qualification, $graph->scratch);
        $ingress->assertMetadataBinding($graph->metadata);
        $credentialProvider = $ingress->credentialProvider();
        if (! $credentialProvider instanceof CredentialHealthProbe) {
            throw new \RuntimeException('EFS candidate authentication requires a credential readiness probe.');
        }
        if ($this->authenticationCredentialProvider !== $credentialProvider) {
            throw new \RuntimeException('EFS candidate authentication provider differs from its ingress graph.');
        }
        $management->assertCredentialHealthProbe($credentialProvider);
        $credentialProvider->assertCredentialStoreAvailable();
        EfsCandidateMetadataStartupAdmission::assertReady($this->config, $graph->metadata, $graph->tiers);
        EfsRecursiveCleanupAdmission::assertReady($graph->metadata, $graph->tiers);

        // Credential and SQL admissions may yield. Recheck the entire graph
        // and shared latching admission after all return, immediately before
        // a future listener bind can be considered.
        if (! $this->ownsQualifiedEfsCandidateGraph($graph)
            || ! $this->ownsQualifiedEfsCandidateIngress($ingress)
            || ! $this->ownsQualifiedEfsCandidateManagement($management)) {
            throw new \RuntimeException('EFS candidate identity changed during pre-bind admission.');
        }
        $management->assertBoundTo($graph, $this->config);
    }

    /** @internal The generic runtime must not become a second graph owner. */
    public function isQualifiedEfsGraphShell(): bool
    {
        return $this->pendingQualifiedEfsGraph !== null;
    }

    /** @internal Irreversible configuration seal; it does not admit EFS serving. */
    public function sealConfigurationForQualifiedEfsCandidate(): void
    {
        $this->assertConfigurationMutable('EFS candidate');
        $this->handlerRegistry->sealForQualifiedEfsCandidate();
        $this->qualifiedEfsCandidateSealed = true;
    }

    /**
     * Bind one qualified ingress graph to an otherwise empty candidate shell.
     * The candidate remains unable to start; storage graph ownership stays
     * with its caller until a separate production serving design exists.
     *
     * @internal
     */
    public function attachQualifiedEfsCandidateIngress(
        EfsCandidateRuntimeResources $resources,
        EfsCandidateIngressResources $ingress,
    ): void {
        $this->assertConfigurationMutable('EFS ingress');
        if ($this->qualifiedEfsIngress !== null || $this->metadata !== null || $this->storage !== null
            || $this->storageTiers !== null || $this->accessLogs !== null || $this->notifications !== null
            || $this->encryption !== null || $this->extraMiddleware !== [] || $this->workerPools !== []
            || $this->authenticationConfigured || $this->adminCredentialApiHandler !== null
            || $this->adminQuotaApiHandler !== null || $this->efsApiAdmissionForTests !== null
            || $this->handlerRegistry->storageRegistrations() !== []) {
            throw new \LogicException('EFS ingress requires an empty candidate server shell with one resource owner.');
        }
        foreach (S3Operation::cases() as $operation) {
            if ($this->handlerRegistry->has($operation)) {
                throw new \LogicException('EFS ingress requires an empty handler registry.');
            }
        }
        $resources->qualification->assertCandidateRuntimeInputs(
            $this->config,
            $resources->metadata,
            $resources->backend,
            $resources->tiers,
            $resources->scratch,
        );
        if ($resources->buildConfig !== $this->config) {
            throw new \RuntimeException('EFS candidate runtime graph was built for another server configuration.');
        }
        $ingress->assertBoundTo($resources->qualification, $resources->scratch);
        $ingress->assertAttachableTo($this->config);
        $ingress->assertMetadataBinding($resources->metadata);

        // Seal before transfer. If it fails, the bundle still owns its pool;
        // no server state or listener has changed.
        $this->handlerRegistry->sealForQualifiedEfsCandidate();
        $this->extraMiddleware[] = $ingress->auth;
        $this->workerPools[] = ['pool' => $ingress->workerPool, 'name' => 'request_body_spool'];
        $this->authenticationConfigured = true;
        $this->authenticationCredentialProvider = $ingress->credentialProvider();
        $this->qualifiedEfsIngress = $ingress;
        $this->qualifiedEfsCandidateSealed = true;
        $ingress->transferOwnershipTo($this);
    }

    /** @internal Verify the server has really taken every ingress component. */
    public function ownsQualifiedEfsCandidateIngress(EfsCandidateIngressResources $ingress): bool
    {
        $expectedPools = [['pool' => $ingress->workerPool, 'name' => 'request_body_spool']];
        if ($this->qualifiedEfsSelectPool !== null) {
            $expectedPools[] = ['pool' => $this->qualifiedEfsSelectPool, 'name' => 'select'];
        }
        if (! $this->qualifiedEfsCandidateSealed
            || $this->qualifiedEfsIngress !== $ingress
            || $this->authenticationCredentialProvider === null
            || $this->extraMiddleware !== [$ingress->auth]
            || $this->workerPools !== $expectedPools
            || $this->requestBodySpoolForStack() !== $ingress->requestBodySpool) {
            return false;
        }

        try {
            $ingress->assertCredentialProviderBinding($this->authenticationCredentialProvider);
            $ingress->requestBodySpool->assertQualifiedBinding(
                $ingress->tempDir,
                $ingress->budgetBytes,
                $ingress->coordinator,
                $ingress->workerPool,
            );
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /** @internal Used only to reject late runtime resource attachment. */
    public function isConfigurationSealedForQualifiedEfsCandidate(): bool
    {
        return $this->qualifiedEfsCandidateSealed;
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

    /** Attach an API entry gate only to the explicit, unsafe EFS qualification profile. */
    public function setEfsApiAdmissionForTests(EfsHostEvidence $evidence): void
    {
        $this->assertConfigurationMutable('EFS API admission');
        if (! $this->config->allowUnfencedStorageForTests || $this->efsApiAdmissionForTests !== null) {
            throw new \LogicException('EFS API admission requires the explicit unsafe test profile and can be attached only once.');
        }
        $this->efsApiAdmissionForTests = new EfsApiAdmissionMiddleware($evidence);
    }

    /**
     * Register a worker pool for graceful shutdown.
     */
    public function addWorkerPool(WorkerPool $pool, string $name = 'registered', ?int $configuredWorkers = null): void
    {
        $this->assertConfigurationMutable('Worker pools');
        $this->registerWorkerPool($pool, $name, $configuredWorkers);
    }

    private function registerWorkerPool(WorkerPool $pool, string $name, ?int $configuredWorkers): void
    {
        $name = $name !== '' ? $name : 'registered';
        $this->workerPools[] = ['pool' => $pool, 'name' => $name];
        if ($configuredWorkers !== null) {
            $this->metrics->registerWorkerPool($name, $configuredWorkers);
        }
    }

    private function assertConfigurationMutable(string $component, bool $allowPendingGraph = false): void
    {
        if ($this->pendingQualifiedEfsGraph !== null && ! $allowPendingGraph) {
            throw new \LogicException("{$component} cannot change a reserved EFS candidate server.");
        }
        if ($this->qualifiedEfsCandidateSealed) {
            throw new \LogicException("{$component} cannot be changed after EFS candidate seal.");
        }
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
     * handling wrap the optional EFS test admission. Admission precedes proxy,
     * logging, header-contract validation, rate-limit, website, CORS, auth and
     * S3 handler work. Management routes use a separate stack; admin mutations
     * share the EFS test gate.
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
            ...($this->efsApiAdmissionForTests !== null ? [$this->efsApiAdmissionForTests] : []),
            new TrustedProxyMiddleware($this->config->trustedProxyCidrs),
            new LoggingMiddleware(logger: $this->logger, accessLogs: $this->accessLogs),
            new RequestHeaderContractMiddleware(),
            new ExpectContinueMiddleware(),
            new RateLimitMiddleware($this->metadata, $this->config->perClientRateLimit, $this->logger),
        ];

        // Website hosting resolves its bucket from Host and handles public
        // GET/HEAD before API route resolution or credential auth.
        if ($this->metadata !== null && $this->storage !== null) {
            $middlewares[] = new WebsiteHostingMiddleware(
                $this->metadata,
                $this->storageTiers ?? StorageTierRegistry::single($this->storage),
                $this->config->websiteHostPattern,
            );
        }

        // API requests still set attributes before CORS, auth, and policy checks.
        $middlewares[] = new S3AttributeMiddleware($this->config->baseDomain);

        $middlewares[] = new CorsMiddleware($this->metadata);

        // Insert user-supplied middleware (e.g., AuthMiddleware).
        foreach ($this->extraMiddleware as $extra) {
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
        if ($this->qualifiedEfsIngress !== null) {
            return $this->qualifiedEfsIngress->requestBodySpool;
        }
        $spoolWorkers = $this->config->requestBodySpoolWorkerPoolSize;
        $spoolPool = new ContextWorkerPool($spoolWorkers);
        $this->registerWorkerPool($spoolPool, 'request_body_spool', $spoolWorkers);

        return new RequestBodySpool($spoolPool, budgetBytes: $this->config->postSpoolBudgetBytes);
    }

    /**
     * Choose background dependencies before a listener can be exposed. The
     * qualified graph owns its instances; choosing them never starts workers
     * or transfers their shutdown ownership to the generic server fields.
     *
     * @return array{
     *     lifecycleRunner: ?LifecycleRunner,
     *     notificationProcessor: ?NotificationProcessor,
     *     objectCollector: ?LocalObjectWriteIntentCollector,
     *     transitionExecutor: ?TierTransitionExecutor,
     *     restoreExecutor: ?RestoreExecutor,
     *     restoreGarbageCollector: ?RestoreGarbageCollector,
     *     storageGarbageCollector: ?StorageGarbageCollector,
     *     multipartIntentCollector: ?MultipartWriteIntentCollector
     * }
     */
    private function prepareBackgroundComponents(): array
    {
        if ($this->pendingQualifiedEfsGraph !== null) {
            $graph = $this->pendingQualifiedEfsGraph;
            if (! $this->ownsQualifiedEfsCandidateGraph($graph)) {
                throw new \LogicException('EFS background components require the attached candidate graph.');
            }
            // The generic processor has no EFS admission check. Keep this
            // path closed until a qualified notification worker exists.
            if ($this->config->notificationWorkerEnabled) {
                throw new \LogicException('EFS notification background processing is not qualified.');
            }

            return [
                'lifecycleRunner' => $graph->lifecycleRunner,
                'notificationProcessor' => null,
                'objectCollector' => $graph->objectCollector,
                'transitionExecutor' => $graph->transitionExecutor,
                'restoreExecutor' => $graph->restoreExecutor,
                'restoreGarbageCollector' => $graph->restoreGarbageCollector,
                'storageGarbageCollector' => $graph->storageGarbageCollector,
                'multipartIntentCollector' => $graph->multipartIntentCollector,
            ];
        }

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
            );
            $lifecycleRunner = new LifecycleRunner($executor, $this->config->lifecycleIntervalSeconds, $this->logger);
            $transitionExecutor = new TierTransitionExecutor(
                $this->metadata,
                $tiers,
                $this->logger,
                localFencedWriter: $this->localBackgroundTestWriter,
            );
            $restoreExecutor = new RestoreExecutor(
                $this->metadata,
                $tiers,
                $this->logger,
                $this->notifications,
                localFencedWriter: $this->localBackgroundTestWriter,
            );
            $restoreGc = new RestoreGarbageCollector($this->metadata, $tiers, $this->logger);
            $storageGc = new StorageGarbageCollector($this->metadata, $tiers, $this->logger);
            $multipartIntentGc = $this->metadata instanceof MultipartWriteIntentStore
                ? new MultipartWriteIntentCollector($this->metadata, $tiers, $this->logger)
                : null;
        }
        if ($this->metadata !== null && $this->config->notificationWorkerEnabled) {
            $notificationProcessor = new NotificationProcessor(
                $this->metadata,
                $this->logger,
                metrics: $this->metrics,
                requireHttps: $this->config->notificationRequireHttps,
            );
        }

        return [
            'lifecycleRunner' => $lifecycleRunner,
            'notificationProcessor' => $notificationProcessor,
            'objectCollector' => $this->localObjectIntentCollector,
            'transitionExecutor' => $transitionExecutor,
            'restoreExecutor' => $restoreExecutor,
            'restoreGarbageCollector' => $restoreGc,
            'storageGarbageCollector' => $storageGc,
            'multipartIntentCollector' => $multipartIntentGc,
        ];
    }

    private function managementHandler(
        RequestHandler $handler,
        bool $rateLimited = true,
        bool $bypassForwardedForValidation = false,
        bool $requireEfsAdmission = true,
    ): RequestHandler {
        $middlewares = [
            new RequestIdMiddleware(),
            new MetricsMiddleware($this->metrics),
            new ErrorHandlingMiddleware($this->logger),
            ...($requireEfsAdmission && $this->efsApiAdmissionForTests !== null ? [$this->efsApiAdmissionForTests] : []),
            new TrustedProxyMiddleware($this->config->trustedProxyCidrs, enforceForwardedFor: ! $bypassForwardedForValidation),
        ];
        if ($rateLimited) {
            $middlewares[] = new RateLimitMiddleware($this->metadata, $this->config->perClientRateLimit, $this->logger);
        }

        return Middleware\stackMiddleware($handler, ...$middlewares);
    }
}
