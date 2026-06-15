<?php

declare(strict_types=1);

namespace OpsFour\S3Server;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Notification\NotificationProcessor;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\ChecksumValidationMiddleware;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\CorsMiddleware;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Middleware\ExpectContinueMiddleware;
use OpsFour\S3Server\Middleware\LoggingMiddleware;
use OpsFour\S3Server\Middleware\MetricsMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Middleware\RateLimitMiddleware;
use OpsFour\S3Server\Middleware\RequestIdMiddleware;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Observability\MetricsCollector;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3DispatchHandler;
use OpsFour\S3Server\Routing\S3Router;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
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

    private readonly HandlerRegistry $handlerRegistry;

    private readonly LoggerInterface $logger;

    private ?LifecycleRunner $lifecycleRunner = null;

    private ?NotificationProcessor $notificationProcessor = null;

    private ?NotificationDispatcher $notifications = null;

    private readonly MetricsCollector $metrics;

    private ?RequestHandler $adminCredentialApiHandler = null;

    private ?RequestHandler $adminQuotaApiHandler = null;

    private bool $cleanupRunning = false;

    private bool $tieringRunning = false;

    private ?StorageTierRegistry $storageTiers = null;

    /** @var list<array{pool: WorkerPool, name: string}> Worker pools to shut down on stop. */
    private array $workerPools = [];

    /** @var list<Middleware> Additional middleware to insert into the stack. */
    private array $extraMiddleware = [];

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
        $this->logger = $logger ?? new NullLogger();
        $this->handlerRegistry = new HandlerRegistry();
        $this->metrics = $metrics ?? new MetricsCollector();
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
        if ($this->extraMiddleware === []) {
            $this->logger->warning('No auth middleware registered. Server is unauthenticated.');
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
            connectionTimeout: 30, // Shorter than default 60s to prevent connection accumulation.
            streamTimeout: 120, // Allow 2 min for large uploads.
        );

        /** @var int<1, max> $connectionLimit */
        $connectionLimit = $this->config->maxConcurrentConnections;

        // Per-IP connection limit prevents connection accumulation from rapid client creation.
        // When a single IP exceeds this limit, oldest connections are force-closed.
        /** @var int<1, max> $perIpLimit */
        $perIpLimit = min($connectionLimit, 200);

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
        // Order: RequestId -> ErrorHandling -> Logging -> ExpectContinue ->
        //        S3Attribute -> WebsiteHosting -> Cors -> [Auth] ->
        //        Policy -> ACL -> ContentMd5 -> ChecksumValidation -> S3Dispatch
        $stack = $this->buildMiddlewareStack($dispatchHandler);

        // 4. Wire Amp Router with three catch-all path patterns.
        $errorHandler = new DefaultErrorHandler();
        $router = new Router($this->server, $this->logger, $errorHandler);

        // Health check endpoint (registered before S3 routes).
        $router->addRoute('GET', '/.health', new HealthCheckHandler($this->metadata));
        $router->addRoute('GET', '/.metrics', new MetricsHandler($this->metrics, $this->metadata));
        if ($this->adminCredentialApiHandler !== null) {
            $router->addRoute('POST', '/.admin/credentials', $this->adminCredentialApiHandler);
            $router->addRoute('DELETE', '/.admin/credentials/{accessKeyId}', $this->adminCredentialApiHandler);
        }
        if ($this->adminQuotaApiHandler !== null) {
            $router->addRoute('GET', '/.admin/quotas', $this->adminQuotaApiHandler);
            $router->addRoute('GET', '/.admin/quotas/{ownerId}', $this->adminQuotaApiHandler);
            $router->addRoute('PUT', '/.admin/quotas/{ownerId}', $this->adminQuotaApiHandler);
            $router->addRoute('DELETE', '/.admin/quotas/{ownerId}', $this->adminQuotaApiHandler);
        }

        // Service-level: GET / (ListBuckets)
        $router->addRoute('GET', '/', $stack);

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

        // 5. Expose on the configured host:port.
        $this->server->expose($this->config->host . ':' . $this->config->port);

        // 6. Start the server.
        $this->server->start($router, $errorHandler);

        // 7. Start lifecycle runner if metadata and storage are available.
        if ($this->metadata !== null && $this->storage !== null) {
            $executor = new LifecycleExecutor(
                $this->metadata,
                $this->storage,
                $this->logger,
                $this->config->lifecycleBatchSize,
                $this->config->lifecycleMaxActionsPerRun,
                $this->config->lifecycleLockTtlSeconds,
                metrics: $this->metrics,
                notifications: $this->notifications,
            );
            $this->lifecycleRunner = new LifecycleRunner($executor, $this->config->lifecycleIntervalSeconds, $this->logger);
            $this->lifecycleRunner->start();
            $this->logger->info('Lifecycle runner started.');
        }

        // 8. Start notification queue processor.
        if ($this->metadata !== null) {
            $this->notificationProcessor = new NotificationProcessor($this->metadata, $this->logger, metrics: $this->metrics);
            $this->notificationProcessor->start();
            $this->logger->info('Notification processor started.');
        }

        // 9. Start background cleanup fiber for rate limit + notification queue.
        if ($this->metadata !== null) {
            $this->cleanupRunning = true;
            \Amp\async(function (): void {
                while ($this->cleanupRunning) {
                    \Amp\delay(300); // Every 5 minutes (reduced from 60s to minimize DB contention)
                    try {
                        $this->metadata->rateLimitCleanup(3600);
                        $this->metadata->cleanupOldNotifications(86400);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Background cleanup error: {error}', ['error' => $e->getMessage()]);
                    }
                }
            });
        }

        // 10. Start physical tier transition / restore background processing.
        if ($this->metadata !== null && $this->storage !== null) {
            $tiers = $this->storageTiers ?? StorageTierRegistry::single($this->storage);
            $transitionExecutor = new TierTransitionExecutor($this->metadata, $tiers, $this->logger);
            $restoreExecutor = new RestoreExecutor($this->metadata, $tiers, $this->logger);
            $restoreGc = new RestoreGarbageCollector($this->metadata, $tiers->defaultBackend(), $this->logger);
            $this->tieringRunning = true;
            \Amp\async(function () use ($transitionExecutor, $restoreExecutor, $restoreGc): void {
                while ($this->tieringRunning) {
                    try {
                        $transitionStats = $transitionExecutor->processNext($this->config->lifecycleBatchSize);
                        $restoreStats = $restoreExecutor->processNext($this->config->lifecycleBatchSize);
                        $gcStats = $restoreGc->collect($this->config->lifecycleBatchSize);

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
                        $this->logger->warning('Tiering background processing error: {error}', ['error' => $e->getMessage()]);
                    }

                    \Amp\delay($this->config->lifecycleIntervalSeconds);
                }
            });
            $this->logger->info('Tiering background processor started.');
        }

        $this->logger->info('S3 server listening on {host}:{port}', [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ]);
    }

    public function setNotificationDispatcher(NotificationDispatcher $notifications): void
    {
        $this->notifications = $notifications;
    }

    public function setStorageTierRegistry(StorageTierRegistry $storageTiers): void
    {
        $this->storageTiers = $storageTiers;
    }

    public function setAdminCredentialApiHandler(RequestHandler $handler): void
    {
        $this->adminCredentialApiHandler = $handler;
    }

    public function setAdminQuotaApiHandler(RequestHandler $handler): void
    {
        $this->adminQuotaApiHandler = $handler;
    }

    /**
     * Gracefully stop the server, waiting for in-flight requests to drain.
     *
     * Waits up to `shutdownDrainTimeout` seconds for in-flight requests
     * to complete. If the timeout expires, the server is force-stopped.
     */
    public function stop(): void
    {
        if ($this->server === null) {
            return;
        }

        $this->logger->info('Stopping S3 server (drain timeout: {timeout}s)...', [
            'timeout' => $this->config->shutdownDrainTimeout,
        ]);

        $this->cleanupRunning = false;
        $this->tieringRunning = false;
        $this->notificationProcessor?->stop();
        $this->notificationProcessor = null;
        $this->lifecycleRunner?->stop();
        $this->lifecycleRunner = null;

        // Amp's stop() waits for in-flight requests. Wrap with a timeout
        // to prevent hanging indefinitely on slow connections.
        $timeout = $this->config->shutdownDrainTimeout;
        $stopped = false;

        $stopFuture = \Amp\async(function () use (&$stopped): void {
            $this->server?->stop();
            $stopped = true;
        });

        try {
            \Amp\Future\await([$stopFuture], new \Amp\TimeoutCancellation($timeout));
        } catch (\Amp\CancelledException) {
            $this->logger->warning('Shutdown drain timeout exceeded ({timeout}s), forcing stop.', [
                'timeout' => $timeout,
            ]);
        }

        // Shut down worker pools.
        foreach ($this->workerPools as $entry) {
            try {
                $entry['pool']->shutdown();
            } catch (\Throwable $e) {
                $this->metrics->recordWorkerPoolShutdownFailure($entry['name'], $e::class);
                $this->logger->warning('Worker pool shutdown error: {error}', ['error' => $e->getMessage()]);
            }
        }

        // Shut down ParallelSqliteMetadataStore if applicable, even when wrapped by decorators.
        $parallelSqlite = $this->parallelSqliteMetadataStore($this->metadata);
        if ($parallelSqlite !== null) {
            try {
                $parallelSqlite->shutdown();
            } catch (\Throwable $e) {
                $this->logger->warning('Metadata store shutdown error: {error}', ['error' => $e->getMessage()]);
            }
        }

        $this->server = null;
        $this->logger->info('S3 server stopped.');
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
        $this->extraMiddleware[] = $middleware;
    }

    /**
     * Register a worker pool for graceful shutdown.
     */
    public function addWorkerPool(WorkerPool $pool, string $name = 'registered', ?int $configuredWorkers = null): void
    {
        $name = $name !== '' ? $name : 'registered';
        $this->workerPools[] = ['pool' => $pool, 'name' => $name];
        if ($configuredWorkers !== null) {
            $this->metrics->registerWorkerPool($name, $configuredWorkers);
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
            if (!$inner instanceof MetadataStore || $inner === $metadata) {
                return null;
            }

            $metadata = $inner;
        }

        return null;
    }

    /**
     * @param array<string, int> $stats
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
     * Middleware is applied outermost-first. The built-in order is:
     * 1. RequestIdMiddleware          - Assigns a unique request ID to every request.
     * 2. ErrorHandlingMiddleware      - Catches S3Exception and converts to XML error responses.
     * 3. LoggingMiddleware            - Logs request/response pairs with timing.
     * 4. ExpectContinueMiddleware     - Handles Expect: 100-continue for large uploads.
     * 5. S3AttributeMiddleware        - Sets s3.bucket, s3.key, s3.operation attributes.
     * 6. WebsiteHostingMiddleware     - Serves static website content (before auth).
     * 7. CorsMiddleware               - Handles CORS preflight and response headers.
     * 8. [extra middleware]            - User-supplied middleware (auth, rate-limiting, etc.)
     * 9. PolicyEnforcementMiddleware  - Evaluates bucket policies.
     * 10. AclEnforcementMiddleware    - Evaluates bucket/object ACLs.
     * 11. ContentMd5Middleware        - Validates Content-MD5 header if present.
     * 12. ChecksumValidationMiddleware - Validates x-amz-checksum-* headers.
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
            new LoggingMiddleware(logger: $this->logger),
            new ExpectContinueMiddleware(),
            new RateLimitMiddleware($this->metadata, $this->config->perClientRateLimit),
            // S3AttributeMiddleware runs early to set s3.bucket, s3.key, s3.operation
            // so all downstream middleware can read them.
            new S3AttributeMiddleware($this->config->baseDomain),
        ];

        // Website hosting: before auth (website content is public).
        // Now has s3.bucket available from S3AttributeMiddleware.
        if ($this->metadata !== null && $this->storage !== null) {
            $middlewares[] = new WebsiteHostingMiddleware(
                $this->metadata,
                $this->storage,
                $this->config->websiteHostPattern,
            );
        }

        $middlewares[] = new CorsMiddleware($this->metadata);

        // Insert user-supplied middleware (e.g., AuthMiddleware).
        foreach ($this->extraMiddleware as $extra) {
            $middlewares[] = $extra;
        }

        // Policy and ACL enforcement: after auth, before content checks.
        if ($this->metadata !== null) {
            $middlewares[] = new PolicyEnforcementMiddleware($this->metadata);
            $middlewares[] = new AclEnforcementMiddleware($this->metadata);
        }

        // Innermost: integrity checks right before the handler.
        $middlewares[] = new ContentMd5Middleware();
        $middlewares[] = new ChecksumValidationMiddleware();

        return Middleware\stackMiddleware($innerHandler, ...$middlewares);
    }
}
