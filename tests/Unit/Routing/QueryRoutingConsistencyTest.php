<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Routing;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\NotImplementedException;
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

    public function test_attribute_middleware_rejects_wrong_method_for_object_subresource(): void
    {
        $request = $this->request(
            'DELETE',
            'http://127.0.0.1/bucket-a/key?retention',
            ['bucket' => 'bucket-a', 'key' => 'key'],
        );

        $this->expectException(MethodNotAllowedException::class);
        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
    }

    public function test_legacy_router_rejects_wrong_method_for_bucket_subresource(): void
    {
        $request = $this->request('DELETE', 'http://127.0.0.1/bucket-a?acl');

        $this->expectException(MethodNotAllowedException::class);
        (new S3Router(new HandlerRegistry()))->handleRequest($request);
    }

    public function test_percent_encoded_conflicting_subresource_is_rejected(): void
    {
        $request = $this->request('GET', 'http://127.0.0.1/bucket-a?%61cl&policy');

        $this->expectException(InvalidArgumentException::class);
        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
    }

    public function test_documented_unsupported_subresource_does_not_reach_legacy_dispatch(): void
    {
        $request = $this->request(
            'DELETE',
            'http://127.0.0.1/bucket-a/key?annotation',
            ['bucket' => 'bucket-a', 'key' => 'key'],
        );

        $this->expectException(NotImplementedException::class);
        (new S3Router(new HandlerRegistry()))->handleRequest($request);
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $routeArgs
     */
    private function request(
        string $method = 'GET',
        string $uri = 'http://127.0.0.1/bucket-a?list-type=2&list-type',
        array $routeArgs = ['bucket' => 'bucket-a'],
    ): Request {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new($uri),
        );
        $request->setAttribute(Router::class, $routeArgs);

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
