<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class CorsMiddlewareTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-cors-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket-a', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_preflight_matches_allowed_header_wildcard_and_echoes_requested_headers(): void
    {
        $this->metadata->putBucketCors('bucket-a', [$this->rule(['x-amz-*', 'content-type'])]);
        $response = (new CorsMiddleware($this->metadata))->handleRequest(
            $this->request('OPTIONS', [
                'Origin' => 'https://client.example',
                'Access-Control-Request-Method' => 'PUT',
                'Access-Control-Request-Headers' => 'X-Amz-Meta-Title, Content-Type',
            ]),
            $this->terminal(),
        );

        self::assertSame(200, $response->getStatus());
        self::assertSame('x-amz-meta-title, content-type', $response->getHeader('Access-Control-Allow-Headers'));
        self::assertSame('Origin, Access-Control-Request-Headers, Access-Control-Request-Method', $response->getHeader('Vary'));
    }

    public function test_preflight_rejects_unlisted_header_and_missing_method(): void
    {
        $this->metadata->putBucketCors('bucket-a', [$this->rule(['x-amz-*'])]);
        $middleware = new CorsMiddleware($this->metadata);

        foreach ([
            ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'Authorization'],
            ['Origin' => 'https://client.example', 'Access-Control-Request-Headers' => 'x-amz-meta-title'],
            ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'PUT', 'Access-Control-Request-Headers' => 'x-amz-meta-title,'],
        ] as $headers) {
            $response = $middleware->handleRequest($this->request('OPTIONS', $headers), $this->terminal());
            self::assertSame(403, $response->getStatus());
            self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
            self::assertSame('Origin, Access-Control-Request-Headers, Access-Control-Request-Method', $response->getHeader('Vary'));
        }
    }

    public function test_regular_response_keeps_existing_vary_fields(): void
    {
        $this->metadata->putBucketCors('bucket-a', [$this->rule([])]);
        $response = (new CorsMiddleware($this->metadata))->handleRequest(
            $this->request('PUT', ['Origin' => 'https://client.example']),
            $this->terminal(new Response(200, ['Vary' => 'Accept-Encoding'])),
        );

        self::assertSame('Accept-Encoding, Origin', $response->getHeader('Vary'));
        self::assertSame('https://client.example', $response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_origin_question_mark_and_brackets_are_literal_not_extra_glob_syntax(): void
    {
        $rule = $this->rule([]);
        $rule['allowedOrigins'] = ['https://client?.example', 'https://client[12].example'];
        $this->metadata->putBucketCors('bucket-a', [$rule]);
        $middleware = new CorsMiddleware($this->metadata);

        foreach (['https://client1.example', 'https://client2.example'] as $origin) {
            $response = $middleware->handleRequest($this->request('OPTIONS', [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'PUT',
            ]), $this->terminal());
            self::assertSame(403, $response->getStatus());
        }
    }

    public function test_origin_wildcard_cannot_match_overlapping_prefix_and_suffix(): void
    {
        $rule = $this->rule([]);
        $rule['allowedOrigins'] = ['https://ab*bc.example'];
        $this->metadata->putBucketCors('bucket-a', [$rule]);

        $response = (new CorsMiddleware($this->metadata))->handleRequest($this->request('OPTIONS', [
            'Origin' => 'https://abc.example',
            'Access-Control-Request-Method' => 'PUT',
        ]), $this->terminal());

        self::assertSame(403, $response->getStatus());
    }

    public function test_stale_cached_cors_cannot_authorize_recreated_bucket_even_with_same_owner(): void
    {
        $rules = [$this->rule([])];
        $this->metadata->putBucketCors('bucket-a', $rules);
        $cached = new CachedMetadataStoreDecorator($this->metadata, 60.0);
        self::assertSame($rules, $cached->getBucketCors('bucket-a'));

        $this->metadata->deleteBucket('owner', 'bucket-a');
        $this->metadata->createBucket('owner', 'bucket-a', 'us-east-1');
        self::assertSame($rules, $cached->getBucketCors('bucket-a'), 'The named CORS cache remains stale across external writes.');

        $response = (new CorsMiddleware($cached))->handleRequest(
            $this->request('OPTIONS', ['Origin' => 'https://client.example', 'Access-Control-Request-Method' => 'PUT']),
            $this->terminal(),
        );

        self::assertSame(403, $response->getStatus());
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }

    public function test_rule_lookup_failure_does_not_log_backend_secret(): void
    {
        $secret = 'database-password-private-test-token';
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException($secret));
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };

        $response = (new CorsMiddleware($metadata, $logger))->handleRequest(
            $this->request('OPTIONS', [
                'Origin' => 'https://client.example',
                'Access-Control-Request-Method' => 'PUT',
            ]),
            $this->terminal(),
        );

        self::assertSame(403, $response->getStatus());
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString($secret, $logger->records[0]['message'] . serialize($logger->records[0]['context']));
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    /**
     * @param list<string> $allowedHeaders
     * @return array{allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: null}
     */
    private function rule(array $allowedHeaders): array
    {
        return [
            'allowedOrigins' => ['https://client.example'],
            'allowedMethods' => ['PUT'],
            'allowedHeaders' => $allowedHeaders,
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ];
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, array $headers): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/bucket-a/key'),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket-a');

        return $request;
    }

    private function terminal(?Response $response = null): RequestHandler
    {
        return new class ($response) implements RequestHandler {
            public function __construct(private readonly ?Response $response) {}

            public function handleRequest(Request $request): Response
            {
                return $this->response ?? new Response(204);
            }
        };
    }
}
