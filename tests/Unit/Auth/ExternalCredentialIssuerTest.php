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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCredentialIssuerTest extends TestCase
{
    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function missingJwtValidationScope(): iterable
    {
        yield 'issuer missing' => [[], ['s3-admin']];
        yield 'audience missing' => [['https://issuer.example.test'], []];
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function blankJwtValidationScope(): iterable
    {
        yield 'issuer blank' => [[''], ['s3-admin']];
        yield 'audience blank' => [['https://issuer.example.test'], ['']];
    }

    /**
     * @param list<string> $issuers
     * @param list<string> $audiences
     */
    #[DataProvider('missingJwtValidationScope')]
    public function test_direct_jwt_provider_requires_issuer_and_audience_scope(array $issuers, array $audiences): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT verification requires expected issuer and audience values.');

        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => 'public-key'],
            allowedIssuers: $issuers,
            allowedAudiences: $audiences,
        );
    }

    /**
     * @param list<string> $issuers
     * @param list<string> $audiences
     */
    #[DataProvider('blankJwtValidationScope')]
    public function test_direct_jwt_provider_rejects_blank_scope_values(array $issuers, array $audiences): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT verification requires non-empty issuer and audience values.');

        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => 'public-key'],
            allowedIssuers: $issuers,
            allowedAudiences: $audiences,
        );
    }

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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingPrefixScopes(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['s3_prefixes' => null]];
        yield 'empty list' => [['s3_prefixes' => []]];
        yield 'empty string' => [['s3_prefixes' => '']];
    }

    /** @param array<string, mixed> $prefixClaim */
    #[DataProvider('missingPrefixScopes')]
    public function test_configured_oidc_prefix_claim_requires_nonempty_scope(array $prefixClaim): void
    {
        $mapper = new OidcClaimMapper(allowedPrefixesClaim: 's3_prefixes');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("OIDC claim 's3_prefixes' must contain at least one prefix.");

        $mapper->map([
            'sub' => 'bucket-owner',
            'iss' => 'https://issuer.example.test',
            ...$prefixClaim,
        ]);
    }

    public function test_signed_oidc_token_without_configured_prefix_scope_cannot_issue_owner_credential(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();
        $identityProvider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(allowedPrefixesClaim: 's3_prefixes'),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
        );
        $credentials = new InMemoryCredentialProvider();
        $credentialIssuer = new ExternalCredentialIssuer(new CredentialManager($credentials));
        $claims = [
            'sub' => 'bucket-owner',
            'iss' => 'https://issuer.example.test',
            'aud' => 's3-admin',
            'exp' => time() + 300,
        ];
        $header = ['alg' => 'RS256', 'kid' => 'key-1'];

        try {
            $identity = $identityProvider->authenticate($this->signJwt($privateKey, $header, $claims));
            $credentialIssuer->issue($identity);
            self::fail('A missing configured prefix claim must not issue an unrestricted credential.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame("OIDC claim 's3_prefixes' must contain at least one prefix.", $e->getMessage());
        }

        self::assertSame([], $credentials->listCredentials());

        $identity = $identityProvider->authenticate($this->signJwt($privateKey, $header, [
            ...$claims,
            's3_prefixes' => ['incoming/'],
        ]));
        $issued = $credentialIssuer->issue($identity);
        self::assertSame(['incoming/'], $issued->credential->allowedPrefixes);
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

        $issued = $issuer->issue($identity, ttlSeconds: 3600);

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

    /** @return iterable<string, array{int}> */
    public static function invalidCredentialTtls(): iterable
    {
        yield 'zero' => [0];
        yield 'below one hour' => [3599];
        yield 'above twelve hours' => [43201];
        yield 'integer maximum' => [PHP_INT_MAX];
    }

    #[DataProvider('invalidCredentialTtls')]
    public function test_issue_rejects_invalid_ttl(int $ttlSeconds): void
    {
        $issuer = new ExternalCredentialIssuer(new CredentialManager(new InMemoryCredentialProvider()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credential TTL must be between 3600 and 43200 seconds.');

        $issuer->issue(new ExternalIdentity('user-123', '', 'tenant:acme'), ttlSeconds: $ttlSeconds);
    }

    public function test_issue_defaults_to_one_hour_and_accepts_twelve_hours(): void
    {
        $issuer = new ExternalCredentialIssuer(new CredentialManager(new InMemoryCredentialProvider()));
        $identity = new ExternalIdentity('user-123', '', 'tenant:acme');

        foreach ([[null, 3600], [43200, 43200]] as [$requested, $expected]) {
            $before = time();
            $issued = $issuer->issue($identity, $requested);
            $after = time();
            self::assertNotNull($issued->expiresAt);
            self::assertGreaterThanOrEqual($before + $expected, $issued->expiresAt->getTimestamp());
            self::assertLessThanOrEqual($after + $expected, $issued->expiresAt->getTimestamp());
            self::assertNotNull($issued->credential->sessionToken);
        }
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
            'iss' => 'https://issuer.example.test',
            'aud' => 'other-client',
            'exp' => time() + 300,
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT audience is not allowed.');

        $provider->authenticate($token);
    }

    public function test_multiple_issuers_have_separate_owner_ids_and_verification_keys_even_with_same_kid(): void
    {
        [$firstPrivateKey, $firstJwk] = $this->createRsaKeyPair();
        [$secondPrivateKey, $secondJwk] = $this->createRsaKeyPair();
        $firstIssuer = 'https://issuer-a.example.test';
        $secondIssuer = 'https://issuer-b.example.test';
        $claims = [
            'sub' => 'same-subject',
            'aud' => 's3-admin',
            'exp' => time() + 300,
            'tenant_id' => 'same-tenant',
        ];
        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(ownerClaim: 'tenant_id', ownerPrefix: 'tenant:'),
            keys: [],
            allowedIssuers: [$firstIssuer, $secondIssuer],
            allowedAudiences: ['s3-admin'],
            keysByIssuer: [
                $firstIssuer => ['key-1' => $firstJwk],
                $secondIssuer => ['key-1' => $secondJwk],
            ],
        );

        $first = $provider->authenticate($this->signJwt($firstPrivateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            ...$claims, 'iss' => $firstIssuer,
        ]));
        $second = $provider->authenticate($this->signJwt($secondPrivateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            ...$claims, 'iss' => $secondIssuer,
        ]));

        self::assertNotSame($first->ownerId, $second->ownerId);
        self::assertSame(OidcClaimMapper::issuerScopedOwnerId($firstIssuer, 'same-tenant', 'tenant:'), $first->ownerId);
        self::assertSame(OidcClaimMapper::issuerScopedOwnerId($secondIssuer, 'same-tenant', 'tenant:'), $second->ownerId);
        self::assertSame($first->ownerId, $provider->authenticate($this->signJwt($firstPrivateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            ...$claims, 'iss' => $firstIssuer,
        ]))->ownerId);

        $forged = $this->signJwt($firstPrivateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            ...$claims, 'iss' => $secondIssuer,
        ]);
        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT signature is invalid.');
        $provider->authenticate($forged);
    }

    public function test_multiple_issuers_reject_a_global_verification_key_pool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('global key pool');

        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => 'public-key'],
            allowedIssuers: ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            allowedAudiences: ['s3-admin'],
        );
    }

    public function test_multiple_issuers_reject_one_key_present_as_jwk_and_pem(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();
        $private = openssl_pkey_get_private($privateKey);
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('shared by multiple issuers');
        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: [],
            allowedIssuers: ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            allowedAudiences: ['s3-admin'],
            keysByIssuer: [
                'https://issuer-a.example.test' => ['key-1' => $jwk],
                'https://issuer-b.example.test' => ['other-kid' => $details['key']],
            ],
        );
    }

    public function test_issuer_scoped_owner_id_rejects_prefix_that_exceeds_storage_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('255-byte');

        OidcClaimMapper::issuerScopedOwnerId('https://issuer.example.test', 'tenant', str_repeat('p', 187));
    }

    public function test_multi_issuer_provider_rejects_oversized_owner_prefix_at_startup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('255-byte');

        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(ownerPrefix: str_repeat('p', 187)),
            keys: [],
            allowedIssuers: ['https://issuer-a.example.test', 'https://issuer-b.example.test'],
            allowedAudiences: ['s3-admin'],
            keysByIssuer: [
                'https://issuer-a.example.test' => ['key-1' => 'public-key-a'],
                'https://issuer-b.example.test' => ['key-1' => 'public-key-b'],
            ],
        );
    }

    public function test_issuer_scoped_owner_id_encodes_separator_characters_without_collision(): void
    {
        self::assertNotSame(
            OidcClaimMapper::issuerScopedOwnerId("a\0b", 'c'),
            OidcClaimMapper::issuerScopedOwnerId('a', "b\0c"),
        );
    }

    public function test_jwt_external_identity_provider_rejects_expired_token(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'iss' => 'https://issuer.example.test',
            'aud' => 's3-admin',
            'exp' => time() - 120,
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT has expired.');

        $provider->authenticate($token);
    }

    public function test_jwt_external_identity_provider_rejects_excessive_clock_skew(): void
    {
        [, $jwk] = $this->createRsaKeyPair();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 0 and 3600 seconds');
        new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
            clockSkewSeconds: 3_601,
        );
    }

    public function test_jwt_external_identity_provider_rejects_token_without_expiration(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'iss' => 'https://issuer.example.test',
            'aud' => 's3-admin',
        ]);

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
        );

        $this->expectException(ExternalIdentityAuthenticationException::class);
        $this->expectExceptionMessage('JWT expiration is required.');

        $provider->authenticate($token);
    }

    public function test_jwt_external_identity_provider_rejects_tampered_signature(): void
    {
        [$privateKey, $jwk] = $this->createRsaKeyPair();

        $token = $this->signJwt($privateKey, ['alg' => 'RS256', 'kid' => 'key-1'], [
            'sub' => 'user-123',
            'iss' => 'https://issuer.example.test',
            'aud' => 's3-admin',
            'exp' => time() + 300,
        ]);
        [$header, $payload] = explode('.', $token);
        $tampered = "{$header}.{$payload}.invalid";

        $provider = new JwtExternalIdentityProvider(
            mapper: new OidcClaimMapper(),
            keys: ['key-1' => $jwk],
            allowedIssuers: ['https://issuer.example.test'],
            allowedAudiences: ['s3-admin'],
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
