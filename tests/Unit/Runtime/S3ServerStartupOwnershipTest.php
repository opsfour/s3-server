<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\DeferredCancellation;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\SocketHttpServer;
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

final class S3ServerStartupOwnershipTest extends TestCase
{
    public function test_failed_start_keeps_registered_worker_pool_for_shutdown_retry_and_allows_reconfiguration(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $stops = 0;
        $pool->method('shutdown')->willReturnCallback(static function () use (&$stops): void {
            if (++$stops === 1) {
                throw new \RuntimeException('injected pool close failure');
            }
        });

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
        self::assertSame(2, $stops);
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
        ), storage: new InMemoryBackend(), logger: $logger);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        return $server;
    }
}
