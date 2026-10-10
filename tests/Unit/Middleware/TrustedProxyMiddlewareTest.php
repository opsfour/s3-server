<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Middleware\RateLimitMiddleware;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Security\RequestNetworkContext;
use PHPUnit\Framework\TestCase;

final class TrustedProxyMiddlewareTest extends TestCase
{
    public function test_untrusted_peer_cannot_spoof_https_or_source_ip(): void
    {
        $request = $this->request('192.0.2.10');
        $request->setHeader('X-Forwarded-Proto', 'https');
        $request->setHeader('X-Forwarded-For', '10.0.0.1');

        $this->dispatch($request, ['127.0.0.1/32']);

        self::assertFalse(RequestNetworkContext::secureTransport($request));
        $sourceIp = RequestNetworkContext::sourceIp($request);
        self::assertSame('192.0.2.10', $sourceIp);
        $policy = json_encode(['Statement' => [[
            'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject',
            'Resource' => '*', 'Condition' => ['NotIpAddress' => ['aws:SourceIp' => '192.0.2.0/24']],
        ]]], JSON_THROW_ON_ERROR);
        self::assertSame('Neutral', PolicyEvaluator::evaluate(
            $policy,
            's3:GetObject',
            'arn:aws:s3:::bucket/key',
            '*',
            ['aws:SourceIp' => $sourceIp],
        ));
    }

    public function test_trusted_peer_uses_single_https_header_and_rightmost_untrusted_hop(): void
    {
        $request = $this->request('127.0.0.1');
        $request->setHeader('X-Forwarded-Proto', 'https');
        $request->setHeader('X-Forwarded-For', '198.51.100.99, 203.0.113.8, 127.0.0.2');

        $this->dispatch($request, ['127.0.0.0/8']);

        self::assertTrue(RequestNetworkContext::secureTransport($request));
        self::assertSame('203.0.113.8', RequestNetworkContext::sourceIp($request));
        self::assertSame('203.0.113.8', RequestNetworkContext::rateLimitIp($request));
    }

    public function test_ipv6_proxy_chain_uses_rightmost_untrusted_hop(): void
    {
        $request = $this->request('2001:db8:ffff::1');
        $request->setHeader('X-Forwarded-For', '2001:db8::10, 2001:db8:ffff::2');

        $this->dispatch($request, ['2001:db8:ffff::/48']);

        self::assertSame('2001:db8::10', RequestNetworkContext::sourceIp($request));
    }

    public function test_missing_or_ambiguous_proto_is_insecure_even_with_backend_tls(): void
    {
        foreach ([null, 'https,http', ['https', 'http']] as $proto) {
            $request = $this->request('127.0.0.1', true);
            $request->setHeader('X-Forwarded-For', '203.0.113.10');
            if ($proto !== null) {
                $request->setHeader('X-Forwarded-Proto', $proto);
            }

            $this->dispatch($request, ['127.0.0.1/32']);

            self::assertFalse(RequestNetworkContext::secureTransport($request));
        }
    }

    public function test_invalid_forwarded_chain_is_s3_access_denied_before_policy_can_see_missing_source_ip(): void
    {
        foreach ([null, 'bad-ip, 127.0.0.1', '127.0.0.1', '198.51.100.2, '] as $chain) {
            $request = $this->request('127.0.0.1');
            $request->setAttribute('requestId', 'proxy-test');
            if ($chain !== null) {
                $request->setHeader('X-Forwarded-For', $chain);
            }
            $terminal = new class implements RequestHandler {
                public bool $handled = false;

                public function handleRequest(Request $request): Response
                {
                    $this->handled = true;

                    return new Response(200);
                }
            };
            $middleware = new TrustedProxyMiddleware(['127.0.0.1/32']);
            $response = (new ErrorHandlingMiddleware())->handleRequest($request, new class ($middleware, $terminal) implements RequestHandler {
                public function __construct(private TrustedProxyMiddleware $middleware, private RequestHandler $terminal) {}

                public function handleRequest(Request $request): Response
                {
                    return $this->middleware->handleRequest($request, $this->terminal);
                }
            });

            self::assertSame(403, $response->getStatus());
            self::assertStringContainsString('<Code>AccessDenied</Code>', \Amp\ByteStream\buffer($response->getBody()));
            self::assertFalse($terminal->handled);
        }
    }

    public function test_probe_exemption_keeps_health_response_available_without_forwarded_for(): void
    {
        $request = $this->request('127.0.0.1');
        $response = (new TrustedProxyMiddleware(['127.0.0.1/32'], enforceForwardedFor: false))
            ->handleRequest($request, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            });

        self::assertSame(200, $response->getStatus());
        self::assertSame('127.0.0.1', RequestNetworkContext::sourceIp($request));

        $request->setHeader('X-Forwarded-For', '127.0.0.1');
        $response = (new TrustedProxyMiddleware(['127.0.0.1/32'], enforceForwardedFor: false))
            ->handleRequest($request, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            });
        self::assertSame(200, $response->getStatus());
    }

    public function test_invalid_proxy_cidrs_fail_at_configuration_time(): void
    {
        $rejected = 0;
        foreach (['0', '0.0.0.0/0', '127.0.0.1/33', 'not-an-ip', '::/0'] as $cidr) {
            try {
                new TrustedProxyMiddleware([$cidr]);
                self::fail("Accepted unsafe proxy CIDR {$cidr}");
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(5, $rejected);
    }

    public function test_rate_limit_uses_resolved_client_ip_instead_of_proxy_peer(): void
    {
        $request = $this->request('127.0.0.1');
        $request->setHeader('X-Forwarded-For', '198.51.100.99, 203.0.113.8');
        $this->dispatch($request, ['127.0.0.1/32']);

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('rateLimitCheck')
            ->with('203.0.113.8', 10.0, 10.0)->willReturn(true);

        $response = (new RateLimitMiddleware($metadata, 10.0))->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        });

        self::assertSame(204, $response->getStatus());
    }

    /** @param list<string> $trustedProxyCidrs */
    private function dispatch(Request $request, array $trustedProxyCidrs): void
    {
        (new TrustedProxyMiddleware($trustedProxyCidrs))->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        });
    }

    private function request(string $peerIp, bool $backendTls = false): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress($peerIp, 12345));
        $client->method('getTlsInfo')->willReturn($backendTls
            ? TlsInfo::fromMetaData(['protocol' => 'TLSv1.3', 'cipher_name' => 'test', 'cipher_bits' => 128, 'cipher_version' => 'TLSv1.3'], [])
            : null);

        return new Request($client, 'GET', Http::new('http://localhost/bucket/key'));
    }
}
