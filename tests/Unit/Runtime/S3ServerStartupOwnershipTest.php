<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class S3ServerStartupOwnershipTest extends TestCase
{
    public function test_failed_start_keeps_registered_worker_pool_for_forced_shutdown_retry_and_allows_reconfiguration(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $stops = 0;
        $pool->expects(self::once())->method('shutdown')->willReturnCallback(static function () use (&$stops): void {
            ++$stops;
            throw new \RuntimeException('injected pool close failure');
        });
        $pool->expects(self::once())->method('kill');
        $pool->method('isRunning')->willReturn(false);

        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $server = $this->server(new NullLogger(), $port);
        $server->addWorkerPool($pool, 'injected');
        try {
            try {
                $server->start();
                self::fail('Occupied listener unexpectedly accepted startup.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('Address already in use', $error->getMessage());
            }
            self::assertSame(0, $stops);
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertGreaterThan(0, count((new \ReflectionProperty($server, 'workerPools'))->getValue($server)));
            $server->getHandlerRegistry()->register(S3Operation::PutObject, $this->createMock(RequestHandler::class));

            fclose($reservation);
            $reservation = null;
            try {
                $server->stop();
                self::fail('First shutdown unexpectedly ignored the injected pool failure.');
            } catch (\RuntimeException $error) {
                self::assertSame('S3 server shutdown incomplete; dependencies remain owned.', $error->getMessage());
            }
            self::assertSame(1, $stops);
            $server->stop();
        } finally {
            if (is_resource($reservation)) {
                fclose($reservation);
            }
        }
    }

    public function test_failed_http_stop_keeps_server_and_workers_owned_until_shutdown_retry(): void
    {
        $logger = new class extends NullLogger {
            public ?S3Server $server = null;

            public bool $installed = false;

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($this->installed || ! str_starts_with((string) $message, 'Listening on http://')) {
                    return;
                }
                if ($this->server === null) {
                    throw new \LogicException('Test server was not assigned.');
                }
                $http = (new \ReflectionProperty($this->server, 'server'))->getValue($this->server);
                if (! $http instanceof SocketHttpServer) {
                    throw new \LogicException('HTTP server was not assigned.');
                }
                $http->onStop(static function (): void {
                    throw new \RuntimeException('injected HTTP stop failure');
                });
                $cancellation = (new \ReflectionProperty($this->server, 'startupCancellation'))->getValue($this->server);
                if (! $cancellation instanceof DeferredCancellation) {
                    throw new \LogicException('Startup cancellation was not assigned.');
                }
                $cancellation->cancel();
                $this->installed = true;
            }
        };

        $server = $this->server($logger);
        $logger->server = $server;
        try {
            $server->start();
            self::fail('HTTP stop failure was not reported.');
        } catch (\RuntimeException $error) {
            self::assertTrue($logger->installed);
            self::assertSame('S3 server startup cleanup incomplete; dependencies remain owned.', $error->getMessage());
            self::assertInstanceOf(SocketHttpServer::class, (new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertGreaterThan(0, count((new \ReflectionProperty($server, 'workerPools'))->getValue($server)));
            self::assertSame([], (new \ReflectionProperty($server, 'closedWorkerPools'))->getValue($server));
            try {
                $server->getHandlerRegistry()->register(S3Operation::PutObject, $this->createMock(RequestHandler::class));
                self::fail('Incomplete HTTP cleanup unfroze the registry.');
            } catch (\LogicException $frozen) {
                self::assertStringContainsString('cannot be changed after the server starts', $frozen->getMessage());
            }
        } finally {
            $server->stop();
        }
        self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
    }

    public function test_startup_pool_shutdown_failure_is_measured_and_force_killed_on_stop_retry(): void
    {
        $logger = new class extends NullLogger {
            public ?S3Server $server = null;

            public ?ContextWorkerPool $pool = null;

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($this->pool !== null || (string) $message !== 'Background resources prepared.') {
                    return;
                }
                if ($this->server === null) {
                    throw new \LogicException('Test server was not assigned.');
                }
                $registered = (new \ReflectionProperty($this->server, 'workerPools'))->getValue($this->server);
                if (! is_array($registered) || $registered === []) {
                    throw new \LogicException('Startup worker pool was not registered.');
                }
                $entry = $registered[array_key_last($registered)];
                $pool = $entry['pool'] ?? null;
                if (! $pool instanceof ContextWorkerPool) {
                    throw new \LogicException('Startup worker pool has an unexpected type.');
                }
                $this->pool = $pool;
                $exit = new DeferredFuture();
                (new \ReflectionProperty($pool, 'exitStatus'))->setValue($pool, $exit->getFuture());
                $cancellation = (new \ReflectionProperty($pool, 'deferredCancellation'))->getValue($pool);
                if (! $cancellation instanceof DeferredCancellation) {
                    throw new \LogicException('Startup worker cancellation was not assigned.');
                }
                EventLoop::queue(static function () use ($cancellation, $exit): void {
                    $cancellation->cancel();
                    $exit->error(new \RuntimeException('injected startup pool close failure'));
                });
            }
        };

        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $server = $this->server($logger, $port);
        $logger->server = $server;

        try {
            try {
                $server->start();
                self::fail('Startup worker shutdown failure was reported as complete.');
            } catch (\RuntimeException $error) {
                self::assertSame('S3 server startup cleanup incomplete; dependencies remain owned.', $error->getMessage());
            }
            self::assertInstanceOf(ContextWorkerPool::class, $logger->pool);
            self::assertFalse($logger->pool->isRunning());
            self::assertStringContainsString(
                's3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="request_body_spool"} 1',
                $server->getMetricsCollector()->renderPrometheus(),
            );

            $server->stop();
            self::assertSame([], (new \ReflectionProperty($server, 'failedWorkerPoolShutdowns'))->getValue($server));
            self::assertStringContainsString(
                's3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="request_body_spool"} 1',
                $server->getMetricsCollector()->renderPrometheus(),
            );
        } finally {
            fclose($reservation);
        }
    }

    private function server(NullLogger $logger, ?int $port = null): S3Server
    {
        if ($port === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertIsResource($socket);
            $address = stream_socket_get_name($socket, false);
            self::assertIsString($address);
            $port = (int) substr($address, strrpos($address, ':') + 1);
            fclose($socket);
        }

        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            allowUnfencedStorageForTests: true,
            perClientRateLimit: 0,
        ), storage: new InMemoryBackend(), logger: $logger);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        return $server;
    }
}
