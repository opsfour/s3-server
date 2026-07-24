<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

final class S3ServerAuthenticationTest extends TestCase
{
    public function test_non_authentication_middleware_does_not_unlock_server_start(): void
    {
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $server->addMiddleware(new PassthroughMiddleware());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Refusing to start without authentication middleware.');
        $server->start();
    }

    public function test_built_in_authentication_middleware_implements_marker_contract(): void
    {
        $middleware = new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
            'us-east-1',
        );

        self::assertInstanceOf(AuthenticationMiddleware::class, $middleware);
    }
}

final class PassthroughMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        return $requestHandler->handleRequest($request);
    }
}
