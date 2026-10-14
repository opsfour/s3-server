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
        $this->assertSame('no-store', $response->getHeader('cache-control'));
        $this->assertSame('Bearer', $response->getHeader('www-authenticate'));
        $this->assertSame(['error' => 'unauthorized'], $this->json($response));
    }

    public function test_handler_rejects_empty_admin_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty admin token');

        $this->createHandler(adminToken: '');
    }

    public function test_handler_rejects_whitespace_admin_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty admin token');

        $this->createHandler(adminToken: " \t ");
    }

    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $request = $this->request('POST', '/.admin/credentials', ['token' => 'idp-token']);
        $request->setHeader('authorization', ['Bearer admin-secret', 'Bearer admin-secret']);

        $response = $this->createHandler()->handleRequest($request);

        self::assertSame(401, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
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
            ['token' => 'idp-token', 'ttlSeconds' => 3600],
            ['authorization' => 'Bearer admin-secret'],
        ));
        $payload = $this->json($response);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('no-store', $response->getHeader('cache-control'));
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
        ], ['authorization' => 'Bearer admin-secret']));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('no-store', $response->getHeader('cache-control'));
        $this->assertSame('invalid_request', $this->json($response)['error']);
    }

    public function test_identity_provider_failure_does_not_disclose_its_exception_message(): void
    {
        foreach ([
            new ExternalIdentityAuthenticationException(
                'OIDC request to https://client:provider-secret@idp.internal/token failed.',
            ),
            new \InvalidArgumentException('Provider rejected tenant provider-secret.'),
        ] as $failure) {
            $identityProvider = new class ($failure) implements ExternalIdentityProvider {
                public function __construct(private readonly \Throwable $failure) {}

                public function authenticate(string $bearerToken): ExternalIdentity
                {
                    throw $this->failure;
                }
            };
            $response = $this->createHandler(identityProvider: $identityProvider)->handleRequest($this->request(
                'POST',
                '/.admin/credentials',
                ['token' => 'bad-token'],
                ['authorization' => 'Bearer admin-secret'],
            ));
            $body = \Amp\ByteStream\buffer($response->getBody());

            self::assertSame(400, $response->getStatus());
            self::assertSame('no-store', $response->getHeader('cache-control'));
            self::assertStringNotContainsString('provider-secret', $body);
            self::assertSame([
                'error' => 'invalid_request',
                'message' => 'External identity authentication failed.',
            ], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_identity_provider_cancellation_is_propagated_unchanged(): void
    {
        $cancellation = new \Amp\CancelledException();
        $identityProvider = new class ($cancellation) implements ExternalIdentityProvider {
            public function __construct(private readonly \Amp\CancelledException $cancellation) {}

            public function authenticate(string $bearerToken): ExternalIdentity
            {
                throw $this->cancellation;
            }
        };

        try {
            $this->createHandler(identityProvider: $identityProvider)->handleRequest($this->request(
                'POST',
                '/.admin/credentials',
                ['token' => 'idp-token'],
                ['authorization' => 'Bearer admin-secret'],
            ));
            self::fail('Credential issuance swallowed provider cancellation.');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($cancellation, $caught);
        }
    }

    public function test_issue_rejects_external_identity_that_cannot_be_serialized_safely(): void
    {
        $identityProvider = new class implements ExternalIdentityProvider {
            public function authenticate(string $bearerToken): ExternalIdentity
            {
                return new ExternalIdentity(
                    subject: 'user-123',
                    issuer: 'https://keycloak.example.test/realms/storage',
                    ownerId: "tenant:\xC3\x28",
                    displayName: 'alice',
                );
            }
        };
        $response = $this->createHandler(identityProvider: $identityProvider)->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            ['token' => 'idp-token'],
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(400, $response->getStatus());
        self::assertSame('invalid_request', $this->json($response)['error']);
    }

    public function test_issue_without_ttl_returns_temporary_credential(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            ['token' => 'idp-token'],
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(201, $response->getStatus());
        $payload = $this->json($response);
        self::assertIsString($payload['sessionToken']);
        self::assertNotSame('', $payload['sessionToken']);
        self::assertIsString($payload['expiresAt']);
    }

    public function test_issue_rejects_ttl_beyond_twelve_hours(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            ['token' => 'idp-token', 'ttlSeconds' => 43201],
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(400, $response->getStatus());
        self::assertSame('Credential TTL must be between 3600 and 43200 seconds.', $this->json($response)['message']);
    }

    public function test_issue_rejects_invalid_json_body(): void
    {
        $handler = $this->createHandler();

        $response = $handler->handleRequest($this->request('POST', '/.admin/credentials', '{', ['authorization' => 'Bearer admin-secret']));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Request body must be a JSON object.', $this->json($response)['message']);
    }

    public function test_issue_rejects_unknown_fields(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            ['token' => 'idp-token', 'ttlSecond' => 3600],
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(400, $response->getStatus());
        self::assertSame("Unknown field 'ttlSecond'.", $this->json($response)['message']);
    }

    public function test_issue_rejects_oversized_body_without_throwing_buffer_exception(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'POST',
            '/.admin/credentials',
            str_repeat('x', 16_385),
            ['authorization' => 'Bearer admin-secret'],
        ));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_request', $this->json($response)['error']);
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
        $this->assertSame('no-store', $response->getHeader('cache-control'));
        $this->assertNull($credentialProvider->getCredential($credential->accessKeyId));
    }

    public function test_revoke_returns_not_found_for_unknown_credential(): void
    {
        $handler = $this->createHandler();

        $response = $handler->handleRequest($this->request('DELETE', '/.admin/credentials/UNKNOWN', '', ['authorization' => 'Bearer admin-secret']));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('no-store', $response->getHeader('cache-control'));
        $this->assertSame(['error' => 'not_found'], $this->json($response));
    }

    public function test_revoke_rejects_invalid_access_key_id_before_provider_lookup(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'DELETE',
            '/.admin/credentials/' . rawurlencode('INVALID KEY'),
            '',
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(400, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame([
            'error' => 'invalid_request',
            'message' => 'Access key ID is invalid.',
        ], $this->json($response));
    }

    public function test_unsupported_method_returns_no_store_and_allow_header(): void
    {
        $response = $this->createHandler()->handleRequest($this->request(
            'GET',
            '/.admin/credentials',
            '',
            ['authorization' => 'Bearer admin-secret'],
        ));

        self::assertSame(405, $response->getStatus());
        self::assertSame('POST, DELETE', $response->getHeader('Allow'));
        self::assertSame('no-store', $response->getHeader('cache-control'));
    }

    private function createHandler(
        ?InMemoryCredentialProvider $credentialProvider = null,
        ?ExternalIdentityProvider $identityProvider = null,
        string $adminToken = 'admin-secret',
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
     * @param non-empty-string $method
     * @param array<string, mixed>|string $body
     * @param array<non-empty-string, string> $headers
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
