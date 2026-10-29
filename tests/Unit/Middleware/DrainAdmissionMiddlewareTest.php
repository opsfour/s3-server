<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Middleware\DrainAdmissionMiddleware;
use OpsFour\S3Server\Runtime\NodeDrainController;
use PHPUnit\Framework\TestCase;

final class DrainAdmissionMiddlewareTest extends TestCase
{
    public function test_admission_is_decided_once_and_existing_work_is_not_revoked(): void
    {
        $drain = new NodeDrainController();
        $calls = 0;
        $handler = new class ($drain, $calls) implements RequestHandler {
            public function __construct(
                private readonly NodeDrainController $drain,
                private int &$calls,
            ) {}

            public function handleRequest(Request $request): Response
            {
                ++$this->calls;
                $this->drain->beginDrain();

                return new Response(200, body: 'accepted');
            }
        };
        $gate = new DrainAdmissionMiddleware($drain);

        $accepted = $gate->handleRequest($this->request('PUT', '/bucket/key'), $handler);
        self::assertSame(200, $accepted->getStatus());
        self::assertSame('accepted', ByteStream\buffer($accepted->getBody()));

        $rejected = $gate->handleRequest($this->request('PUT', '/bucket/key'), $handler);
        self::assertSame(503, $rejected->getStatus());
        self::assertSame('1', $rejected->getHeader('retry-after'));
        self::assertStringContainsString('<Code>SlowDown</Code>', ByteStream\buffer($rejected->getBody()));
        self::assertSame(1, $calls);
    }

    public function test_management_rejection_is_bounded_json_and_never_calls_handler(): void
    {
        $drain = new NodeDrainController();
        $drain->beginDrain();
        $handler = $this->createMock(RequestHandler::class);
        $handler->expects(self::never())->method('handleRequest');

        $response = (new DrainAdmissionMiddleware($drain, managementRequest: true))
            ->handleRequest($this->request('DELETE', '/.admin/credentials/key'), $handler);

        self::assertSame(503, $response->getStatus());
        self::assertSame('application/json', $response->getHeader('content-type'));
        self::assertLessThan(128, strlen(ByteStream\buffer($response->getBody())));
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $path): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://localhost' . $path));
        $request->setAttribute('requestId', 'request-id');

        return $request;
    }
}
