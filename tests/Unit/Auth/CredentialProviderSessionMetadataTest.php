<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await;

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
            $this->assertSame(0o600, fileperms($path) & 0o777);
            $this->assertSame([], glob($path . '.tmp.*') ?: []);
        } finally {
            @unlink($path);
        }
    }

    public function test_config_file_provider_serializes_concurrent_atomic_updates(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-concurrent-' . bin2hex(random_bytes(4)) . '.json';

        try {
            $provider = new ConfigFileCredentialProvider($path);
            await([
                async(fn() => $provider->putCredential($this->credential(
                    new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
                ))),
                async(fn() => $provider->putCredential(new Credential(
                    accessKeyId: 'AKIASECOND123456789',
                    secretAccessKey: 'secret-2',
                    ownerId: 'tenant:second',
                ))),
            ]);

            $reloaded = new ConfigFileCredentialProvider($path);
            $this->assertCount(2, $reloaded->listCredentials());
            $this->assertNotNull($reloaded->getCredential('AKIASESSION12345678'));
            $this->assertNotNull($reloaded->getCredential('AKIASECOND123456789'));
        } finally {
            @unlink($path);
            foreach (glob($path . '.tmp.*') ?: [] as $tempPath) {
                @unlink($tempPath);
            }
        }
    }

    public function test_config_file_provider_rejects_non_list_json(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-invalid-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, '{"accessKeyId":"unsafe"}');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials file must contain a JSON array.');

            new ConfigFileCredentialProvider($path);
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

    public function test_database_provider_refreshes_cross_node_revocation_after_cache_ttl(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-revocation-' . bin2hex(random_bytes(4)) . '.sqlite';
        $writer = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 0.0);
        $reader = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 0.01);
        $writer->initialize();
        $reader->initialize();

        try {
            $active = $this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));
            $writer->putCredential($active);
            $this->assertTrue($reader->getCredential($active->accessKeyId)?->isActive);

            $writer->putCredential(new Credential(
                accessKeyId: $active->accessKeyId,
                secretAccessKey: $active->secretAccessKey,
                ownerId: $active->ownerId,
                displayName: $active->displayName,
                isActive: false,
                sessionToken: $active->sessionToken,
                expiresAt: $active->expiresAt,
                policyNames: $active->policyNames,
                allowedPrefixes: $active->allowedPrefixes,
            ));

            $cached = $reader->getCredential($active->accessKeyId);
            $this->assertTrue($cached->isActive);
            usleep(20_000);
            $refreshed = $reader->getCredential($active->accessKeyId);
            $this->assertFalse($refreshed->isActive);
        } finally {
            @unlink($path);
        }
    }

    public function test_database_provider_handles_postgres_boolean_strings_safely(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();
        $pdo->exec(
            "INSERT INTO s3_credentials "
            . "(access_key_id, secret_access_key, owner_id, display_name, is_active) "
            . "VALUES ('AKIABOOLEAN123456789', 'secret', 'owner', 'Owner', 'f')",
        );

        $credential = $provider->getCredential('AKIABOOLEAN123456789');

        $this->assertNotNull($credential);
        $this->assertFalse($credential->isActive);
    }

    public function test_database_provider_cache_has_a_hard_entry_limit(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $provider = new DatabaseCredentialProvider($pdo, 60.0, 2);
        $provider->initialize();

        foreach (['ONE', 'TWO', 'THREE'] as $suffix) {
            $provider->putCredential(new Credential(
                accessKeyId: 'AKIA' . $suffix . '123456789012',
                secretAccessKey: 'secret-' . $suffix,
                ownerId: 'owner-' . $suffix,
            ));
        }

        $pdo->exec("DELETE FROM s3_credentials WHERE access_key_id IN ('AKIAONE123456789012', 'AKIATWO123456789012')");

        self::assertSame('owner-TWO', $provider->getCredential('AKIATWO123456789012')?->ownerId);
        self::assertNull($provider->getCredential('AKIAONE123456789012'));
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
