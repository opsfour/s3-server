<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Routing;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class VirtualHostedAddressingTest extends TestCase
{
    public function test_single_segment_virtual_host_path_is_an_object_key(): void
    {
        $request = $this->request('/single', ['bucket' => 'single']);

        (new S3AttributeMiddleware('s3.example.test'))->handleRequest($request, new VirtualHostedTerminal());

        self::assertSame('photos', $request->getAttribute('s3.bucket'));
        self::assertSame('single', $request->getAttribute('s3.key'));
        self::assertSame(S3Operation::GetObject, $request->getAttribute('s3.operation'));
    }

    public function test_multi_segment_virtual_host_path_preserves_the_complete_key_and_percent(): void
    {
        $request = $this->request('/folder/a%252Fb', ['bucket' => 'folder', 'key' => 'a%2Fb']);

        (new S3AttributeMiddleware('s3.example.test'))->handleRequest($request, new VirtualHostedTerminal());

        self::assertSame('folder/a%2Fb', $request->getAttribute('s3.key'));
        self::assertSame(S3Operation::GetObject, $request->getAttribute('s3.operation'));
    }

    /** @param array<string, string> $routeArguments */
    private function request(string $path, array $routeArguments): Request
    {
        $request = new Request(
            new VirtualHostedClient(),
            'GET',
            Http::new('http://127.0.0.1' . $path),
            ['host' => 'photos.s3.example.test'],
        );
        $request->setAttribute(Router::class, $routeArguments);

        return $request;
    }
}

final class VirtualHostedTerminal implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(status: 200);
    }
}

final class VirtualHostedClient implements Client
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
    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }
    public function close(): void {}
    public function isClosed(): bool
    {
        return false;
    }
    public function onClose(\Closure $onClose): void {}
}
