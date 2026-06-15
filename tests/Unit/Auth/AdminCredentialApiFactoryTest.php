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

    public function test_requires_admin_token_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('External IAM admin API requires "admin_token".');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'public_key' => $this->publicKey(),
        ], $this->credentialProvider());
    }

    public function test_requires_verification_key_when_enabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('External IAM admin API requires JWT verification keys.');

        AdminCredentialApiFactory::create([
            'enabled' => true,
            'admin_token' => 'admin-secret',
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
                'kty' => 'RSA',
                'kid' => 'key-1',
                'n' => 'AQAB',
                'e' => 'AQAB',
            ]],
        ], JSON_THROW_ON_ERROR));

        try {
            $handler = AdminCredentialApiFactory::create([
                'enabled' => true,
                'admin_token' => 'admin-secret',
                'jwks_path' => $path,
            ], $this->credentialProvider());
        } finally {
            @unlink($path);
        }

        $this->assertInstanceOf(AdminCredentialApiHandler::class, $handler);
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
        return <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAECAwEAAQ==
-----END PUBLIC KEY-----
PEM;
    }
}
