<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class MetricsHandlerTest extends TestCase
{
    public function test_configured_bearer_token_is_required(): void
    {
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: 'secret-token');

        self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request('Bearer secret-token'))->getStatus());
    }

    private function request(?string $authorization = null): Request
    {
        return new Request(
            new MetricsHandlerTestClient(),
            'GET',
            Http::new('http://127.0.0.1/metrics'),
            $authorization !== null ? ['authorization' => $authorization] : [],
        );
    }
}

final class MetricsHandlerTestClient implements Client
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
