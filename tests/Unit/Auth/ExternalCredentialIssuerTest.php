<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\External\ExternalCredentialIssuer;
use OpsFour\S3Server\Auth\External\ExternalIdentity;
use OpsFour\S3Server\Auth\External\ExternalIdentityAuthenticationException;
use OpsFour\S3Server\Auth\External\JwtExternalIdentityProvider;
use OpsFour\S3Server\Auth\External\OidcClaimMapper;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCredentialIssuerTest extends TestCase
{
    public function test_oidc_claim_mapper_maps_keycloak_claims(): void
    {
        $mapper = new OidcClaimMapper(
            ownerClaim: 'tenant_id',
            policyNamesClaim: 's3_policies',
            allowedPrefixesClaim: 's3_prefixes',
            ownerPrefix: 'tenant:',
        );

        $identity = $mapper->map([
            'sub' => 'user-123',
            'iss' => 'https://keycloak.example.test/realms/storage',
            'preferred_username' => 'alice',
            'tenant_id' => 'acme',
            'groups' => ['/storage-admins', '/engineering', '/engineering'],
            's3_policies' => ['readonly', 'uploads'],
            's3_prefixes' => ['incoming/', 'exports/'],
        ]);

        $this->assertSame('user-123', $identity->subject);
        $this->assertSame('https://keycloak.example.test/realms/storage', $identity->issuer);
        $this->assertSame('tenant:acme', $identity->ownerId);
        $this->assertSame('alice', $identity->displayName);
        $this->assertSame(['/storage-admins', '/engineering'], $identity->groups);
        $this->assertSame(['readonly', 'uploads'], $identity->policyNames);
        $this->assertSame(['incoming/', 'exports/'], $identity->allowedPrefixes);
    }

    public function test_oidc_claim_mapper_rejects_missing_required_owner_claim(): void
    {
        $mapper = new OidcClaimMapper(ownerClaim: 'tenant_id');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("OIDC claim 'tenant_id' must be a non-empty string.");

        $mapper->map([
            'sub' => 'user-123',
        ]);
    }

    public function test_issue_creates_persisted_sigv4_credential_for_external_identity(): void
    {
        $provider = new InMemoryCredentialProvider();
        $issuer = new ExternalCredentialIssuer(new CredentialManager($provider));
        $identity = new ExternalIdentity(
            subject: 'user-123',
            issuer: 'https://keycloak.example.test/realms/storage',
            ownerId: 'tenant:acme',
            displayName: 'alice',
            groups: ['/storage-admins'],
            policyNames: ['uploads'],
            allowedPrefixes: ['incoming/'],
        );

        $issued = $issuer->issue($identity, ttlSeconds: 900);

        $stored = $provider->getCredential($issued->credential->accessKeyId);
        $this->assertNotNull($stored);
        $this->assertSame('tenant:acme', $stored->ownerId);
        $this->assertSame('alice', $stored->displayName);
        $this->assertTrue($stored->isActive);
        $this->assertSame($identity, $issued->identity);
        $this->assertSame(['uploads'], $issued->policyNames);
        $this->assertSame(['incoming/'], $issued->allowedPrefixes);
        $this->assertSame(['uploads'], $stored->policyNames);
        $this->assertSame(['incoming/'], $stored->allowedPrefixes);
        $this->assertNotNull($issued->expiresAt);
        $this->assertIsString($stored->sessionToken);
        $this->assertNotSame('', $stored->sessionToken);
        $this->assertEquals($issued->expiresAt, $stored->expiresAt);
        $this->assertGreaterThan(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $issued->expiresAt);
    }

    public function test_issue_rejects_invalid_ttl(): void
    {
        $issuer = new ExternalCredentialIssuer(new CredentialManager(new InMemoryCredentialProvider()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credential TTL must be at least one second.');

        $issuer->issue(new ExternalIdentity('user-123', '', 'tenant:acme'), ttlSeconds: 0);
    }

    public function test_revoke_deletes_issued_credential_without_restart(): void
    {
        $provider = new InMemoryCredentialProvider();
        $issuer = new ExternalCredentialIssuer(new CredentialManager($provider));

        $issued = $issuer->issue(new ExternalIdentity('user-123', '', 'tenant:acme'));

        $this->assertNotNull($provider->getCredential($issued->credential->accessKeyId));
        $this->assertTrue($issuer->revoke($issued->credential->accessKeyId));
        $this->assertNull($provider->getCredential($issued->credential->accessKeyId));
        $this->assertFalse($issuer->revoke($issued->credential->accessKeyId));
    }

    public function test_jwt_external_identity_provider_validates_rs256_keycloak_token(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();
        $issuer = 'https://keycloak.example.test/realms/storage';

        $token = $this->signJwt($privateKey, [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'key-1',
        ], [
            'sub' => 'user-123',
            'iss' => $issuer,
            'aud' => ['s3-admin'],
            'exp' => time() + 300,
            'iat' => time(),
            'preferred_username' => 'alice',
            'tenant_id' => 'acme',
            'groups' => ['/storage-admins'],
            's3_policies' => ['uploads'],
            's3_prefixes' => ['incoming/'],
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(
                ownerClaim: 'tenant_id',
                policyNamesClaim: 's3_policies',
                allowedPrefixesClaim: 's3_prefixes',
                ownerPrefix: 'tenant:',
            ),
            keys: ['key-1' => $jwk],
            allowedIssuers: [$issuer],
            allowedAudiences: ['s3-admin'],
        );

        $identity = $provider->authenticate("Bearer {$token}");

        $this->assertSame('user-123', $identity->subject);
        $this->assertSame('tenant:acme', $identity->ownerId);
        $this->assertSame('alice', $identity->displayName);
        $this->assertSame(['/storage-admins'], $identity->groups);
        $this->assertSame(['uploads'], $identity->policyNames);
        $this->assertSame(['incoming/'], $identity->allowedPrefixes);
    }

    public function test_jwt_external_identity_provider_rejects_disallowed_audience(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'aud' => 'other-client',
            'exp' => time() + 300,
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedAudiences: ['s3-admin'],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT audience is not allowed.');

        $provider->authenticate($token);
    }

    public function test_jwt_external_identity_provider_rejects_expired_token(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'exp' => time() - 120,
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT has expired.');

        $provider->authenticate($token);
    }

    public function test_jwt_external_identity_provider_rejects_tampered_signature(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'exp' => time() + 300,
        ]);
        [$header, $payload] = explode('.', $token);
        $tampered = "{$header}.{$payload}.invalid";

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT signature is invalid.');

        $provider->authenticate($tampered);
    }

    /**
     * @return array{0: string, 1: array{kty: string, kid: string, use: string, alg: string, n: string, e: string}}
     */
    private function createRsaKeyPair(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL extension is required for JWT tests.');
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $exported = openssl_pkey_export($key, $privateKey);
        $this->assertTrue($exported);

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('rsa', $details);
        $this->assertIsArray($details['rsa']);

        return [
            $privateKey,
            [
                'kty' => 'RSA',
                'kid' => 'key-1',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private function signJwt(string $privateKey, array $header, array $claims): string
    {
        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = "{$encodedHeader}.{$encodedClaims}";

        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $this->assertTrue($signed);

        return "{$signingInput}." . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
