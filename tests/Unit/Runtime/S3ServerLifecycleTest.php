<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream\Pipe;
use Amp\DeferredFuture;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Runtime\ClusterMembershipStore;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\NodeDrainController;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class S3ServerLifecycleTest extends TestCase
{
    public function test_node_temp_process_leases_close_only_after_shutdown_reaches_owned_resources(): void
    {
        $base = sys_get_temp_dir() . '/s3-server-temp-shutdown-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        $server = new S3Server(new S3ServerConfig(
            storagePath: $base,
            storageTempDir: $base,
            storageTempReserveBytes: 0,
        ));
        $server->preparePostObjectSpoolCoordinator();
        $control = $base . '/.opsfour-s3-storage-budget';

        try {
            self::assertCount(2, glob($control . '/process-lease-*') ?: []);
            $server->beginDrain();
            self::assertCount(2, glob($control . '/process-lease-*') ?: []);

            $server->stop();

            self::assertSame([], glob($control . '/process-lease-*') ?: []);
            self::assertSame([], glob($control . '/process-state-*') ?: []);
        } finally {
            $server->stop();
            foreach (glob($control . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($control);
            @rmdir($base);
        }
    }

    public function test_server_cannot_start_after_programmatic_drain_begins(): void
    {
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $server->beginDrain();

        try {
            $server->start();
            self::fail('A drained server reopened request admission.');
        } catch (\LogicException $error) {
            self::assertSame('S3 server cannot start after drain begins.', $error->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function test_server_drain_demotes_membership_before_listener_close_and_then_starts_propagation(): void
    {
        $port = $this->unusedPort();
        $listenerOpenWhenDemoted = false;
        $store = new class (function () use ($port, &$listenerOpenWhenDemoted): void {
            $listenerOpenWhenDemoted = $this->canConnect($port);
            \Amp\delay(0.05);
        }) implements ClusterMembershipStore {
            /** @var list<bool> */
            public array $readyUpdates = [];
            private bool $ready = false;

            public function __construct(private readonly \Closure $onDemotion) {}

            public function registerClusterMember(
                string $clusterId,
                string $nodeId,
                string $incarnationId,
                array $roles,
                bool $ready,
                bool $draining,
                float $ttlSeconds,
            ): void {
                $this->ready = $ready;
            }

            public function heartbeatClusterMember(
                string $clusterId,
                string $nodeId,
                string $incarnationId,
                bool $ready,
                bool $draining,
                float $ttlSeconds,
            ): bool {
                $this->readyUpdates[] = $ready;
                if ($draining) {
                    ($this->onDemotion)();
                }
                $this->ready = $ready;

                return true;
            }

            public function withdrawClusterMember(string $clusterId, string $nodeId, string $incarnationId): void
            {
                $this->ready = false;
            }

            public function isClusterMemberLive(string $clusterId, string $nodeId, string $incarnationId): bool
            {
                return $this->ready;
            }

            public function liveClusterRoles(string $clusterId): array
            {
                return $this->ready ? ['api', 'reconcile'] : [];
            }
        };
        $clusterConfig = new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            clusterRoles: 'api,reconcile',
        );
        $roles = new ClusterRoleController($store, $clusterConfig);
        $roles->registerReady();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            shutdownReadinessDelay: 1,
            notificationWorkerEnabled: false,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->start();
        (new \ReflectionProperty($server, 'clusterRoles'))->setValue($server, $roles);

        try {
            $server->beginDrain();

            self::assertSame([false], $store->readyUpdates);
            self::assertTrue($listenerOpenWhenDemoted);
            self::assertTrue($this->canConnect($port));
            $drain = (new \ReflectionProperty($server, 'drain'))->getValue($server);
            self::assertInstanceOf(NodeDrainController::class, $drain);
            self::assertGreaterThan(0.9, $drain->remainingPropagationDelay(1));
        } finally {
            $server->stop();
        }

        self::assertFalse($this->canConnect($port));
    }

    /** @return iterable<string, array{bool}> */
    public static function objectPartLayoutCapabilityStates(): iterable
    {
        yield 'absent' => [false];
        yield 'disabled' => [true];
    }

    #[DataProvider('objectPartLayoutCapabilityStates')]
    public function test_start_rejects_missing_layout_capability_before_listener_binding(bool $disabled): void
    {
        $metadata = $this->layoutCapabilityMetadataMock($disabled);
        if ($disabled) {
            self::assertInstanceOf(ObjectPartLayoutStore::class, $metadata);
            $metadata->method('supportsObjectPartLayouts')->willReturn(false);
        }
        $root = sys_get_temp_dir() . '/s3-layout-capability-start-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $port = $this->unusedPort();
        $server = new S3Server(
            new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: $root, perClientRateLimit: 0),
            $metadata,
            new FilesystemBackend($root),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Startup must reject metadata without authoritative object part layouts.');
        } catch (\LogicException $error) {
            self::assertSame('Metadata store does not support authoritative object part layouts.', $error->getMessage());
        } finally {
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertFalse($this->canConnect($port));
            $server->stop();
            @unlink($root . '/.opsfour/runtime.lock');
            @rmdir($root . '/.opsfour');
            @rmdir($root);
        }
    }

    public function test_local_intent_collector_gets_its_own_background_future(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-collector-lifecycle-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $database = $root . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $storage = new FilesystemBackend($root);
        $tiers = StorageTierRegistry::single($storage);
        $collector = new LocalObjectWriteIntentCollector($metadata, $tiers, 'STANDARD');
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);
        $server = new S3Server(new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: $root), $metadata, $storage);
        $server->setLocalObjectWriteIntentCollector($collector);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::assertCount(3, (new \ReflectionProperty($server, 'backgroundFutures'))->getValue($server));
        } finally {
            $server->stop();
            @unlink($database);
            @unlink($database . '-wal');
            @unlink($database . '-shm');
            @unlink($root . '/.opsfour/runtime.lock');
            @rmdir($root . '/.opsfour');
            @rmdir($root);
        }
    }

    public function test_invalid_tls_file_fails_before_server_and_spool_pool_are_created(): void
    {
        $server = new S3Server(new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            tlsCertPath: sys_get_temp_dir() . '/missing-s3-cert-' . bin2hex(random_bytes(8)),
            tlsKeyPath: sys_get_temp_dir() . '/missing-s3-key-' . bin2hex(random_bytes(8)),
            perClientRateLimit: 0,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected a missing TLS certificate to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_failed_http_bind_releases_only_startup_resources(): void
    {
        $occupied = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($occupied);
        $address = stream_socket_get_name($occupied, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
        ));
        $callerPool = $this->createMock(WorkerPool::class);
        $callerPool->expects(self::once())->method('shutdown');
        $server->addWorkerPool($callerPool, 'caller');
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            try {
                $server->start();
                self::fail('Expected the occupied HTTP port to reject startup.');
            } catch (\Amp\Socket\SocketException) {
                self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
                self::assertSame([[
                    'pool' => $callerPool,
                    'name' => 'caller',
                    'forceKillOnShutdown' => false,
                ]], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
            }
        } finally {
            fclose($occupied);
            $server->stop();
        }
    }

    public function test_dedicated_blocking_pool_is_force_killed_while_other_pools_remain_graceful(): void
    {
        $probePool = $this->createMock(WorkerPool::class);
        $probePool->expects(self::once())->method('kill');
        $probePool->expects(self::never())->method('shutdown');
        $probePool->method('isRunning')->willReturn(false);
        $regularPool = $this->createMock(WorkerPool::class);
        $regularPool->expects(self::never())->method('kill');
        $regularPool->expects(self::once())->method('shutdown');
        $regularPool->method('isRunning')->willReturn(false);
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $server->addWorkerPool($probePool, 'aws_provider_probe', 1, forceKillOnShutdown: true);
        $server->addWorkerPool($regularPool, 'regular', 1);

        $server->stop();
    }

    public function test_storage_shutdown_error_does_not_log_backend_secret(): void
    {
        $secret = 'storage-password-private-test-token';
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willThrowException(new \RuntimeException($secret));
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage, logger: $logger);

        try {
            $server->stop();
            self::fail('A failed storage shutdown was reported as complete.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }

        self::assertStringNotContainsString($secret, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertContains(\RuntimeException::class, array_column(array_column($logger->records, 'context'), 'exception'));
    }

    public function test_runtime_shutdown_error_does_not_log_encryption_secret(): void
    {
        $secret = 'encryption-password-private-test-token';
        $encryption = new class ($secret) implements EncryptionServiceInterface {
            public function __construct(private readonly string $secret) {}

            public function shutdown(): void
            {
                throw new \RuntimeException($this->secret);
            }

            public function encryptSseS3(string $plaintext): array
            {
                throw new \LogicException('Not used in this test.');
            }

            public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
            {
                throw new \LogicException('Not used in this test.');
            }

            public function encryptSseC(string $plaintext, string $customerKey): array
            {
                throw new \LogicException('Not used in this test.');
            }

            public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
            {
                throw new \LogicException('Not used in this test.');
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), logger: $logger);
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher($metadata), $encryption, logger: $logger);

        try {
            $runtime->stop();
            self::fail('A failed encryption shutdown was reported as complete.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 runtime shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }

        self::assertStringNotContainsString($secret, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertContains(\RuntimeException::class, array_column(array_column($logger->records, 'context'), 'exception'));
    }

    public function test_runtime_shutdown_logger_failure_cannot_expose_its_error_or_mark_shutdown_complete(): void
    {
        $storageCalls = 0;
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willReturnCallback(static function () use (&$storageCalls): void {
            if (++$storageCalls === 1) {
                throw new \RuntimeException('private-storage-error');
            }
        });
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ((string) $message === 'Server shutdown failed.') {
                    throw new \RuntimeException('private-logger-error');
                }
            }
        };
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $runtime = new S3ServerRuntime(
            new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage),
            new NotificationDispatcher($metadata),
            logger: $logger,
        );

        try {
            $runtime->stop();
            self::fail('Expected the first storage close to fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 runtime shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }
        $runtime->stop();
        self::assertSame(2, $storageCalls);
    }

    public function test_active_lifecycle_sweep_keeps_dependencies_owned_until_it_finishes(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $finished = new DeferredFuture();
        $stopLogged = new DeferredFuture();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('acquireLock')->willReturn(true);
        $metadata->method('listAllBuckets')->willReturnCallback(
            static function () use ($entered, $release): array {
                $entered->complete();
                $release->getFuture()->await();

                return [];
            },
        );
        $runnerLogger = new class ($finished, $stopLogged) extends AbstractLogger {
            /**
             * @param DeferredFuture<void> $finished
             * @param DeferredFuture<void> $stopLogged
             */
            public function __construct(
                private readonly DeferredFuture $finished,
                private readonly DeferredFuture $stopLogged,
            ) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (($context['event'] ?? null) === 'runner_stopped' && ! $this->stopLogged->isComplete()) {
                    $this->stopLogged->complete();
                }
                if (($context['event'] ?? null) === 'sweep_finished') {
                    $this->finished->complete();
                }
            }
        };
        $runner = new LifecycleRunner(new LifecycleExecutor($metadata, new InMemoryBackend(), $runnerLogger), 0.01, $runnerLogger);
        $storageCalls = 0;
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->expects(self::once())->method('shutdown')->willReturnCallback(static function () use (&$storageCalls): void {
            $storageCalls++;
        });
        $poolCalls = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('shutdown')->willReturnCallback(static function () use (&$poolCalls): void {
            $poolCalls++;
        });
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage);
        $server->addWorkerPool($pool);
        (new \ReflectionProperty($server, 'lifecycleRunner'))->setValue($server, $runner);
        $encryption = new class implements EncryptionServiceInterface {
            public int $shutdownCalls = 0;

            public function shutdown(): void
            {
                $this->shutdownCalls++;
            }

            public function encryptSseS3(string $plaintext): array
            {
                throw new \LogicException('Not used in this test.');
            }

            public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
            {
                throw new \LogicException('Not used in this test.');
            }

            public function encryptSseC(string $plaintext, string $customerKey): array
            {
                throw new \LogicException('Not used in this test.');
            }

            public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
            {
                throw new \LogicException('Not used in this test.');
            }
        };
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher(new SqliteMetadataStore(':memory:')), $encryption);
        // The lifecycle timer is unreferenced in production. This referenced
        // test timer permits deterministic waiting for the first sweep.
        $keepAlive = EventLoop::repeat(0.1, static function (): void {});
        $serverStop = null;
        try {
            $runner->start();
            $entered->getFuture()->await(new \Amp\TimeoutCancellation(2));

            $serverStop = \Amp\async(static fn() => $server->stop());
            $stopLogged->getFuture()->await(new \Amp\TimeoutCancellation(2));

            self::assertFalse($serverStop->isComplete());
            self::assertSame($runner, (new \ReflectionProperty($server, 'lifecycleRunner'))->getValue($server));
            self::assertSame(0, $storageCalls);
            self::assertSame(0, $poolCalls);
            self::assertSame(0, $encryption->shutdownCalls);

            $release->complete();
            $finished->getFuture()->await(new \Amp\TimeoutCancellation(2));
            $serverStop->await(new \Amp\TimeoutCancellation(2));

            self::assertSame(1, $storageCalls);
            self::assertSame(1, $poolCalls);
            $runtime->stop();
            self::assertSame(1, $encryption->shutdownCalls);
        } finally {
            if (! $release->isComplete()) {
                $release->complete();
            }
            if ($entered->isComplete()) {
                $finished->getFuture()->await(new \Amp\TimeoutCancellation(2));
            }
            if ($serverStop !== null && ! $serverStop->isComplete()) {
                $serverStop->await(new \Amp\TimeoutCancellation(2));
            }
            EventLoop::cancel($keepAlive);
        }
    }

    public function test_failed_worker_pool_shutdown_stops_later_teardown_and_retries_only_open_resources(): void
    {
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->expects(self::once())->method('shutdown');
        $firstPool = $this->createMock(WorkerPool::class);
        $firstPool->expects(self::once())->method('shutdown')->willThrowException(new \RuntimeException('private-pool-error'));
        $killCalls = 0;
        $firstPool->expects(self::exactly(2))->method('kill')->willReturnCallback(
            static function () use (&$killCalls): void {
                if (++$killCalls === 1) {
                    throw new \RuntimeException('private-pool-kill-error');
                }
            },
        );
        $firstPool->method('isRunning')->willReturn(false);
        $secondShutdownCalls = 0;
        $secondPool = $this->createMock(WorkerPool::class);
        $secondPool->expects(self::once())->method('shutdown')->willReturnCallback(
            static function () use (&$secondShutdownCalls): void {
                ++$secondShutdownCalls;
            },
        );
        $secondPool->expects(self::never())->method('kill');
        $secondPool->method('isRunning')->willReturn(false);
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage);
        $server->addWorkerPool($firstPool, 'first');
        $server->addWorkerPool($secondPool, 'second');

        try {
            $server->stop();
            self::fail('A failed worker close was reported as complete.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }
        self::assertStringContainsString(
            's3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="first"} 1',
            $server->getMetricsCollector()->renderPrometheus(),
        );
        self::assertSame(0, $secondShutdownCalls);

        try {
            $server->stop();
            self::fail('A failed worker kill was reported as complete.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }
        self::assertSame(0, $secondShutdownCalls);
        self::assertStringContainsString(
            's3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="first"} 2',
            $server->getMetricsCollector()->renderPrometheus(),
        );

        $server->stop();
        $server->stop();
        self::assertSame(1, $secondShutdownCalls);
        self::assertSame(2, $killCalls);
        self::assertStringContainsString(
            's3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="first"} 2',
            $server->getMetricsCollector()->renderPrometheus(),
        );
    }

    public function test_worker_pool_returning_while_still_running_is_killed_before_later_pool(): void
    {
        $running = true;
        $firstPool = $this->createMock(WorkerPool::class);
        $firstPool->expects(self::once())->method('shutdown');
        $firstPool->expects(self::once())->method('kill')->willReturnCallback(static function () use (&$running): void {
            $running = false;
        });
        $firstPool->method('isRunning')->willReturnCallback(static function () use (&$running): bool {
            return $running;
        });
        $laterCalls = 0;
        $secondPool = $this->createMock(WorkerPool::class);
        $secondPool->expects(self::once())->method('shutdown')->willReturnCallback(static function () use (&$laterCalls): void {
            ++$laterCalls;
        });
        $secondPool->method('isRunning')->willReturn(false);
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $server->addWorkerPool($firstPool, 'no_op');
        $server->addWorkerPool($secondPool, 'later');

        try {
            $server->stop();
            self::fail('A still-running worker pool was marked closed.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
        }
        self::assertSame(0, $laterCalls);

        $server->stop();
        self::assertFalse($running);
        self::assertSame(1, $laterCalls);
    }

    public function test_startup_configuration_setters_reject_late_changes(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);

        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->start();
        try {
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $handler = new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            };
            $middleware = new class implements Middleware {
                public function handleRequest(Request $request, RequestHandler $requestHandler): Response
                {
                    return $requestHandler->handleRequest($request);
                }
            };
            $unownedPool = $this->createMock(WorkerPool::class);
            $unownedPool->expects(self::never())->method('shutdown');
            foreach ([
                static fn() => $server->setStorageTierRegistry(StorageTierRegistry::single(new InMemoryBackend())),
                static fn() => $server->setNotificationDispatcher(new NotificationDispatcher($metadata)),
                static fn() => $server->setAdminCredentialApiHandler($handler),
                static fn() => $server->setAdminQuotaApiHandler($handler),
                static fn() => $server->addMiddleware($middleware),
                static fn() => $server->addWorkerPool($unownedPool, 'late'),
            ] as $mutate) {
                try {
                    $mutate();
                    self::fail('Expected a late server configuration change to be rejected.');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('before the S3 server starts', $e->getMessage());
                }
            }
        } finally {
            $server->stop();
        }
        foreach ([
            static fn() => $server->setNotificationDispatcher(new NotificationDispatcher($metadata)),
            static fn() => $server->addWorkerPool($unownedPool, 'after_stop'),
        ] as $mutate) {
            try {
                $mutate();
                self::fail('Expected a stopped server to reject new owned resources.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('before the S3 server starts', $error->getMessage());
            }
        }
        $server->stop();
    }

    public function test_logger_failures_during_startup_and_shutdown_are_isolated(): void
    {
        $port = $this->unusedPort();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ((string) $message === 'Lifecycle runner started.' && $context === []) {
                    throw new \RuntimeException('startup-sentinel');
                }
                if (str_starts_with((string) $message, 'Stopping S3 server')) {
                    throw new \RuntimeException('stop-logger-sentinel');
                }
                if ((string) $message === 'S3 server stopped.') {
                    throw new \RuntimeException('cleanup-sentinel');
                }
            }
        };
        $server = new S3Server(
            new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: sys_get_temp_dir(), notificationWorkerEnabled: false),
            metadata: $metadata,
            storage: new InMemoryBackend(),
            logger: $logger,
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::assertTrue($this->canConnect($port));
        } finally {
            $server->stop();
        }
        self::assertFalse($this->canConnect($port));
        self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
    }

    public function test_background_preparation_logger_failure_does_not_interrupt_startup(): void
    {
        $port = $this->unusedPort();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $logger = new class extends AbstractLogger {
            public bool $failPreparation = true;

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($this->failPreparation && (string) $message === 'Background resources prepared.') {
                    $this->failPreparation = false;
                    throw new \RuntimeException('background-preparation-sentinel');
                }
            }
        };
        $server = new S3Server(
            new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: sys_get_temp_dir(), notificationWorkerEnabled: true),
            metadata: $metadata,
            storage: new InMemoryBackend(),
            logger: $logger,
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::assertFalse($logger->failPreparation);
            self::assertTrue($this->canConnect($port));
        } finally {
            $server->stop();
        }
    }

    public function test_stop_closes_listener_before_waiting_for_background_worker_and_drains_active_request(): void
    {
        $port = $this->unusedPort();
        $workerStarted = new DeferredFuture();
        $releaseWorker = new DeferredFuture();
        $requestStarted = new DeferredFuture();
        $releaseRequest = new DeferredFuture();
        $metadata = $this->capableMetadataMock();
        $metadata->method('dequeueNotifications')->willReturnCallback(
            static function () use ($workerStarted, $releaseWorker): array {
                if (! $workerStarted->isComplete()) {
                    $workerStarted->complete();
                }
                $releaseWorker->getFuture()->await();

                return [];
            },
        );
        $handler = new class ($requestStarted, $releaseRequest) implements RequestHandler {
            /**
             * @param  DeferredFuture<void>  $started
             * @param  DeferredFuture<void>  $release
             */
            public function __construct(private readonly DeferredFuture $started, private readonly DeferredFuture $release) {}

            public function handleRequest(Request $request): Response
            {
                $this->started->complete();
                $this->release->getFuture()->await();

                return new Response(200, body: 'drained');
            }
        };
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            shutdownDrainTimeout: 1,
        ), metadata: $metadata);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setAdminCredentialApiHandler($handler);
        $server->start();

        try {
            $workerStarted->getFuture()->await(new \Amp\TimeoutCancellation(2));
            $client = HttpClientBuilder::buildDefault();
            $requestFuture = \Amp\async(static fn() => $client->request(new ClientRequest("http://127.0.0.1:{$port}/.admin/credentials", 'POST')));
            $requestStarted->getFuture()->await(new \Amp\TimeoutCancellation(2));
            $stopFuture = \Amp\async(static fn() => $server->stop());
            \Amp\delay(0.02);

            self::assertFalse($this->canConnect($port));
            self::assertFalse($requestFuture->isComplete());
            self::assertFalse($stopFuture->isComplete());

            $releaseRequest->complete();
            $response = $requestFuture->await(new \Amp\TimeoutCancellation(2));
            self::assertSame(200, $response->getStatus());
            self::assertSame('drained', \Amp\ByteStream\buffer($response->getBody()));
            self::assertFalse($stopFuture->isComplete());
            self::assertFalse($this->canConnect($port));
        } finally {
            if (! $releaseRequest->isComplete()) {
                $releaseRequest->complete();
            }
            if (! $releaseWorker->isComplete()) {
                $releaseWorker->complete();
            }
            if (isset($stopFuture)) {
                $stopFuture->await(new \Amp\TimeoutCancellation(2));
            } else {
                $server->stop();
            }
        }
    }

    public function test_http_drain_timeout_returns_without_releasing_dependencies_and_retry_finishes_cleanup(): void
    {
        $port = $this->unusedPort();
        $requestStarted = new DeferredFuture();
        $releaseRequest = new DeferredFuture();
        $handler = new class ($requestStarted, $releaseRequest) implements RequestHandler {
            /**
             * @param  DeferredFuture<void>  $started
             * @param  DeferredFuture<void>  $release
             */
            public function __construct(private readonly DeferredFuture $started, private readonly DeferredFuture $release) {}

            public function handleRequest(Request $request): Response
            {
                $this->started->complete();
                $this->release->getFuture()->await();

                return new Response(200, body: 'finished');
            }
        };
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            shutdownDrainTimeout: 1,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setAdminCredentialApiHandler($handler);
        $server->start();

        $client = HttpClientBuilder::buildDefault();
        $requestFuture = \Amp\async(static fn() => $client->request(new ClientRequest("http://127.0.0.1:{$port}/.admin/credentials", 'POST')));
        $requestStarted->getFuture()->await(new \Amp\TimeoutCancellation(2));
        $startedAt = microtime(true);

        try {
            $server->stop();
            self::fail('A request beyond the shutdown drain deadline must fail the stop attempt.');
        } catch (\RuntimeException $error) {
            self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
            self::assertLessThan(1.8, microtime(true) - $startedAt);
            self::assertNotNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertFalse($this->canConnect($port));
        } finally {
            $releaseRequest->complete();
        }

        $response = $requestFuture->await(new \Amp\TimeoutCancellation(2));
        self::assertSame('finished', \Amp\ByteStream\buffer($response->getBody()));
        $server->stop();
        self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
    }

    public function test_runtime_drain_keeps_probes_open_rejects_new_work_then_closes_listener(): void
    {
        $port = $this->unusedPort();
        $adminCalls = 0;
        $quotaCalls = 0;
        $admin = new class ($adminCalls) implements RequestHandler {
            public function __construct(private int &$calls) {}

            public function handleRequest(Request $request): Response
            {
                ++$this->calls;

                return new Response(204);
            }
        };
        $quota = new class ($quotaCalls) implements RequestHandler {
            public function __construct(private int &$calls) {}

            public function handleRequest(Request $request): Response
            {
                ++$this->calls;

                return new Response(204);
            }
        };
        $config = new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            shutdownDrainTimeout: 2,
            shutdownReadinessDelay: 1,
            notificationWorkerEnabled: false,
        );
        $server = new S3Server($config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setAdminCredentialApiHandler($admin);
        $server->setAdminQuotaApiHandler($quota);
        $notificationMetadata = new SqliteMetadataStore(':memory:');
        $notificationMetadata->initialize();
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher($notificationMetadata));
        $server->start();
        $client = HttpClientBuilder::buildDefault();

        $stop = \Amp\async($runtime->stop(...));
        \Amp\delay(0.05);

        self::assertFalse($stop->isComplete());
        self::assertTrue($this->canConnect($port));
        self::assertSame(200, $client->request(new ClientRequest("http://127.0.0.1:{$port}/.live"))->getStatus());
        self::assertSame(503, $client->request(new ClientRequest("http://127.0.0.1:{$port}/.health"))->getStatus());
        self::assertSame(200, $client->request(new ClientRequest("http://127.0.0.1:{$port}/.metrics"))->getStatus());

        $s3 = $client->request(new ClientRequest("http://127.0.0.1:{$port}/bucket/key"));
        self::assertSame(503, $s3->getStatus());
        self::assertStringContainsString('<Code>SlowDown</Code>', \Amp\ByteStream\buffer($s3->getBody()));
        $adminResponse = $client->request(new ClientRequest(
            "http://127.0.0.1:{$port}/.admin/credentials",
            'POST',
        ));
        self::assertSame(503, $adminResponse->getStatus());
        self::assertSame('application/json', $adminResponse->getHeader('content-type'));
        self::assertSame(0, $adminCalls);
        $quotaResponse = $client->request(new ClientRequest(
            "http://127.0.0.1:{$port}/.admin/quotas",
        ));
        self::assertSame(503, $quotaResponse->getStatus());
        self::assertSame('application/json', $quotaResponse->getHeader('content-type'));
        self::assertLessThan(128, strlen(\Amp\ByteStream\buffer($quotaResponse->getBody())));
        self::assertSame(0, $quotaCalls);

        $stop->await(new \Amp\TimeoutCancellation(3));
        self::assertFalse($this->canConnect($port));
    }

    public function test_node_temp_capacity_drives_real_readiness_and_metrics_routes(): void
    {
        $root = sys_get_temp_dir() . '/s3-node-temp-routes-' . bin2hex(random_bytes(8));
        $managed = $root . '/managed';
        self::assertTrue(mkdir($managed, 0700, true));
        $port = $this->unusedPort();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: $root,
            storageTempDir: $root,
            storageTempBudgetBytes: 10,
            storageTempReserveBytes: 0,
            perClientRateLimit: 0,
            notificationWorkerEnabled: false,
        ));
        $server->storageTempBudgetCoordinator()->registerNamespace('route_test', $managed);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->start();

        try {
            self::assertSame(1, file_put_contents($managed . '/unaccounted', 'x'));
            $client = HttpClientBuilder::buildDefault();

            $health = $client->request(new ClientRequest("http://127.0.0.1:{$port}/.health"));
            self::assertSame(503, $health->getStatus());
            self::assertStringContainsString('node_temp_capacity', \Amp\ByteStream\buffer($health->getBody()));

            $metrics = $client->request(new ClientRequest("http://127.0.0.1:{$port}/.metrics"));
            self::assertSame(200, $metrics->getStatus());
            $rendered = \Amp\ByteStream\buffer($metrics->getBody());
            self::assertStringContainsString('s3_server_node_temp_budget_bytes 10', $rendered);
            self::assertStringContainsString('s3_server_node_temp_reserved_bytes 0', $rendered);
            self::assertStringContainsString('s3_server_node_temp_active_reservations 0', $rendered);
            self::assertStringContainsString('s3_server_node_temp_unsafe_entries 1', $rendered);
            self::assertDoesNotMatchRegularExpression('/^s3_server_node_temp_[^ ]+\{/m', $rendered);
        } finally {
            $server->stop();
            @unlink($managed . '/unaccounted');
            @rmdir($managed);
            foreach (glob($root . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($root . '/.opsfour-s3-storage-budget');
            @rmdir($root);
        }
    }

    public function test_stream_admitted_before_drain_finishes_while_new_work_is_rejected(): void
    {
        $port = $this->unusedPort();
        $pipe = new Pipe(1024);
        $requestStarted = new DeferredFuture();
        $releaseStream = new DeferredFuture();
        $handlerCalls = 0;
        $handler = new class ($pipe, $requestStarted, $handlerCalls) implements RequestHandler {
            /** @param DeferredFuture<void> $requestStarted */
            public function __construct(
                private readonly Pipe $pipe,
                private readonly DeferredFuture $requestStarted,
                private int &$handlerCalls,
            ) {}

            public function handleRequest(Request $request): Response
            {
                ++$this->handlerCalls;
                $this->requestStarted->complete();

                return new Response(200, body: $this->pipe->getSource());
            }
        };
        $config = new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            shutdownDrainTimeout: 2,
            shutdownReadinessDelay: 0,
            notificationWorkerEnabled: false,
        );
        $server = new S3Server($config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setAdminCredentialApiHandler($handler);
        $notificationMetadata = new SqliteMetadataStore(':memory:');
        $notificationMetadata->initialize();
        $runtime = new S3ServerRuntime($server, new NotificationDispatcher($notificationMetadata));
        $server->start();
        $client = HttpClientBuilder::buildDefault();
        $writer = \Amp\async(static function () use ($pipe, $releaseStream): void {
            $pipe->getSink()->write('accepted-');
            $releaseStream->getFuture()->await();
            $pipe->getSink()->write('stream');
            $pipe->getSink()->end();
        });

        $response = $client->request(new ClientRequest(
            "http://127.0.0.1:{$port}/.admin/credentials",
            'POST',
        ));
        $requestStarted->getFuture()->await(new \Amp\TimeoutCancellation(2));
        self::assertSame(200, $response->getStatus());
        self::assertSame('accepted-', $response->getBody()->read());

        $runtime->beginDrain();
        $rejected = $client->request(new ClientRequest(
            "http://127.0.0.1:{$port}/.admin/credentials",
            'POST',
        ));
        self::assertSame(503, $rejected->getStatus());
        self::assertSame(1, $handlerCalls);

        $stop = \Amp\async($runtime->stop(...));
        \Amp\delay(0.02);
        self::assertFalse($stop->isComplete());

        $releaseStream->complete();
        self::assertSame('stream', \Amp\ByteStream\buffer($response->getBody()));
        $writer->await(new \Amp\TimeoutCancellation(2));
        $stop->await(new \Amp\TimeoutCancellation(2));
        self::assertFalse($this->canConnect($port));
    }

    private function unusedPort(): int
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        fclose($reservation);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function canConnect(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorCode, $error, 0.1);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function capableMetadataMock(): MetadataStore&ObjectPartLayoutStore&MockObject
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);

        return $metadata;
    }

    private function layoutCapabilityMetadataMock(bool $disabled): MetadataStore&MockObject
    {
        if (! $disabled) {
            return $this->createMock(MetadataStore::class);
        }
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(false);

        return $metadata;
    }
}
