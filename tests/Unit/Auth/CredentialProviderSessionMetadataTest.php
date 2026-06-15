<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use PHPUnit\Framework\TestCase;

final class CredentialProviderSessionMetadataTest extends TestCase
{
    public function test_config_file_provider_persists_session_token_and_expiration(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-session-' . bin2hex(random_bytes(4)) . '.json';
        $expiresAt = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));

        try {
            $provider = new ConfigFileCredentialProvider($path);
            $provider->putCredential($this->credential($expiresAt));

            $reloaded = new ConfigFileCredentialProvider($path);
            $credential = $reloaded->getCredential('AKIASESSION12345678');

            $this->assertNotNull($credential);
            $this->assertSame('session-token-123', $credential->sessionToken);
            $this->assertEquals($expiresAt->getTimestamp(), $credential->expiresAt?->getTimestamp());
            $this->assertSame(['uploads'], $credential->policyNames);
            $this->assertSame(['incoming/'], $credential->allowedPrefixes);
        } finally {
            @unlink($path);
        }
    }

    public function test_database_provider_persists_session_token_and_expiration(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-session-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $path);
        $provider = new DatabaseCredentialProvider($pdo);
        $provider->initialize();
        $expiresAt = new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'));

        try {
            $provider->putCredential($this->credential($expiresAt));

            $reloaded = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path));
            $reloaded->initialize();
            $credential = $reloaded->getCredential('AKIASESSION12345678');

            $this->assertNotNull($credential);
            $this->assertSame('session-token-123', $credential->sessionToken);
            $this->assertEquals($expiresAt->getTimestamp(), $credential->expiresAt?->getTimestamp());
            $this->assertSame(['uploads'], $credential->policyNames);
            $this->assertSame(['incoming/'], $credential->allowedPrefixes);
        } finally {
            @unlink($path);
        }
    }

    private function credential(\DateTimeImmutable $expiresAt): Credential
    {
        return new Credential(
            accessKeyId: 'AKIASESSION12345678',
            secretAccessKey: 'secret',
            ownerId: 'tenant:acme',
            displayName: 'Alice',
            isActive: true,
            sessionToken: 'session-token-123',
            expiresAt: $expiresAt,
            policyNames: ['uploads'],
            allowedPrefixes: ['incoming/'],
        );
    }
}
