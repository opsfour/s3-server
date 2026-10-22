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
use OpsFour\S3Server\Exception\InvalidBucketNameException;
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

    public function test_attribute_middleware_extracts_object_scope_without_router_arguments(): void
    {
        $request = $this->request(
            'GET',
            'http://127.0.0.1/bucket-a/folder%2Fobject',
            [],
        );

        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());

        self::assertSame('bucket-a', $request->getAttribute('s3.bucket'));
        self::assertSame('folder/object', $request->getAttribute('s3.key'));
        self::assertSame(S3Operation::GetObject, $request->getAttribute('s3.operation'));
    }

    public function test_attribute_middleware_rejects_invalid_utf8_in_decoded_object_key(): void
    {
        $request = $this->request('GET', 'http://127.0.0.1/bucket-a/object%FF');

        try {
            (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
            self::fail('Expected an invalid UTF-8 object key to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('InvalidArgument', $exception->getErrorCode());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidXmlObjectKeys')]
    public function test_attribute_middleware_rejects_invalid_xml_text_in_decoded_object_key(string $encodedKey): void
    {
        $request = $this->request('GET', 'http://127.0.0.1/bucket-a/' . $encodedKey);

        try {
            (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
            self::fail('Expected an invalid XML object key to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('InvalidArgument', $exception->getErrorCode());
        }
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function invalidXmlObjectKeys(): iterable
    {
        yield 'NUL' => ['object%00key'];
        yield 'XML control' => ['object%0Bkey'];
    }

    public function test_options_rejects_invalid_xml_text_before_cors_middleware(): void
    {
        $request = $this->request('OPTIONS', 'http://127.0.0.1/bucket-a/object%0Bkey');

        try {
            (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
            self::fail('Expected an invalid XML object key in a CORS preflight to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('InvalidArgument', $exception->getErrorCode());
        }
    }

    public function test_attribute_middleware_accepts_valid_unicode_object_key(): void
    {
        $request = $this->request('GET', 'http://127.0.0.1/bucket-a/%E2%98%83/%E6%96%87%E4%BB%B6');

        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());

        self::assertSame("\u{2603}/\u{6587}\u{4EF6}", $request->getAttribute('s3.key'));
        self::assertSame(S3Operation::GetObject, $request->getAttribute('s3.operation'));
    }

    public function test_decoded_control_character_cannot_alias_another_bucket(): void
    {
        $request = $this->request(
            'GET',
            'http://127.0.0.1/bucket-a%00/object',
            [],
        );

        $this->expectException(InvalidBucketNameException::class);
        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedBucketSeparators')]
    public function test_encoded_separator_in_bucket_segment_fails_closed(string $encodedBucket): void
    {
        $request = $this->request(
            'GET',
            'http://127.0.0.1/' . $encodedBucket . '/object',
            [],
        );

        $this->expectException(InvalidBucketNameException::class);
        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());
    }

    /** @return iterable<string, array{string}> */
    public static function encodedBucketSeparators(): iterable
    {
        yield 'encoded slash' => ['bucket-a%2Fhidden'];
        yield 'double encoded slash' => ['bucket-a%252Fhidden'];
        yield 'encoded backslash' => ['bucket-a%5Chidden'];
        yield 'double encoded backslash' => ['bucket-a%255Chidden'];
        yield 'encoding beyond inspection limit' => ['bucket-a%2525252541hidden'];
    }

    public function test_bucket_encoding_at_inspection_limit_is_processed_in_constant_passes(): void
    {
        $request = $this->request(
            'GET',
            'http://127.0.0.1/bucket-a%25252541/object',
            [],
        );

        (new S3AttributeMiddleware())->handleRequest($request, $this->terminal());

        self::assertSame('bucket-a%252541', $request->getAttribute('s3.bucket'));
        self::assertSame('object', $request->getAttribute('s3.key'));
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

    public function test_legacy_router_rejects_an_invalid_object_key_before_dispatch(): void
    {
        $request = $this->request(
            'GET',
            'http://127.0.0.1/bucket-a/object%0Bkey',
            [],
        );
        $registry = new HandlerRegistry();
        $registry->register(S3Operation::GetObject, $this->terminal());

        $this->expectException(InvalidArgumentException::class);
        (new S3Router($registry))->handleRequest($request);
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
