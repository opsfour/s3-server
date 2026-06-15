<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\External\AdminCredentialApiHandler;
use OpsFour\S3Server\Auth\External\ExternalCredentialIssuer;
use OpsFour\S3Server\Auth\External\ExternalIdentity;
use OpsFour\S3Server\Auth\External\ExternalIdentityAuthenticationException;
use OpsFour\S3Server\Auth\External\ExternalIdentityProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use PHPUnit\Framework\TestCase;

final class AdminCredentialApiHandlerTest extends TestCase
{
    public function test_issue_requires_admin_bearer_token_when_configured(): void
    {
        $handler = $this->createHandler(adminToken: 'admin-secret');

        $response = $handler->handleRequest($this->request('POST', '/.admin/credentials', [
            'token' => 'idp-token',
        ]));

        $this->assertSame(401, $response->getStatus());
        $this->assertSame(['error' => 'unauthorized'], $this->json($response));
    }

    public function test_issue_creates_credential_from_external_token(): void
    {
        $credentialProvider = new InMemoryCredentialProvider();
        $handler = $this->createHandler(
            credentialProvider: $credentialProvider,
            adminToken: 'admin-secret',
        );

        $response = $handler->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            ['token' => 'idp-token', 'ttlSeconds' => 900],
            ['authorization' => 'Bearer admin-secret'],
        ));
        $payload = $this->json($response);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('tenant:acme', $payload['ownerId']);
        $this->assertSame('alice', $payload['displayName']);
        $this->assertSame(['uploads'], $payload['policyNames']);
        $this->assertSame(['incoming/'], $payload['allowedPrefixes']);
        $this->assertIsString($payload['accessKeyId']);
        $this->assertIsString($payload['secretAccessKey']);
        $this->assertIsString($payload['sessionToken']);
        $this->assertIsString($payload['expiresAt']);
        $this->assertNotNull($credentialProvider->getCredential($payload['accessKeyId']));
    }

    public function test_issue_rejects_invalid_external_token(): void
    {
        $handler = $this->createHandler(identityProvider: new RejectingExternalIdentityProvider());

        $response = $handler->handleRequest($this->request('POST', '/.admin/credentials', [
            'token' => 'bad-token',
        ]));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_request', $this->json($response)['error']);
    }

    public function test_issue_rejects_invalid_json_body(): void
    {
        $handler = $this->createHandler();

        $response = $handler->handleRequest($this->request('POST', '/.admin/credentials', '{'));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Request body must be a JSON object.', $this->json($response)['message']);
    }

    public function test_revoke_deletes_credential(): void
    {
        $credentialProvider = new InMemoryCredentialProvider();
        $manager = new CredentialManager($credentialProvider);
        $credential = $manager->createCredential('tenant:acme', 'alice', 'AKIADELETEKEY123456', 'secret');
        $handler = $this->createHandler(
            credentialProvider: $credentialProvider,
            adminToken: 'admin-secret',
        );

        $response = $handler->handleRequest($this->request(
            'DELETE',
            '/.admin/credentials/' . rawurlencode($credential->accessKeyId),
            '',
            ['authorization' => 'Bearer admin-secret'],
        ));

        $this->assertSame(204, $response->getStatus());
        $this->assertNull($credentialProvider->getCredential($credential->accessKeyId));
    }

    public function test_revoke_returns_not_found_for_unknown_credential(): void
    {
        $handler = $this->createHandler();

        $response = $handler->handleRequest($this->request('DELETE', '/.admin/credentials/UNKNOWN'));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $this->json($response));
    }

    private function createHandler(
        ?InMemoryCredentialProvider $credentialProvider = null,
        ?ExternalIdentityProvider $identityProvider = null,
        ?string $adminToken = null,
    ): AdminCredentialApiHandler {
        $credentialProvider ??= new InMemoryCredentialProvider();
        $identityProvider ??= new FixedExternalIdentityProvider();

        return new AdminCredentialApiHandler(
            $identityProvider,
            new ExternalCredentialIssuer(new CredentialManager($credentialProvider)),
            $adminToken,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array|string $body = '', array $headers = []): Request
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers = ['content-type' => 'application/json'] + $headers;
        }

        return new Request(
            new AdminApiTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Amp\Http\Server\Response $response): array
    {
        $decoded = json_decode(\Amp\ByteStream\buffer($response->getBody()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

final class FixedExternalIdentityProvider implements ExternalIdentityProvider
{
    public function authenticate(string $bearerToken): ExternalIdentity
    {
        return new ExternalIdentity(
            subject: 'user-123',
            issuer: 'https://keycloak.example.test/realms/storage',
            ownerId: 'tenant:acme',
            displayName: 'alice',
            groups: ['/storage-admins'],
            policyNames: ['uploads'],
            allowedPrefixes: ['incoming/'],
        );
    }
}

final class RejectingExternalIdentityProvider implements ExternalIdentityProvider
{
    public function authenticate(string $bearerToken): ExternalIdentity
    {
        throw new ExternalIdentityAuthenticationException('Token is invalid.');
    }
}

final class AdminApiTestClient implements Client
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
