<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Middleware\RequestHeaderContractMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestHeaderContractMiddlewareTest extends TestCase
{
    /** @param non-empty-string $header */
    #[DataProvider('singletonHeaders')]
    public function test_repeated_singletons_are_rejected_before_dispatch(string $header): void
    {
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };

        try {
            (new RequestHeaderContractMiddleware())->handleRequest(
                $this->request([$header => ['first', 'second']]),
                $handler,
            );
            self::fail("Repeated {$header} was accepted.");
        } catch (InvalidArgumentException $error) {
            self::assertSame("{$header} must be supplied at most once.", $error->getMessage());
            self::assertSame(0, $calls);
        }
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function singletonHeaders(): iterable
    {
        foreach ([
            'expect',
            'content-length',
            'content-md5',
            'content-type',
            'content-disposition',
            'expires',
            'user-agent',
            'x-amz-acl',
            'x-amz-bucket-object-lock-enabled',
            'x-amz-bypass-governance-retention',
            'x-amz-content-sha256',
            'x-amz-decoded-content-length',
            'x-amz-expected-bucket-owner',
            'x-amz-metadata-directive',
            'x-amz-object-lock-legal-hold',
            'x-amz-object-lock-mode',
            'x-amz-object-lock-retain-until-date',
            'x-amz-object-ownership',
            'x-amz-sdk-checksum-algorithm',
            'x-amz-server-side-encryption',
            'x-amz-storage-class',
            'x-amz-tagging',
            'x-amz-tagging-directive',
            'x-amz-trailer',
            'x-amz-checksum-algorithm',
            'x-amz-checksum-type',
            'x-amz-checksum-mode',
            'x-amz-checksum-crc32',
            'x-amz-checksum-crc32c',
            'x-amz-checksum-sha1',
            'x-amz-checksum-sha256',
            'x-amz-checksum-future',
        ] as $header) {
            yield $header => [$header];
        }
    }

    public function test_content_length_and_transfer_encoding_are_rejected_before_dispatch(): void
    {
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };

        try {
            (new RequestHeaderContractMiddleware())->handleRequest(
                $this->request([
                    'content-length' => '4',
                    'transfer-encoding' => 'chunked',
                ]),
                $handler,
            );
            self::fail('Ambiguous request framing reached dispatch.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Content-Length and Transfer-Encoding cannot be combined.', $error->getMessage());
            self::assertSame(0, $calls);
        }
    }

    public function test_connection_is_poisoned_after_ambiguous_framing(): void
    {
        $client = $this->createMock(Client::class);
        $middleware = new RequestHeaderContractMiddleware();
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                throw new \LogicException('A poisoned connection reached dispatch.');
            }
        };
        $ambiguous = new Request(
            $client,
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => '4', 'transfer-encoding' => 'chunked'],
        );
        $followUp = new Request($client, 'PUT', Http::new('http://127.0.0.1/bucket/follow-up'));

        foreach ([$ambiguous, $followUp] as $index => $request) {
            try {
                $middleware->handleRequest($request, $handler);
                self::fail('Ambiguous framing did not poison the HTTP connection.');
            } catch (InvalidArgumentException $error) {
                self::assertTrue($request->getAttribute('s3.forceConnectionClose'));
                self::assertSame(
                    $index === 0
                        ? 'Content-Length and Transfer-Encoding cannot be combined.'
                        : 'The HTTP connection cannot be reused after ambiguous framing.',
                    $error->getMessage(),
                );
            }
        }
    }

    public function test_ambiguous_http_two_stream_does_not_poison_follow_up_streams(): void
    {
        $client = $this->createMock(Client::class);
        $middleware = new RequestHeaderContractMiddleware();
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };
        $ambiguous = new Request(
            $client,
            'PUT',
            Http::new('https://127.0.0.1/bucket/key'),
            ['content-length' => '4', 'transfer-encoding' => 'chunked'],
            protocol: '2',
        );
        $followUp = new Request(
            $client,
            'PUT',
            Http::new('https://127.0.0.1/bucket/follow-up'),
            protocol: '2',
        );

        try {
            $middleware->handleRequest($ambiguous, $handler);
            self::fail('Ambiguous HTTP/2 framing reached dispatch.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Content-Length and Transfer-Encoding cannot be combined.', $error->getMessage());
            self::assertFalse($ambiguous->hasAttribute('s3.forceConnectionClose'));
            self::assertSame(0, $calls);
        }

        self::assertSame(204, $middleware->handleRequest($followUp, $handler)->getStatus());
        self::assertFalse($followUp->hasAttribute('s3.forceConnectionClose'));
        self::assertSame(1, $calls);
    }

    public function test_unambiguous_singletons_and_repeated_lists_reach_dispatch_unchanged(): void
    {
        $request = $this->request([
            'content-type' => 'application/octet-stream',
            'content-encoding' => ['gzip', 'br'],
            'cache-control' => ['no-cache', 'no-store'],
        ]);
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204, [
                    'x-observed-content-encoding' => implode('|', $request->getHeaderArray('content-encoding')),
                    'x-observed-cache-control' => implode('|', $request->getHeaderArray('cache-control')),
                ]);
            }
        };

        $response = (new RequestHeaderContractMiddleware())->handleRequest($request, $handler);

        self::assertSame(204, $response->getStatus());
        self::assertSame('gzip|br', $response->getHeader('x-observed-content-encoding'));
        self::assertSame('no-cache|no-store', $response->getHeader('x-observed-cache-control'));
    }

    public function test_repeated_trailer_is_rejected_before_authentication_selects_a_decoder(): void
    {
        $request = $this->request([
            'content-encoding' => 'aws-chunked',
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-decoded-content-length' => '0',
            'x-amz-trailer' => ['x-amz-checksum-crc32', 'x-amz-checksum-sha256'],
        ]);
        $calls = 0;
        $terminal = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };
        $auth = new AuthMiddleware(new InMemoryCredentialProvider(), 'us-east-1');
        $authHandler = new class ($auth, $terminal) implements RequestHandler {
            public function __construct(
                private readonly AuthMiddleware $auth,
                private readonly RequestHandler $terminal,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return $this->auth->handleRequest($request, $this->terminal);
            }
        };

        try {
            (new RequestHeaderContractMiddleware())->handleRequest($request, $authHandler);
            self::fail('Repeated x-amz-trailer reached authentication.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('x-amz-trailer must be supplied at most once.', $error->getMessage());
            self::assertSame(0, $calls);
            self::assertSame(
                ['x-amz-checksum-crc32', 'x-amz-checksum-sha256'],
                $request->getHeaderArray('x-amz-trailer'),
            );
        }
    }

    /** @param array<non-empty-string, string|list<string>> $headers */
    private function request(array $headers): Request
    {
        return new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
    }
}
