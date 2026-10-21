<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Contracts\AuthenticationMiddleware;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Contracts\CredentialProvider;
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

    public function test_default_auth_spooling_binds_to_server_pool_without_replacing_explicit_components(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-auth-spool-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $pool = $this->createStub(WorkerPool::class);
        $owned = new PostObjectSpoolCoordinator($tempDir, $pool);
        $explicit = new PostObjectSpoolCoordinator($tempDir, $this->createStub(WorkerPool::class));
        $defaultAuth = new AuthMiddleware(new InMemoryCredentialProvider(), 'us-east-1');
        $explicitAuth = new AuthMiddleware(
            new InMemoryCredentialProvider(),
            'us-east-1',
            postSpoolCoordinator: $explicit,
        );

        try {
            $defaultAuth->bindServerSpoolCoordinator($owned);
            $explicitAuth->bindServerSpoolCoordinator($owned);

            self::assertSame($owned, $this->authSpoolCoordinator($defaultAuth));
            self::assertSame($explicit, $this->authSpoolCoordinator($explicitAuth));
        } finally {
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_enabled_rate_limit_requires_metadata_store_before_startup(): void
    {
        $server = new S3Server(new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 2,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
            'us-east-1',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('set perClientRateLimit to 0 to disable rate limiting');

        $server->start();
    }

    public function test_readiness_binding_uses_the_exact_provider_from_built_in_authentication(): void
    {
        $provider = new ProbeCredentialProvider();
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));

        $server->addMiddleware(new AuthMiddleware($provider, 'us-east-1'));

        $property = new \ReflectionProperty($server, 'authenticationCredentialProvider');
        self::assertSame($provider, $property->getValue($server));
        self::assertInstanceOf(CredentialHealthProbe::class, $property->getValue($server));
    }

    public function test_bound_credential_probe_degrades_readiness_without_affecting_liveness(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);

        $provider = new ProbeCredentialProvider();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            notificationWorkerEnabled: false,
        ));
        $server->addMiddleware(new AuthMiddleware($provider, 'us-east-1'));
        $provider->failure = new \RuntimeException('credential database unavailable');

        try {
            $server->start();
            $client = (new HttpClientBuilder())->retry(0)->build();
            $endpoint = "http://127.0.0.1:{$port}";

            self::assertSame(503, $client->request(new ClientRequest($endpoint . '/.health'))->getStatus());
            self::assertSame(200, $client->request(new ClientRequest($endpoint . '/.live'))->getStatus());
            self::assertSame(1, $provider->checks, 'Liveness must not invoke the credential dependency probe.');
        } finally {
            $server->stop();
        }
    }

    public function test_different_built_in_auth_providers_are_rejected_without_replacing_the_binding(): void
    {
        $provider = new ProbeCredentialProvider();
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $server->addMiddleware(new AuthMiddleware($provider, 'us-east-1'));

        try {
            $server->addMiddleware(new AuthMiddleware(new ProbeCredentialProvider(), 'us-east-1'));
            self::fail('A second authentication provider was accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('share one credential provider', $error->getMessage());
        }

        $property = new \ReflectionProperty($server, 'authenticationCredentialProvider');
        self::assertSame($provider, $property->getValue($server));
    }

    private function authSpoolCoordinator(AuthMiddleware $middleware): PostObjectSpoolCoordinator
    {
        $parserProperty = new \ReflectionProperty($middleware, 'postObjectFormParser');
        $parser = $parserProperty->getValue($middleware);
        self::assertIsObject($parser);
        $coordinatorProperty = new \ReflectionProperty($parser, 'spoolCoordinator');
        $coordinator = $coordinatorProperty->getValue($parser);
        self::assertInstanceOf(PostObjectSpoolCoordinator::class, $coordinator);

        return $coordinator;
    }
}

final class ProbeCredentialProvider implements CredentialHealthProbe, CredentialProvider
{
    public int $checks = 0;

    public ?\Throwable $failure = null;

    public function assertCredentialStoreAvailable(): void
    {
        $this->checks++;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        return null;
    }

    public function listCredentials(): array
    {
        return [];
    }

    public function putCredential(Credential $credential): void {}

    public function deleteCredential(string $accessKeyId): void {}
}

final class PassthroughMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        return $requestHandler->handleRequest($request);
    }
}
