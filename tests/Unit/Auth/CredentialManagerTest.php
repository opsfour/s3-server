<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\CredentialProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_create_with_existing_access_key_does_not_replace_the_credential(): void
    {
        $original = $this->manager->createCredential('first-owner', accessKeyId: 'AKIAEXPLICITKEY0001', secretAccessKey: 'first-secret');

        try {
            $this->manager->createCredential('second-owner', accessKeyId: $original->accessKeyId, secretAccessKey: 'second-secret');
            self::fail('An existing credential was replaced by create.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }

        self::assertSame($original, $this->provider->getCredential($original->accessKeyId));
    }

    public function test_database_create_with_existing_access_key_preserves_another_nodes_credential(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-create-collision-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $second = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 0);
            $original = (new CredentialManager($first))->createCredential(
                'first-owner',
                accessKeyId: 'AKIADBEXPLICIT000001',
                secretAccessKey: 'first-secret',
            );

            try {
                (new CredentialManager($second))->createCredential(
                    'second-owner',
                    accessKeyId: $original->accessKeyId,
                    secretAccessKey: 'second-secret',
                );
                self::fail('A cross-node create replaced an existing credential.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('already exists', $error->getMessage());
            }

            $stored = $second->getCredential($original->accessKeyId);
            self::assertNotNull($stored);
            self::assertSame($original->secretAccessKey, $stored->secretAccessKey);
            self::assertSame($original->ownerId, $stored->ownerId);
            self::assertTrue($stored->isActive);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_generated_access_key_collision_is_retried_with_a_new_id(): void
    {
        $provider = new class implements AtomicCredentialCreator, CredentialProvider {
            /** @var list<string> */
            public array $attemptedIds = [];

            public function createCredentialIfAbsent(Credential $credential): bool
            {
                $this->attemptedIds[] = $credential->accessKeyId;

                return count($this->attemptedIds) > 1;
            }

            public function getCredential(string $accessKeyId): ?Credential
            {
                return null;
            }
            public function listCredentials(): array
            {
                return [];
            }
            public function putCredential(Credential $credential): void {}
            public function deleteCredential(string $accessKeyId): void {}
        };

        $created = (new CredentialManager($provider))->createCredential('owner');

        self::assertCount(2, $provider->attemptedIds);
        self::assertNotSame($provider->attemptedIds[0], $provider->attemptedIds[1]);
        self::assertSame($provider->attemptedIds[1], $created->accessKeyId);
    }

    public function test_create_rejects_provider_without_atomic_insert_capability(): void
    {
        $provider = new class implements CredentialProvider {
            public function getCredential(string $accessKeyId): ?Credential
            {
                return null;
            }
            public function listCredentials(): array
            {
                return [];
            }
            public function putCredential(Credential $credential): void
            {
                throw new \RuntimeException('Unsafe upsert fallback was used.');
            }
            public function deleteCredential(string $accessKeyId): void {}
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support atomic creation');

        (new CredentialManager($provider))->createCredential('owner');
    }

    public function test_activation_cannot_resurrect_a_credential_revoked_by_another_database_node(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-revocation-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $second = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 0);
            $manager = new CredentialManager($first);
            $credential = $manager->createCredential('owner', accessKeyId: 'AKIAREVOKERACE00001');
            self::assertNotNull($manager->getCredential($credential->accessKeyId));

            $second->deleteCredential($credential->accessKeyId);
            try {
                $manager->setActive($credential->accessKeyId, true);
                self::fail('Activation resurrected a revoked credential.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('revoked', $error->getMessage());
            }
            self::assertNull($second->getCredential($credential->accessKeyId));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_activation_cannot_change_a_replaced_secret_from_stale_cache(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-replacement-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $second = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 0);
            $manager = new CredentialManager($first);
            $credential = $manager->createCredential('owner', accessKeyId: 'AKIAREPLACERACE00001');
            $second->putCredential(new Credential(
                accessKeyId: $credential->accessKeyId,
                secretAccessKey: 'new-secret',
                ownerId: 'owner',
                isActive: false,
            ));

            try {
                $manager->setActive($credential->accessKeyId, true);
                self::fail('Activation changed a replacement credential.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('changed', $error->getMessage());
            }
            $current = $second->getCredential($credential->accessKeyId);
            self::assertNotNull($current);
            self::assertSame('new-secret', $current->secretAccessKey);
            self::assertFalse($current->isActive);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_activation_cannot_undo_concurrent_deactivation_from_stale_cache(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-status-race-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $second = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 0);
            $manager = new CredentialManager($first);
            $credential = $manager->createCredential('owner', accessKeyId: 'AKIASTATUSRACE00001');
            self::assertTrue($manager->getCredential($credential->accessKeyId)?->isActive);

            self::assertFalse((new CredentialManager($second))->setActive($credential->accessKeyId, false)->isActive);
            try {
                $manager->setActive($credential->accessKeyId, true);
                self::fail('Activation undid a concurrent credential deactivation.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('changed', $error->getMessage());
            }
            self::assertFalse($second->getCredential($credential->accessKeyId)?->isActive);
            self::assertFalse($first->setActiveIfCurrent($credential, false));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_stale_delete_cannot_remove_a_reissued_database_credential(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-delete-race-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $second = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 0);
            $manager = new CredentialManager($first);
            $original = $manager->createCredential('first-owner', accessKeyId: 'AKIADELETERACE00001', secretAccessKey: 'first-secret');
            $replacement = new Credential($original->accessKeyId, 'second-secret', 'second-owner');
            $second->deleteCredential($original->accessKeyId);
            $second->putCredential($replacement);

            self::assertFalse($manager->deleteCredential($original->accessKeyId));
            $stored = $second->getCredential($original->accessKeyId);
            self::assertNotNull($stored);
            self::assertSame('second-owner', $stored->ownerId);
            self::assertSame('second-secret', $stored->secretAccessKey);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_status_update_on_file_provider_rejects_stale_activation(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-file-status-' . bin2hex(random_bytes(6)) . '.json';
        try {
            $provider = new ConfigFileCredentialProvider($path);
            $original = (new CredentialManager($provider))->createCredential('owner', accessKeyId: 'AKIAFILESTATUS000001');
            self::assertTrue($provider->setActiveIfCurrent($original, false));
            self::assertFalse($provider->setActiveIfCurrent($original, true));
            self::assertFalse($provider->getCredential($original->accessKeyId)?->isActive);
        } finally {
            @unlink($path);
        }
    }

    public function test_multi_provider_chain_refuses_a_partial_delete(): void
    {
        $primary = new InMemoryCredentialProvider();
        $secondary = new InMemoryCredentialProvider();
        $credential = new Credential('AKIACHAINDELETE0001', 'first-secret', 'first-owner');
        $primary->putCredential($credential);
        $secondary->putCredential(new Credential($credential->accessKeyId, 'second-secret', 'second-owner'));
        $chain = new ChainCredentialProvider($primary, $secondary);
        $manager = new CredentialManager($chain);

        try {
            $manager->deleteCredential($credential->accessKeyId);
            self::fail('Multi-provider deletion partially removed the primary credential.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('across multiple credential providers', $error->getMessage());
        }
        self::assertNotNull($primary->getCredential($credential->accessKeyId));
        self::assertNotNull($secondary->getCredential($credential->accessKeyId));

        try {
            $chain->deleteCredential($credential->accessKeyId);
            self::fail('Direct chain deletion partially removed a credential.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('across multiple credential providers', $error->getMessage());
        }
        self::assertNotNull($primary->getCredential($credential->accessKeyId));
        self::assertNotNull($secondary->getCredential($credential->accessKeyId));
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    public static function invalidCredentialFields(): iterable
    {
        yield 'empty owner' => ['ownerId' => ''];
        yield 'oversized owner' => ['ownerId' => str_repeat('o', 256)];
        yield 'owner with invalid UTF-8' => ['ownerId' => "owner-\xC3\x28"];
        yield 'owner with XML-forbidden character' => ['ownerId' => "owner-\u{FFFE}"];
        yield 'invalid access key characters' => [
            'ownerId' => 'owner-1',
            'accessKeyId' => 'invalid/access/key',
        ];
        yield 'empty secret' => [
            'ownerId' => 'owner-1',
            'secretAccessKey' => '',
        ];
        yield 'oversized display name' => [
            'ownerId' => 'owner-1',
            'displayName' => str_repeat('d', 256),
        ];
        yield 'display name with invalid UTF-8' => [
            'ownerId' => 'owner-1',
            'displayName' => "name-\xC3\x28",
        ];
        yield 'display name with XML-forbidden character' => [
            'ownerId' => 'owner-1',
            'displayName' => "name-\u{FFFF}",
        ];
        yield 'too many policy names' => [
            'ownerId' => 'owner-1',
            'policyNames' => array_fill(0, 129, 'policy'),
        ];
        yield 'oversized allowed prefix' => [
            'ownerId' => 'owner-1',
            'allowedPrefixes' => [str_repeat('p', 1025)],
        ];
    }

    /**
     * @param  list<string>  $policyNames
     * @param  list<string>  $allowedPrefixes
     */
    #[DataProvider('invalidCredentialFields')]
    public function test_create_credential_rejects_invalid_or_unpersistable_fields(
        string $ownerId,
        string $displayName = '',
        ?string $accessKeyId = null,
        ?string $secretAccessKey = null,
        array $policyNames = [],
        array $allowedPrefixes = [],
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->createCredential(
            ownerId: $ownerId,
            displayName: $displayName,
            accessKeyId: $accessKeyId,
            secretAccessKey: $secretAccessKey,
            policyNames: $policyNames,
            allowedPrefixes: $allowedPrefixes,
        );
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
