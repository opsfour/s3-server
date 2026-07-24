<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class HealthCheckHandlerTest extends TestCase
{
    public function test_liveness_without_dependencies_is_ok(): void
    {
        $response = (new HealthCheckHandler())->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        self::assertSame('{"status":"ok"}', $response->getBody()->read());
    }

    public function test_readiness_probes_storage_without_writing(): void
    {
        $storage = new CallbackStorageBackend(new InMemoryBackend());
        $handler = new HealthCheckHandler(
            storageTiers: StorageTierRegistry::single($storage),
        );

        $response = $handler->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        self::assertNull($storage->lastWrite);
    }

    public function test_readiness_is_degraded_when_storage_probe_fails(): void
    {
        $logger = new HealthCheckArrayLogger();
        $storage = new CallbackStorageBackend(
            new InMemoryBackend(),
            beforeBucketExists: static function (): never {
                throw new \RuntimeException('storage unavailable');
            },
        );
        $handler = new HealthCheckHandler(
            logger: $logger,
            storageTiers: StorageTierRegistry::single($storage),
        );

        $response = $handler->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
        self::assertSame('storage:STANDARD', $logger->records[0]['context']['component']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    private function request(): Request
    {
        return new Request(
            new HealthCheckHandlerTestClient(),
            'GET',
            Http::new('http://127.0.0.1/.health'),
        );
    }
}

final class HealthCheckHandlerTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}

final class HealthCheckArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
