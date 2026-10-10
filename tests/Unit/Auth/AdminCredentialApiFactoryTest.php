<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\External\AdminCredentialApiFactory;
use OpsFour\S3Server\Auth\External\AdminCredentialApiHandler;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use PHPUnit\Framework\TestCase;

final class AdminCredentialApiFactoryTest extends TestCase
{
    public function test_returns_null_when_external_iam_is_disabled(): void
    {
        $handler = AdminCredentialApiFactory::create([], $this->credentialProvider());

        $this->assertNull($handler);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('disabledValues')]
    public function test_accepts_documented_disabled_values(bool|string $value): void
    {
        self::assertNull(AdminCredentialApiFactory::create([
            'enabled' => $value,
        ], $this->credentialProvider()));
    }

    /** @return iterable<string, array{bool|string}> */
    public static function disabledValues(): iterable
    {
        yield 'boolean false' => [false];
        yield 'string false' => ['false'];
        yield 'uppercase string false' => ['FALSE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEnabledValues')]
    public function test_rejects_invalid_explicit_enabled_values(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('External IAM enabled');

        AdminCredentialApiFactory::create([
            'enabled' => $value,
        ], $this->credentialProvider());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidEnabledValues(): iterable
    {
        yield 'typo' => ['treu'];
        yield 'legacy truthy integer' => [1];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    public function test_requires_admin_token_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('External IAM admin API requires "admin_token".');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'public_key' => $this->publicKey(),
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
        ], $this->credentialProvider());
    }

    public function test_requires_verification_key_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('External IAM admin API requires JWT verification keys.');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
        ], $this->credentialProvider());
    }

    public function test_rejects_invalid_single_issuer_key_at_startup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2048 bits');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => 'not-a-public-key',
        ], $this->credentialProvider());
    }

    public function test_requires_expected_issuer_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one expected JWT issuer');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
        ], $this->credentialProvider());
    }

    public function test_requires_expected_audience_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one expected JWT audience');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
        ], $this->credentialProvider());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClockSkewValues')]
    public function test_invalid_configured_clock_skew_fails_at_startup(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clock_skew_seconds');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $this->publicKey(),
            'clock_skew_seconds' => $value,
        ], $this->credentialProvider());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidClockSkewValues(): array
    {
        return [
            'malformed number' => ['oops'],
            'negative number' => ['-1'],
            'fractional number' => ['0.5'],
            'above maximum' => ['3601'],
            'integer overflow-sized allowance' => [PHP_INT_MAX],
        ];
    }

    public function test_accepts_maximum_clock_skew(): void
    {
        self::assertInstanceOf(AdminCredentialApiHandler::class, AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $this->publicKey(),
            'clock_skew_seconds' => 3_600,
        ], $this->credentialProvider()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStringOptions')]
    public function test_rejects_invalid_explicit_string_options(string $name, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $this->publicKey(),
            $name => $value,
        ], $this->credentialProvider());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidStringOptions(): iterable
    {
        yield 'owner claim wrong type' => ['owner_claim', ['tenant_id']];
        yield 'owner claim empty' => ['owner_claim', '  '];
        yield 'display name claim wrong type' => ['display_name_claim', 123];
        yield 'groups claim empty' => ['groups_claim', ''];
        yield 'policy names claim wrong type' => ['policy_names_claim', false];
        yield 'allowed prefixes claim wrong type' => ['allowed_prefixes_claim', []];
        yield 'owner prefix wrong type' => ['owner_prefix', 42];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTrustLists')]
    public function test_rejects_invalid_explicit_trust_lists(string $name, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $this->publicKey(),
            $name => $value,
        ], $this->credentialProvider());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidTrustLists(): iterable
    {
        yield 'issuer scalar type' => ['allowed_issuers', 123];
        yield 'issuer invalid array member' => ['allowed_issuers', ['https://issuer.example.test', 123]];
        yield 'audience empty array member' => ['allowed_audiences', ['s3-admin', '']];
    }

    public function test_multiple_issuers_require_keys_by_issuer_instead_of_global_public_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('keys_by_issuer');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'public_key' => $this->publicKey(),
            'allowed_issuers' => ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            'audience' => 's3-admin',
        ], $this->credentialProvider());
    }

    public function test_multiple_issuers_accept_explicit_key_sets(): void
    {
        $handler = AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'allowed_issuers' => ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            'audience' => 's3-admin',
            'keys_by_issuer' => [
                'https://issuer-a.example.test' => ['a-key' => $this->generatedPublicKey()],
                'https://issuer-b.example.test' => ['b-key' => $this->generatedPublicKey()],
            ],
        ], $this->credentialProvider());

        self::assertInstanceOf(AdminCredentialApiHandler::class, $handler);
    }

    public function test_multiple_issuers_reject_the_same_signing_key_in_both_key_sets(): void
    {
        $publicKey = $this->generatedPublicKey();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('shared by multiple issuers');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'allowed_issuers' => ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            'audience' => 's3-admin',
            'keys_by_issuer' => [
                'https://issuer-a.example.test' => ['key-a' => $publicKey],
                'https://issuer-b.example.test' => ['key-b' => $publicKey],
            ],
        ], $this->credentialProvider());
    }

    public function test_multiple_issuers_reject_missing_issuer_key_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires keys for issuer');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'allowed_issuers' => ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            'audience' => 's3-admin',
            'keys_by_issuer' => [
                'https://issuer-a.example.test' => ['key-1' => $this->publicKey()],
            ],
        ], $this->credentialProvider());
    }

    public function test_multiple_issuers_reject_unknown_additional_key_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('include an issuer that is not allowed');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'allowed_issuers' => ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            'audience' => 's3-admin',
            'keys_by_issuer' => [
                'https://issuer-a.example.test' => ['key-a' => $this->generatedPublicKey()],
                'https://issuer-b.example.test' => ['key-b' => $this->generatedPublicKey()],
                'https://issuer-c.example.test' => ['key-c' => $this->generatedPublicKey()],
            ],
        ], $this->credentialProvider());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeySources')]
    public function test_rejects_invalid_explicit_key_source(string $name, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $this->publicKey(),
            $name => $value,
        ], $this->credentialProvider());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidKeySources(): iterable
    {
        yield 'keys wrong type' => ['keys', 'wrong-shape'];
        yield 'keys empty' => ['keys', []];
        yield 'JWKS path wrong type' => ['jwks_path', ['keys.json']];
        yield 'JWKS path empty' => ['jwks_path', ''];
        yield 'public key path wrong type' => ['public_key_path', 123];
    }

    public function test_rejects_multiple_single_issuer_key_sources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one JWT verification key source');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'keys' => ['key-1' => $this->publicKey()],
            'public_key' => $this->publicKey(),
        ], $this->credentialProvider());
    }

    /** @param array<string, mixed> $metadata */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidJwkUsage')]
    public function test_rejects_jwk_that_is_not_authorized_for_rs256_verification(array $metadata): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'keys' => ['key-1' => ['pem' => $this->publicKey(), ...$metadata]],
        ], $this->credentialProvider());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidJwkUsage(): iterable
    {
        yield 'encryption use' => [['use' => 'enc']];
        yield 'different algorithm' => [['alg' => 'RS512']];
        yield 'missing verify operation' => [['key_ops' => ['encrypt']]];
        yield 'invalid key operations type' => [['key_ops' => 'verify']];
    }

    public function test_rejects_rsa_key_shorter_than_2048_bits(): void
    {
        $weakKey = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($weakKey);
        $details = openssl_pkey_get_details($weakKey);
        self::assertIsArray($details);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2048 bits');
        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
            'issuer' => 'https://issuer.example.test',
            'audience' => 's3-admin',
            'public_key' => $details['key'],
        ], $this->credentialProvider());
    }

    public function test_creates_handler_from_inline_public_key(): void
    {
        $handler = AdminCredentialApiFactory::create([
            'enabled' => 'true',
            'admin_token' => 'admin-secret',
            'public_key' => $this->publicKey(),
            'issuer' => 'https://keycloak.example.test/realms/storage',
            'audience' => 's3-admin,account',
            'owner_claim' => 'tenant_id',
            'policy_names_claim' => 's3_policies',
            'allowed_prefixes_claim' => 's3_prefixes',
        ], $this->credentialProvider());

        $this->assertInstanceOf(AdminCredentialApiHandler::class, $handler);
    }

    public function test_creates_handler_from_jwks_file(): void
    {
        $path = sys_get_temp_dir() . '/s3-external-iam-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            'keys' => [[
                'kid' => 'key-1',
                'pem' => $this->publicKey(),
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $handler = AdminCredentialApiFactory::create([
                'enabled' => true,
                'admin_token' => 'admin-secret',
                'jwks_path' => $path,
                'issuer' => 'https://issuer.example.test',
                'audience' => 's3-admin',
            ], $this->credentialProvider());
        } finally {
            @unlink($path);
        }

        $this->assertInstanceOf(AdminCredentialApiHandler::class, $handler);
    }

    public function test_rejects_oversized_jwks_file(): void
    {
        $path = sys_get_temp_dir() . '/s3-external-iam-large-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, str_repeat('x', 1_048_577));

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('External IAM JWKS file exceeds 1048576 bytes');

            AdminCredentialApiFactory::create([
                'enabled' => true,
                'admin_token' => 'admin-secret',
                'jwks_path' => $path,
                'issuer' => 'https://issuer.example.test',
                'audience' => 's3-admin',
            ], $this->credentialProvider());
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_duplicate_jwks_key_ids(): void
    {
        $path = sys_get_temp_dir() . '/s3-external-iam-duplicate-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            'keys' => [
                ['kid' => 'duplicate', 'pem' => $this->publicKey()],
                ['kid' => 'duplicate', 'pem' => $this->publicKey()],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('key IDs must be unique');
            AdminCredentialApiFactory::create([
                'enabled' => true,
                'admin_token' => 'admin-secret',
                'jwks_path' => $path,
                'issuer' => 'https://issuer.example.test',
                'audience' => 's3-admin',
            ], $this->credentialProvider());
        } finally {
            @unlink($path);
        }
    }

    private function credentialProvider(): InMemoryCredentialProvider
    {
        return new InMemoryCredentialProvider(new Credential(
            accessKeyId: 'AKIATESTKEY12345678',
            secretAccessKey: 'secret',
            ownerId: 'owner',
            displayName: 'Owner',
        ));
    }

    private function publicKey(): string
    {
        static $key = null;
        if ($key === null) {
            $key = $this->generatedPublicKey();
        }

        return $key;
    }

    private function generatedPublicKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return $details['key'];
    }
}
