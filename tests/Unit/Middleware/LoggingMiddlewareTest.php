<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Middleware\LoggingMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class LoggingMiddlewareTest extends TestCase
{
    public function test_successful_request_is_logged_with_response_status(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $middleware = new LoggingMiddleware($logger);

        $response = $middleware->handleRequest(
            $this->request(),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204);
                }
            },
        );

        self::assertSame(204, $response->getStatus());
        self::assertSame(204, $logger->records[0]['context']['status']);
    }

    public function test_s3_exception_is_logged_before_it_is_rethrown(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $middleware = new LoggingMiddleware($logger);

        try {
            $middleware->handleRequest(
                $this->request(),
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        throw new NoSuchBucketException();
                    }
                },
            );
            self::fail('Expected the downstream S3 exception.');
        } catch (NoSuchBucketException) {
            self::assertSame(404, $logger->records[0]['context']['status']);
            self::assertSame('GET', $logger->records[0]['context']['method']);
        }
    }

    public function test_presigned_credentials_and_signatures_are_redacted(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $middleware = new LoggingMiddleware($logger);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'GET',
            Http::new(
                'http://127.0.0.1/bucket/key'
                . '?X-Amz-Credential=AKIA%2Fscope'
                . '&X-Amz-Signature=secret'
                . '&X-Amz-Security-Token=session'
                . '&versionId=visible',
            ),
        );

        $middleware->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        });

        $path = (string) $logger->records[0]['context']['path'];
        self::assertStringNotContainsString('AKIA', $path);
        self::assertStringNotContainsString('secret', $path);
        self::assertStringNotContainsString('session', $path);
        self::assertStringContainsString('versionId=visible', $path);
        self::assertSame(3, substr_count($path, '%5BREDACTED%5D'));
    }

    private function request(): Request
    {
        return new Request(
            new LoggingMiddlewareTestClient(),
            'GET',
            Http::new('http://127.0.0.1/test-bucket/missing.txt'),
        );
    }
}

final class LoggingMiddlewareTestClient implements Client
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

final class LoggingMiddlewareArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}
