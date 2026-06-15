<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use PHPUnit\Framework\TestCase;

final class CredentialManagerTest extends TestCase
{
    private CredentialManager $manager;

    private InMemoryCredentialProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new InMemoryCredentialProvider();
        $this->manager = new CredentialManager($this->provider);
    }

    public function test_generate_access_key_id_format(): void
    {
        $key = $this->manager->generateAccessKeyId();

        $this->assertSame(20, strlen($key));
        $this->assertStringStartsWith('AKIA', $key);
        $this->assertMatchesRegularExpression('/^AKIA[A-F0-9]{16}$/', $key);
    }

    public function test_generate_access_key_id_uniqueness(): void
    {
        $key1 = $this->manager->generateAccessKeyId();
        $key2 = $this->manager->generateAccessKeyId();

        $this->assertNotSame($key1, $key2);
    }

    public function test_generate_secret_access_key_format(): void
    {
        $secret = $this->manager->generateSecretAccessKey();

        $this->assertSame(40, strlen($secret));
        $this->assertNotFalse(base64_decode($secret, true), 'Secret key must be valid base64');
    }

    public function test_generate_session_token_format(): void
    {
        $token = $this->manager->generateSessionToken();

        $this->assertNotSame('', $token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    public function test_generate_secret_access_key_uniqueness(): void
    {
        $secret1 = $this->manager->generateSecretAccessKey();
        $secret2 = $this->manager->generateSecretAccessKey();

        $this->assertNotSame($secret1, $secret2);
    }

    public function test_create_credential_auto_generates_keys(): void
    {
        $credential = $this->manager->createCredential('owner-1', 'Test User');

        $this->assertSame(20, strlen($credential->accessKeyId));
        $this->assertStringStartsWith('AKIA', $credential->accessKeyId);
        $this->assertSame(40, strlen($credential->secretAccessKey));
        $this->assertSame('owner-1', $credential->ownerId);
        $this->assertSame('Test User', $credential->displayName);
        $this->assertTrue($credential->isActive);
    }

    public function test_create_credential_with_custom_keys(): void
    {
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $credential = $this->manager->createCredential(
            ownerId: 'owner-1',
            displayName: 'Custom',
            accessKeyId: 'CUSTOM_ACCESS_KEY_ID',
            secretAccessKey: 'custom-secret-key-value',
            sessionToken: 'session-token',
            expiresAt: $expiresAt,
        );

        $this->assertSame('CUSTOM_ACCESS_KEY_ID', $credential->accessKeyId);
        $this->assertSame('custom-secret-key-value', $credential->secretAccessKey);
        $this->assertSame('session-token', $credential->sessionToken);
        $this->assertSame($expiresAt, $credential->expiresAt);
    }

    public function test_create_credential_persists_to_provider(): void
    {
        $credential = $this->manager->createCredential('owner-1', 'Test');

        $retrieved = $this->provider->getCredential($credential->accessKeyId);
        $this->assertNotNull($retrieved);
        $this->assertSame($credential->accessKeyId, $retrieved->accessKeyId);
        $this->assertSame('owner-1', $retrieved->ownerId);
    }

    public function test_list_credentials(): void
    {
        $this->manager->createCredential('owner-1', 'User 1');
        $this->manager->createCredential('owner-2', 'User 2');
        $this->manager->createCredential('owner-3', 'User 3');

        $list = $this->manager->listCredentials();

        $this->assertCount(3, $list);
    }

    public function test_list_credentials_empty(): void
    {
        $this->assertSame([], $this->manager->listCredentials());
    }

    public function test_get_credential(): void
    {
        $created = $this->manager->createCredential('owner-1', 'Test');

        $retrieved = $this->manager->getCredential($created->accessKeyId);
        $this->assertNotNull($retrieved);
        $this->assertSame($created->accessKeyId, $retrieved->accessKeyId);
    }

    public function test_get_credential_not_found(): void
    {
        $this->assertNull($this->manager->getCredential('NONEXISTENT'));
    }

    public function test_set_active_deactivates(): void
    {
        $created = $this->manager->createCredential('owner-1', 'Test');
        $this->assertTrue($created->isActive);

        $updated = $this->manager->setActive($created->accessKeyId, false);

        $this->assertFalse($updated->isActive);
        $this->assertSame($created->accessKeyId, $updated->accessKeyId);
        $this->assertSame($created->ownerId, $updated->ownerId);
    }

    public function test_set_active_activates(): void
    {
        $created = $this->manager->createCredential('owner-1', 'Test');
        $this->manager->setActive($created->accessKeyId, false);

        $reactivated = $this->manager->setActive($created->accessKeyId, true);

        $this->assertTrue($reactivated->isActive);
    }

    public function test_set_active_not_found_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credential not found: NONEXISTENT');

        $this->manager->setActive('NONEXISTENT', false);
    }

    public function test_delete_credential_returns_true(): void
    {
        $created = $this->manager->createCredential('owner-1', 'Test');

        $this->assertTrue($this->manager->deleteCredential($created->accessKeyId));
    }

    public function test_delete_nonexistent_returns_false(): void
    {
        $this->assertFalse($this->manager->deleteCredential('NONEXISTENT'));
    }

    public function test_delete_removes_from_provider(): void
    {
        $created = $this->manager->createCredential('owner-1', 'Test');
        $this->manager->deleteCredential($created->accessKeyId);

        $this->assertNull($this->provider->getCredential($created->accessKeyId));
    }

    public function test_mask_secret_key(): void
    {
        $this->assertSame('**********1234', $this->manager->maskSecretKey('abcdefghij1234'));
    }

    public function test_mask_secret_key_40_chars(): void
    {
        $secret = str_repeat('x', 36) . 'EKEY';

        $this->assertSame(str_repeat('*', 36) . 'EKEY', $this->manager->maskSecretKey($secret));
    }

    public function test_mask_secret_key_short(): void
    {
        $this->assertSame('**', $this->manager->maskSecretKey('ab'));
    }

    public function test_mask_secret_key_exactly_4(): void
    {
        $this->assertSame('****', $this->manager->maskSecretKey('abcd'));
    }

    public function test_mask_secret_key_5_chars(): void
    {
        $this->assertSame('*efgh', $this->manager->maskSecretKey('defgh'));
    }
}
