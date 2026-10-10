<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Middleware\RequestIdMiddleware;
use PHPUnit\Framework\TestCase;

final class RequestIdMiddlewareTest extends TestCase
{
    public function test_bodyless_responses_have_unambiguous_http_one_framing(): void
    {
        foreach ([204, 304] as $status) {
            $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket'));
            $response = (new RequestIdMiddleware())->handleRequest(
                $request,
                new class ($status) implements RequestHandler {
                    public function __construct(private readonly int $status) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response($this->status, body: 'must not be sent');
                    }
                },
            );

            self::assertSame('close', $response->getHeader('Connection'));
            self::assertNull($response->getHeader('Content-Length'));
            self::assertNull($response->getHeader('Transfer-Encoding'));
            self::assertSame('', \Amp\ByteStream\buffer($response->getBody()));
            self::assertNotNull($response->getHeader('x-amz-request-id'));
        }
    }

    public function test_normal_response_keeps_its_length_and_reusable_connection(): void
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket'));
        $response = (new RequestIdMiddleware())->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'body');
                }
            },
        );

        self::assertSame('4', $response->getHeader('Content-Length'));
        self::assertNull($response->getHeader('Connection'));
    }

    public function test_http_two_bodyless_response_has_no_connection_header(): void
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('https://127.0.0.1/bucket'));
        $request->setProtocolVersion('2');
        $response = (new RequestIdMiddleware())->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204, body: 'must not be sent');
                }
            },
        );

        self::assertNull($response->getHeader('Connection'));
        self::assertNull($response->getHeader('Content-Length'));
        self::assertNull($response->getHeader('Transfer-Encoding'));
        self::assertSame('', \Amp\ByteStream\buffer($response->getBody()));
    }
}
