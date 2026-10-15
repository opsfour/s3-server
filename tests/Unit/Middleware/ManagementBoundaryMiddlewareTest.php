<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream;
use Amp\CancelledException;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Middleware\ManagementBoundaryMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ManagementBoundaryMiddlewareTest extends TestCase
{
    public function test_rate_limit_xml_is_replaced_without_losing_retry_after(): void
    {
        $terminal = new ManagementBoundaryTestHandler(new Response(
            503,
            ['content-type' => 'application/xml', 'retry-after' => '1'],
            '<Error><Code>SlowDown</Code></Error>',
        ));
        $boundary = new ManagementBoundaryMiddleware(true, true, new NullLogger());
        $response = $boundary->handleRequest($this->request('GET', '/.admin/quotas'), $terminal);

        self::assertSame(503, $response->getStatus());
        self::assertSame('1', $response->getHeader('retry-after'));
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame('application/json', $response->getHeader('content-type'));
        self::assertSame('{"error":"service_unavailable"}', ByteStream\buffer($response->getBody()));
        self::assertTrue($terminal->handled);
    }

    public function test_metrics_backend_error_uses_text_without_disclosing_details(): void
    {
        $boundary = new ManagementBoundaryMiddleware(false, false, new NullLogger());
        $response = $boundary->handleRequest(
            $this->request('GET', '/.metrics'),
            new ManagementBoundaryTestHandler(new \RuntimeException('private credential: example')),
        );

        self::assertSame(500, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame('text/plain; charset=utf-8', $response->getHeader('content-type'));
        self::assertSame('Internal Server Error', ByteStream\buffer($response->getBody()));
    }

    public function test_metrics_xml_errors_use_their_actual_status_reason(): void
    {
        $boundary = new ManagementBoundaryMiddleware(false, false, new NullLogger());
        foreach ([
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            409 => 'Conflict',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
        ] as $status => $message) {
            $response = $boundary->handleRequest(
                $this->request('GET', '/.metrics'),
                new ManagementBoundaryTestHandler(new Response($status, ['content-type' => 'application/xml'], '<Error/>')),
            );

            self::assertSame($status, $response->getStatus());
            self::assertSame('no-store', $response->getHeader('cache-control'));
            self::assertSame('text/plain; charset=utf-8', $response->getHeader('content-type'));
            self::assertSame($message, ByteStream\buffer($response->getBody()));
        }
    }

    public function test_disabled_admin_route_is_404_instead_of_an_s3_wildcard(): void
    {
        $terminal = new ManagementBoundaryTestHandler(new Response(200));
        $boundary = new ManagementBoundaryMiddleware(false, false, new NullLogger());
        $response = $boundary->handleRequest($this->request('DELETE', '/.admin/credentials/key'), $terminal);

        self::assertSame(404, $response->getStatus());
        self::assertFalse($terminal->handled);
        self::assertSame('no-store', $response->getHeader('cache-control'));
    }

    public function test_excessive_nested_path_encoding_fails_before_fallback(): void
    {
        $terminal = new ManagementBoundaryTestHandler(new Response(200));
        $boundary = new ManagementBoundaryMiddleware(true, true, new NullLogger());

        try {
            $boundary->handleRequest(
                $this->request('GET', '/.admin%252525252Fcredentials'),
                $terminal,
            );
            self::fail('Excessively nested encoding reached the router fallback.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Request path exceeds the percent-decoding limit.', $error->getMessage());
            self::assertFalse($terminal->handled);
        }
    }

    public function test_four_decode_passes_on_a_data_path_remain_bounded_and_reach_fallback(): void
    {
        $terminal = new ManagementBoundaryTestHandler(new Response(204));
        $boundary = new ManagementBoundaryMiddleware(true, true, new NullLogger());

        $response = $boundary->handleRequest(
            $this->request('GET', '/bucket%25252541/object'),
            $terminal,
        );

        self::assertSame(204, $response->getStatus());
        self::assertTrue($terminal->handled);
    }

    public function test_multiply_encoded_reserved_path_never_reaches_fallback_for_allowed_method(): void
    {
        $terminal = new ManagementBoundaryTestHandler(new Response(200));
        $boundary = new ManagementBoundaryMiddleware(true, true, new NullLogger());

        $response = $boundary->handleRequest(
            $this->request('POST', '/.admin%252Fcredentials'),
            $terminal,
        );

        self::assertSame(404, $response->getStatus());
        self::assertSame('application/json', $response->getHeader('content-type'));
        self::assertFalse($terminal->handled);
    }

    public function test_cancellation_remains_cancellation(): void
    {
        $cancellation = new CancelledException();
        $boundary = new ManagementBoundaryMiddleware(true, true, new NullLogger());

        try {
            $boundary->handleRequest(
                $this->request('GET', '/.admin/quotas'),
                new ManagementBoundaryTestHandler($cancellation),
            );
            self::fail('Cancellation must not become a management response.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $path): Request
    {
        return new Request($this->createMock(Client::class), $method, Http::new('http://localhost' . $path));
    }
}

final class ManagementBoundaryTestHandler implements RequestHandler
{
    public bool $handled = false;

    public function __construct(private readonly Response|\Throwable $result) {}

    public function handleRequest(Request $request): Response
    {
        $this->handled = true;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
