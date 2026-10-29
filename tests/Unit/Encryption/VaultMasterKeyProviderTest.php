<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use OpsFour\S3Server\Encryption\VaultMasterKeyProvider;
use PHPUnit\Framework\TestCase;

final class VaultMasterKeyProviderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['S3_VAULT_ADDR', 'S3_VAULT_TOKEN', 'S3_VAULT_PATH', 'S3_VAULT_KEY_FIELD'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_multi_key_configuration_uses_explicit_active_key(): void
    {
        $first = str_repeat('a', 32);
        $second = str_repeat('b', 32);
        $client = self::client(200, json_encode([
            'data' => ['data' => [
                'keys' => [
                    'first' => base64_encode($first),
                    'second' => base64_encode($second),
                ],
                'activeKeyId' => 'second',
            ]],
        ], JSON_THROW_ON_ERROR));

        $provider = new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: $client,
        );

        self::assertSame('second', $provider->getKeyId());
        self::assertSame($second, $provider->getMasterKey());
        self::assertSame($first, $provider->getMasterKeyById('first'));
        self::assertStringStartsWith('vault:', $provider->clusterEncryptionProviderIdentity());
        self::assertStringNotContainsString('secret-token', $provider->clusterEncryptionProviderIdentity());
        self::assertStringNotContainsString($second, $provider->clusterEncryptionProviderIdentity());
    }

    public function test_legacy_single_key_configuration_remains_supported(): void
    {
        $key = str_repeat('k', 32);
        $client = self::client(200, json_encode([
            'data' => ['data' => ['custom' => base64_encode($key)]],
        ], JSON_THROW_ON_ERROR));

        $provider = new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            keyField: 'custom',
            httpClient: $client,
        );

        self::assertSame('default', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKey());
    }

    public function test_empty_environment_path_fails_closed(): void
    {
        putenv('S3_VAULT_PATH=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault secret path must be a non-empty URL path');

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: self::client(200, '{}'),
        );
    }

    public function test_empty_environment_key_field_fails_closed(): void
    {
        putenv('S3_VAULT_KEY_FIELD=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault key field must not be empty.');

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: self::client(200, '{}'),
        );
    }

    public function test_zero_environment_key_field_is_used_literally(): void
    {
        $key = str_repeat('z', 32);
        putenv('S3_VAULT_KEY_FIELD=0');
        $client = self::client(200, json_encode([
            'data' => ['data' => ['0' => base64_encode($key)]],
        ], JSON_THROW_ON_ERROR));

        $provider = new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: $client,
        );

        self::assertSame($key, $provider->getMasterKey());
    }

    public function test_zero_environment_path_is_used_literally(): void
    {
        putenv('S3_VAULT_PATH=0');
        $key = base64_encode(str_repeat('z', 32));
        $delegate = new class ($key) implements DelegateHttpClient {
            public ?string $requestedUri = null;

            public function __construct(private readonly string $key) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                $this->requestedUri = (string) $request->getUri();

                return new Response('1.1', 200, null, [], json_encode([
                    'data' => ['data' => ['key' => $this->key]],
                ], JSON_THROW_ON_ERROR), $request);
            }
        };

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: new HttpClient($delegate, []),
        );

        self::assertSame('https://vault.internal/v1/0', $delegate->requestedUri);
    }

    public function test_zero_environment_address_reaches_url_validation(): void
    {
        putenv('S3_VAULT_ADDR=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault address must use HTTPS');

        new VaultMasterKeyProvider(vaultToken: 'secret-token');
    }

    public function test_zero_environment_token_is_sent_literally(): void
    {
        putenv('S3_VAULT_TOKEN=0');
        $key = base64_encode(str_repeat('z', 32));
        $delegate = new class ($key) implements DelegateHttpClient {
            public ?string $token = null;

            public function __construct(private readonly string $key) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                $this->token = $request->getHeader('X-Vault-Token');

                return new Response('1.1', 200, null, [], json_encode([
                    'data' => ['data' => ['key' => $this->key]],
                ], JSON_THROW_ON_ERROR), $request);
            }
        };

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            httpClient: new HttpClient($delegate, []),
        );

        self::assertSame('0', $delegate->token);
    }

    public function test_numeric_master_key_id_remains_resolvable(): void
    {
        $key = str_repeat('n', 32);
        $client = self::client(200, json_encode([
            'data' => ['data' => [
                'keys' => ['123' => base64_encode($key)],
                'activeKeyId' => '123',
            ]],
        ], JSON_THROW_ON_ERROR));

        $provider = new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: $client,
        );

        self::assertSame('123', $provider->getKeyId());
        self::assertSame($key, $provider->getMasterKeyById('123'));
    }

    public function test_multi_key_configuration_fails_closed_without_active_key(): void
    {
        $client = self::client(200, json_encode([
            'data' => ['data' => [
                'keys' => ['first' => base64_encode(str_repeat('a', 32))],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Active master key ID field 'activeKeyId' is missing");

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: $client,
        );
    }

    public function test_error_response_does_not_expose_vault_body(): void
    {
        $client = self::client(403, 'sensitive diagnostic');

        try {
            new VaultMasterKeyProvider(
                vaultAddr: 'https://vault.internal',
                vaultToken: 'secret-token',
                httpClient: $client,
            );
            self::fail('Expected Vault request to fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP 403', $e->getMessage());
            self::assertStringNotContainsString('sensitive diagnostic', $e->getMessage());
        }
    }

    public function test_response_body_is_bounded(): void
    {
        $client = self::client(200, str_repeat('x', 1_048_577));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault returned invalid JSON');

        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            httpClient: $client,
        );
    }

    public function test_transport_exception_does_not_expose_vault_token(): void
    {
        $delegate = new class implements DelegateHttpClient {
            public function request(Request $request, Cancellation $cancellation): Response
            {
                throw new \RuntimeException('client diagnostic: X-Vault-Token=secret-token-sentinel');
            }
        };

        try {
            new VaultMasterKeyProvider(
                vaultAddr: 'https://vault.internal',
                vaultToken: 'secret-token-sentinel',
                httpClient: new HttpClient($delegate, []),
            );
            self::fail('Expected Vault request to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Unable to read master keys from Vault.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('secret-token-sentinel', (string) $e);
        }
    }

    public function test_invalid_vault_url_does_not_expose_secret_path(): void
    {
        try {
            new VaultMasterKeyProvider(
                vaultAddr: 'https://vault.internal:99999',
                vaultToken: 'secret-token',
                vaultPath: 'secret/data/sensitive-path-token-sentinel',
                httpClient: self::client(200, '{}'),
            );
            self::fail('Expected invalid Vault URL to fail.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('sensitive-path-token-sentinel', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_vault_address_with_userinfo_is_rejected_before_sending_token(): void
    {
        $delegate = new class implements DelegateHttpClient {
            public function request(Request $request, Cancellation $cancellation): Response
            {
                TestCase::fail('Vault token must not be sent to an address containing userinfo.');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault address must be an HTTPS URL without credentials, query, or fragment.');
        new VaultMasterKeyProvider(
            vaultAddr: 'https://expected.vault.internal@other.example',
            vaultToken: 'secret-token',
            httpClient: new HttpClient($delegate, []),
        );
    }

    public function test_vault_error_does_not_repeat_secret_path(): void
    {
        try {
            new VaultMasterKeyProvider(
                vaultAddr: 'https://vault.internal',
                vaultToken: 'secret-token',
                vaultPath: 'secret/data/sensitive-path-token-sentinel',
                httpClient: self::client(403, 'denied'),
            );
            self::fail('Expected Vault error response to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Vault returned HTTP 403.', $e->getMessage());
            self::assertStringNotContainsString('sensitive-path-token-sentinel', $e->getMessage());
        }
    }

    public function test_vault_secret_path_rejects_query_tokens(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault secret path must be a non-empty URL path without query or fragment.');
        new VaultMasterKeyProvider(
            vaultAddr: 'https://vault.internal',
            vaultToken: 'secret-token',
            vaultPath: 'secret/data/master-key?token=path-token-sentinel',
            httpClient: self::client(200, '{}'),
        );
    }

    private static function client(int $status, string $body): HttpClient
    {
        $delegate = new class ($status, $body) implements DelegateHttpClient {
            public function __construct(
                private readonly int $status,
                private readonly string $body,
            ) {}

            public function request(Request $request, Cancellation $cancellation): Response
            {
                return new Response('1.1', $this->status, null, [], $this->body, $request);
            }
        };

        return new HttpClient($delegate, []);
    }
}
