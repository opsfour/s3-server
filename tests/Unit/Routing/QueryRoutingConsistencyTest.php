<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Routing;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use League\Uri\Http;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Routing\S3Router;
use PHPUnit\Framework\TestCase;

final class QueryRoutingConsistencyTest extends TestCase
{
    public function test_duplicate_key_only_query_does_not_override_first_value_in_attribute_middleware(): void
    {
        $request = $this->request();

        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());

        self::assertSame(S3Operation::ListObjectsV2, $request->getAttribute('s3.operation'));
    }

    public function test_duplicate_key_only_query_does_not_override_first_value_in_legacy_router(): void
    {
        $request = $this->request();
        $registry = new HandlerRegistry();
        $registry->register(S3Operation::ListObjectsV2, $this->terminal());

        $response = (new S3Router($registry))->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame(S3Operation::ListObjectsV2, $request->getAttribute('s3.operation'));
    }

    private function request(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket-a?list-type=2&list-type'),
        );
        $request->setAttribute(Router::class, ['bucket' => 'bucket-a']);

        return $request;
    }

    private function terminal(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        };
    }
}
