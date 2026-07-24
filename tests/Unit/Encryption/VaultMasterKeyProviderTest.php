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
