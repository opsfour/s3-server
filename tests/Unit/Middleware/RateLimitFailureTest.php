<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

final class RateLimitFailureTest extends TestCase
{
    public function test_metadata_error_fails_closed_without_exposing_backend_details(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('rateLimitCheck')
            ->with('192.0.2.55', 2.0, 2.0)
            ->willThrowException(new \RuntimeException('postgres://user:secret@db.example/internal'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')
            ->with(LogLevel::ERROR, 'Rate-limit metadata check failed.', ['exception' => \RuntimeException::class]);
        $terminal = new RateLimitTerminalHandler();

        $response = (new RateLimitMiddleware($metadata, 2.0, $logger))->handleRequest($this->request(), $terminal);
        $body = \Amp\ByteStream\buffer($response->getBody());

        self::assertSame(503, $response->getStatus());
        self::assertSame('1', $response->getHeader('Retry-After'));
        self::assertSame('rate-failure-test', $response->getHeader('x-amz-request-id'));
        self::assertStringContainsString('<Code>SlowDown</Code>', $body);
        self::assertStringNotContainsString('secret', $body);
        self::assertFalse($terminal->handled);
    }

    public function test_disabled_limit_bypasses_metadata_failure(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('rateLimitCheck');
        $terminal = new RateLimitTerminalHandler();

        $response = (new RateLimitMiddleware($metadata, 0.0))->handleRequest($this->request(), $terminal);

        self::assertSame(204, $response->getStatus());
        self::assertTrue($terminal->handled);
    }

    public function test_absent_metadata_store_keeps_unconfigured_path_available(): void
    {
        $terminal = new RateLimitTerminalHandler();

        $response = (new RateLimitMiddleware(null, 2.0))->handleRequest($this->request(), $terminal);

        self::assertSame(204, $response->getStatus());
        self::assertTrue($terminal->handled);
    }

    private function request(): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('192.0.2.55', 12345));
        $request = new Request($client, 'GET', Http::new('http://localhost/bucket/key'));
        $request->setAttribute('requestId', 'rate-failure-test');

        return $request;
    }
}

final class RateLimitTerminalHandler implements RequestHandler
{
    public bool $handled = false;

    public function handleRequest(Request $request): Response
    {
        $this->handled = true;

        return new Response(204);
    }
}
