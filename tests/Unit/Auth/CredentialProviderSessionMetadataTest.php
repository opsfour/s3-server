<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\FilesystemDriver;
use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function test_config_file_provider_keeps_secret_file_private_with_permissive_umask(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-private-' . bin2hex(random_bytes(8)) . '.json';
        $oldUmask = umask(0);

        try {
            $provider = new ConfigFileCredentialProvider($path);
            $provider->putCredential($this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'))));

            clearstatcache(true, $path);
            self::assertSame(0o600, fileperms($path) & 0o777);
            self::assertSame([], glob($path . '.tmp.*') ?: []);
            self::assertSame('secret', $provider->getCredential('AKIASESSION12345678')?->secretAccessKey);
        } finally {
            umask($oldUmask);
            @unlink($path);
        }
    }

    #[RunInSeparateProcess]
    public function test_config_file_provider_keeps_temporary_secret_private_during_write(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-write-window-' . bin2hex(random_bytes(8)) . '.json';
        $oldUmask = umask(0);
        $base = new BlockingFilesystemDriver();
        $driver = $this->createMock(FilesystemDriver::class);
        $temporaryDirectoryMode = null;
        $secretPresentDuringWrite = false;
        $driver->method('getStatus')->willReturnCallback($base->getStatus(...));
        $driver->method('createDirectory')->willReturnCallback($base->createDirectory(...));
        $driver->method('changePermissions')->willReturnCallback($base->changePermissions(...));
        $driver->method('move')->willReturnCallback($base->move(...));
        $driver->method('deleteDirectory')->willReturnCallback($base->deleteDirectory(...));
        $driver->method('write')->willReturnCallback(
            static function (string $temporaryPath, string $contents) use ($base, &$temporaryDirectoryMode, &$secretPresentDuringWrite): void {
                $base->write($temporaryPath, $contents);
                clearstatcache(true, dirname($temporaryPath));
                $temporaryDirectoryMode = fileperms(dirname($temporaryPath)) & 0o777;
                $secretPresentDuringWrite = str_contains((string) file_get_contents($temporaryPath), 'session-token-123');
            },
        );

        try {
            \Amp\File\filesystem($driver);
            $provider = new ConfigFileCredentialProvider($path);
            $provider->putCredential($this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'))));

            self::assertSame(0o700, $temporaryDirectoryMode);
            self::assertTrue($secretPresentDuringWrite);
            self::assertSame([], glob($path . '.tmp.*') ?: []);
        } finally {
            umask($oldUmask);
            @unlink($path);
        }
    }

    public function test_config_file_provider_rejects_nonsticky_public_credential_directory(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-public-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        chmod($directory, 0o777);
        $path = $directory . '/credentials.json';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials directory must have trusted, private or sticky ancestors.');
            new ConfigFileCredentialProvider($path);
        } finally {
            self::assertFileDoesNotExist($path);
            self::assertSame([], glob($path . '.tmp.*') ?: []);
            @rmdir($directory);
        }
    }

    public function test_config_file_provider_rejects_private_directory_under_replaceable_ancestor(): void
    {
        $ancestor = sys_get_temp_dir() . '/s3-credential-ancestor-' . bin2hex(random_bytes(8));
        $private = $ancestor . '/private';
        mkdir($ancestor, 0o700);
        mkdir($private, 0o700);
        chmod($ancestor, 0o777);
        $path = $private . '/credentials.json';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials directory must have trusted, private or sticky ancestors.');
            new ConfigFileCredentialProvider($path);
        } finally {
            self::assertFileDoesNotExist($path);
            self::assertSame([], glob($path . '.tmp.*') ?: []);
            @rmdir($private);
            @rmdir($ancestor);
        }
    }

    public function test_config_file_provider_rejects_existing_file_below_unsafe_ancestor(): void
    {
        $ancestor = sys_get_temp_dir() . '/s3-credential-existing-ancestor-' . bin2hex(random_bytes(8));
        $private = $ancestor . '/private';
        self::assertTrue(mkdir($ancestor, 0o700));
        self::assertTrue(mkdir($private, 0o700));
        $path = $private . '/credentials.json';
        self::writePrivateCredentialFile($path, '[]');
        self::assertTrue(chmod($ancestor, 0o777));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials directory must have trusted, private or sticky ancestors.');
            new ConfigFileCredentialProvider($path);
        } finally {
            @unlink($path);
            @rmdir($private);
            @rmdir($ancestor);
        }
    }

    public function test_config_file_provider_rejects_existing_file_hidden_by_inaccessible_directory(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-inaccessible-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $path = $directory . '/credentials.json';
        self::writePrivateCredentialFile($path, '[]');
        self::assertTrue(chmod($directory, 0o000));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials directory is unavailable.');
            new ConfigFileCredentialProvider($path);
        } finally {
            @chmod($directory, 0o700);
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_config_file_provider_preserves_trusted_directory_symlink(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-real-' . bin2hex(random_bytes(8));
        $alias = $directory . '-alias';
        mkdir($directory, 0o700);
        if (! @symlink($directory, $alias)) {
            @rmdir($directory);
            self::markTestSkipped('Directory symlinks are unavailable.');
        }

        try {
            $path = $alias . '/credentials.json';
            $provider = new ConfigFileCredentialProvider($path);
            $provider->putCredential($this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC'))));
            self::assertFileExists($directory . '/credentials.json');
            self::assertSame('secret', (new ConfigFileCredentialProvider($path))->getCredential('AKIASESSION12345678')?->secretAccessKey);
            self::assertSame([], glob($directory . '/credentials.json.tmp.*') ?: []);
        } finally {
            @unlink($directory . '/credentials.json');
            @unlink($alias);
            @rmdir($directory);
        }
    }

    public function test_config_file_provider_rejects_simulated_foreign_owned_directory_symlink(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-link-target-' . bin2hex(random_bytes(8));
        $alias = $directory . '-alias';
        self::assertTrue(mkdir($directory, 0o700));
        if (! @symlink($directory, $alias)) {
            @rmdir($directory);
            self::markTestSkipped('Directory symlinks are unavailable.');
        }

        try {
            $status = lstat($alias);
            self::assertIsArray($status);
            self::assertSame(0o120000, $status['mode'] & 0o170000);
            $effectiveUid = posix_geteuid();
            $status['uid'] = $effectiveUid === 1 ? 2 : 1;
            $check = new \ReflectionMethod(ConfigFileCredentialProvider::class, 'assertTrustedCredentialDirectoryComponent');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials directory symlinks must be owned by root or the effective user.');
            $check->invoke(null, $status, $effectiveUid);
        } finally {
            @unlink($alias);
            @rmdir($directory);
        }
    }

    public function test_config_file_provider_rejects_unsafe_existing_files_before_loading_credentials(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-load-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o700));
        $validJson = json_encode([[
            'accessKeyId' => 'AKIAUNSAFE123456789',
            'secretAccessKey' => 'must-not-load',
            'ownerId' => 'unsafe-owner',
        ]], JSON_THROW_ON_ERROR);
        $paths = [];

        try {
            $target = $directory . '/target.json';
            self::writePrivateCredentialFile($target, $validJson);
            $link = $directory . '/link.json';
            if (@symlink($target, $link)) {
                $paths['symbolic link'] = $link;
            }

            $nonRegular = $directory . '/directory.json';
            self::assertTrue(mkdir($nonRegular, 0o700));
            $paths['non-regular file'] = $nonRegular;

            $public = $directory . '/public.json';
            self::writePrivateCredentialFile($public, $validJson);
            self::assertTrue(chmod($public, 0o640));
            $paths['group-readable file'] = $public;

            $empty = $directory . '/empty.json';
            self::writePrivateCredentialFile($empty, '');
            $paths['empty file'] = $empty;

            $unreadable = $directory . '/unreadable.json';
            self::writePrivateCredentialFile($unreadable, $validJson);
            self::assertTrue(chmod($unreadable, 0o200));
            $paths['owner-unreadable file'] = $unreadable;

            $oversized = $directory . '/oversized.json';
            self::writePrivateCredentialFile($oversized, str_repeat('x', 16_777_217));
            $paths['oversized file'] = $oversized;

            foreach ($paths as $label => $path) {
                try {
                    new ConfigFileCredentialProvider($path);
                    self::fail("The {$label} must fail closed.");
                } catch (\RuntimeException $error) {
                    self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
                }
            }
        } finally {
            foreach ($paths as $path) {
                if (is_dir($path) && ! is_link($path)) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
            @unlink($target);
            @rmdir($directory);
        }
    }

    public function test_config_file_security_checks_reject_wrong_owner_and_inode_mismatch(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-stat-' . bin2hex(random_bytes(8)) . '.json';
        self::writePrivateCredentialFile($path, '[]');

        try {
            $status = lstat($path);
            self::assertIsArray($status);
            $effectiveUid = posix_geteuid();
            $wrongOwner = $status;
            $wrongOwner['uid'] = $effectiveUid === 1 ? 2 : 1;
            $metadataCheck = new \ReflectionMethod(ConfigFileCredentialProvider::class, 'assertSecureCredentialFile');
            try {
                $metadataCheck->invoke(null, $wrongOwner, $effectiveUid);
                self::fail('A credential file owned by another non-root user must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('owned by root or the effective user', $error->getMessage());
            }

            $differentFile = $status;
            $differentFile['ino']++;
            $identityCheck = new \ReflectionMethod(ConfigFileCredentialProvider::class, 'assertSameCredentialFile');
            try {
                $identityCheck->invoke(null, $status, $differentFile);
                self::fail('A path-to-handle inode mismatch must be rejected.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('changed while it was being opened or read', $error->getMessage());
            }
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

    public function test_config_file_provider_keeps_memory_and_disk_unchanged_when_write_fails(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-write-' . bin2hex(random_bytes(4));
        $movedDirectory = $directory . '-moved';
        $path = $directory . '/credentials.json';
        mkdir($directory, 0o700);

        try {
            $provider = new ConfigFileCredentialProvider($path);
            $original = $this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));
            $provider->putCredential($original);

            rename($directory, $movedDirectory);
            file_put_contents($directory, 'blocks directory recreation');

            $newCredential = new Credential(
                accessKeyId: 'AKIASECOND123456789',
                secretAccessKey: 'secret-2',
                ownerId: 'tenant:second',
            );
            foreach ([
                static fn() => $provider->putCredential($newCredential),
                static fn() => $provider->deleteCredential($original->accessKeyId),
            ] as $write) {
                try {
                    $write();
                    self::fail('The blocked credential file write must fail.');
                } catch (\Throwable $error) {
                    self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
                }
            }

            self::assertSame($original, $provider->getCredential($original->accessKeyId));
            self::assertNull($provider->getCredential($newCredential->accessKeyId));
            self::assertCount(1, (new ConfigFileCredentialProvider($movedDirectory . '/credentials.json'))->listCredentials());
        } finally {
            @unlink($directory);
            @unlink($movedDirectory . '/credentials.json');
            @rmdir($movedDirectory);
            @rmdir($directory);
        }
    }

    public function test_config_file_provider_rejects_non_list_json(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-invalid-' . bin2hex(random_bytes(4)) . '.json';
        self::writePrivateCredentialFile($path, '{"accessKeyId":"unsafe"}');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Credentials file must contain a JSON array.');

            new ConfigFileCredentialProvider($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_config_file_provider_rejects_duplicate_access_key_ids(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-duplicate-' . bin2hex(random_bytes(4)) . '.json';
        self::writePrivateCredentialFile($path, json_encode([
            ['accessKeyId' => 'duplicate', 'secretAccessKey' => 'first', 'ownerId' => 'owner-a'],
            ['accessKeyId' => 'duplicate', 'secretAccessKey' => 'second', 'ownerId' => 'owner-b'],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('duplicate accessKeyId at entry 1');

            new ConfigFileCredentialProvider($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_factory_builds_documented_chain_provider_configuration(): void
    {
        $provider = CredentialProviderFactory::create('chain', ['providers' => [
            [
                'driver' => 'memory',
                'access_key' => 'admin',
                'secret_key' => 'admin-secret',
                'owner_id' => 'admin-owner',
                'display_name' => 'Admin',
            ],
            [
                'driver' => 'memory',
                'access_key' => 'user',
                'secret_key' => 'user-secret',
                'owner_id' => 'user-owner',
                'display_name' => 'User',
            ],
        ]]);

        self::assertSame('admin-owner', $provider->getCredential('admin')?->ownerId);
        self::assertSame('user-owner', $provider->getCredential('user')?->ownerId);
        self::assertNull($provider->getCredential('missing'));
    }

    public function test_config_file_provider_rejects_malformed_security_fields_instead_of_dropping_them(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-invalid-fields-' . bin2hex(random_bytes(4)) . '.json';
        $base = [
            'accessKeyId' => 'AKIASESSION12345678',
            'secretAccessKey' => 'secret',
            'ownerId' => 'tenant:acme',
        ];

        try {
            foreach ([
                ['isActive' => 'false'],
                ['displayName' => 123],
                ['sessionToken' => 123],
                ['expiresAt' => 123],
                ['policyNames' => ['readonly', 123]],
                ['allowedPrefixes' => 'incoming/'],
            ] as $corruptField) {
                self::writePrivateCredentialFile($path, json_encode([$base + $corruptField], JSON_THROW_ON_ERROR));
                try {
                    new ConfigFileCredentialProvider($path);
                    self::fail('A malformed credential security field must reject the credential file.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString((string) array_key_first($corruptField), $error->getMessage());
                }
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_config_file_provider_rejects_unknown_fields_instead_of_ignoring_a_typo(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-unknown-field-' . bin2hex(random_bytes(4)) . '.json';
        self::writePrivateCredentialFile($path, json_encode([[
            'accessKeyId' => 'AKIASESSION12345678',
            'secretAccessKey' => 'secret',
            'ownerId' => 'tenant:acme',
            'is_active' => false,
        ]], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('contains an unknown field');

            new ConfigFileCredentialProvider($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_config_file_provider_rejects_xml_invalid_identity_fields(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-invalid-identity-' . bin2hex(random_bytes(4)) . '.json';
        $base = [
            'accessKeyId' => 'AKIASESSION12345678',
            'secretAccessKey' => 'secret',
            'ownerId' => 'tenant:acme',
            'displayName' => 'Alice',
        ];

        try {
            foreach ([
                ['ownerId' => "tenant:\u{FFFE}"],
                ['displayName' => "Alice\u{FFFF}"],
            ] as $invalidIdentity) {
                self::writePrivateCredentialFile($path, json_encode([$invalidIdentity + $base], JSON_THROW_ON_ERROR));
                try {
                    new ConfigFileCredentialProvider($path);
                    self::fail('An XML-invalid credential identity must reject the credential file.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('XML 1.0', $error->getMessage());
                }
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_memory_factory_accepts_string_zero_values_and_rejects_non_strings(): void
    {
        $provider = CredentialProviderFactory::create('memory', [
            'access_key' => '0',
            'secret_key' => '0',
            'owner_id' => '0',
            'display_name' => '0',
        ]);
        self::assertSame('0', $provider->getCredential('0')?->ownerId);

        $this->expectException(\InvalidArgumentException::class);
        CredentialProviderFactory::create('memory', [
            'access_key' => ['invalid'],
            'secret_key' => 'secret',
            'owner_id' => 'owner',
            'display_name' => 'Owner',
        ]);
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

    public function test_database_provider_rejects_malformed_policy_scopes_instead_of_dropping_them(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();
        $credential = $this->credential(new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')));
        $provider->putCredential($credential);

        foreach (['policy_names', 'allowed_prefixes'] as $column) {
            foreach (['not-json', '["allowed", 123]', '{}', ''] as $corruptValue) {
                $stmt = $pdo->prepare("UPDATE s3_credentials SET {$column} = ? WHERE access_key_id = ?");
                $stmt->execute([$corruptValue, $credential->accessKeyId]);
                try {
                    $provider->getCredential($credential->accessKeyId);
                    self::fail('A malformed credential policy scope must be rejected.');
                } catch (\UnexpectedValueException $error) {
                    self::assertStringContainsString($column, $error->getMessage());
                }
            }
            $stmt = $pdo->prepare("UPDATE s3_credentials SET {$column} = NULL WHERE access_key_id = ?");
            $stmt->execute([$credential->accessKeyId]);
            self::assertNotNull($provider->getCredential($credential->accessKeyId));
        }
    }

    public function test_database_provider_rejects_xml_invalid_identity_fields(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();
        $insert = $pdo->prepare(
            'INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id, display_name) VALUES (?, ?, ?, ?)',
        );

        foreach ([
            ['AKIAINVALIDOWNER001', "owner-\xC3\x28", 'Alice'],
            ['AKIAINVALIDDISPLAY01', 'owner', "Alice\u{FFFE}"],
        ] as [$accessKeyId, $ownerId, $displayName]) {
            $insert->execute([$accessKeyId, 'secret', $ownerId, $displayName]);
            try {
                $provider->getCredential($accessKeyId);
                self::fail('An XML-invalid database credential identity must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('XML 1.0', $error->getMessage());
            }
        }
    }

    public function test_sqlite_identity_cas_ignores_case_insensitive_column_collations(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(<<<'SQL'
            CREATE TABLE s3_credentials (
                access_key_id TEXT COLLATE BINARY PRIMARY KEY,
                secret_access_key TEXT COLLATE NOCASE NOT NULL,
                owner_id TEXT COLLATE NOCASE NOT NULL,
                display_name TEXT NOT NULL DEFAULT '',
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                session_token TEXT COLLATE NOCASE NULL,
                expires_at TIMESTAMP NULL,
                policy_names TEXT NULL,
                allowed_prefixes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();
        $current = new Credential('AKIACASECAS', 'Secret', 'Owner', sessionToken: 'Token');
        $provider->putCredential($current);

        foreach ([
            new Credential('AKIACASECAS', 'secret', 'Owner', sessionToken: 'Token'),
            new Credential('AKIACASECAS', 'Secret', 'owner', sessionToken: 'Token'),
            new Credential('AKIACASECAS', 'Secret', 'Owner', sessionToken: 'token'),
        ] as $stale) {
            self::assertFalse($provider->setActiveIfCurrent($stale, false));
            self::assertFalse($provider->deleteCredentialIfCurrent($stale));
        }

        self::assertTrue($provider->getCredential($current->accessKeyId)?->isActive);
        self::assertTrue($provider->setActiveIfCurrent($current, false));
        self::assertFalse($provider->deleteCredentialIfCurrent($current));
        $inactive = $provider->getCredential($current->accessKeyId);
        self::assertFalse($inactive->isActive);
        self::assertTrue($provider->deleteCredentialIfCurrent($inactive));
        self::assertNull($provider->getCredential($current->accessKeyId));
    }

    public function test_database_provider_preserves_expiration_in_utc_across_php_timezones(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-utc-' . bin2hex(random_bytes(4)) . '.sqlite';
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            $provider = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 0.0);
            $provider->initialize();
            $expiresAt = new \DateTimeImmutable('2030-01-02 03:04:05', new \DateTimeZone('Pacific/Auckland'));
            $provider->putCredential($this->credential($expiresAt));

            $reloaded = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 0.0);
            $reloaded->initialize();
            $stored = $reloaded->getCredential('AKIASESSION12345678');

            self::assertNotNull($stored);
            self::assertNotNull($stored->expiresAt);
            self::assertSame($expiresAt->getTimestamp(), $stored->expiresAt->getTimestamp());
            self::assertSame('UTC', $stored->expiresAt->getTimezone()->getName());
        } finally {
            date_default_timezone_set($originalTimezone);
            @unlink($path);
        }
    }

    public function test_database_provider_accepts_a_column_added_by_another_initializer(): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            private bool $raced = false;

            public function exec(string $statement): int|false
            {
                if (! $this->raced && str_contains($statement, 'ADD COLUMN session_token')) {
                    $this->raced = true;
                    parent::exec($statement);

                    throw new \PDOException('duplicate column name: session_token');
                }

                return parent::exec($statement);
            }
        };
        $pdo->exec("CREATE TABLE s3_credentials (access_key_id VARCHAR(255) PRIMARY KEY, secret_access_key TEXT NOT NULL, owner_id VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL DEFAULT '', is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");

        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();

        $columns = $pdo->query('PRAGMA table_info(s3_credentials)');
        self::assertInstanceOf(\PDOStatement::class, $columns);
        $names = array_column($columns->fetchAll(\PDO::FETCH_ASSOC), 'name');
        self::assertContains('session_token', $names);
        self::assertContains('expires_at', $names);
        self::assertContains('policy_names', $names);
        self::assertContains('allowed_prefixes', $names);
    }

    public function test_database_provider_does_not_hide_a_failed_column_upgrade(): void
    {
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public function exec(string $statement): int|false
            {
                if (str_contains($statement, 'ADD COLUMN session_token')) {
                    throw new \PDOException('simulated DDL failure');
                }

                return parent::exec($statement);
            }
        };
        $pdo->exec('CREATE TABLE s3_credentials (access_key_id VARCHAR(255) PRIMARY KEY)');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('simulated DDL failure');

        (new DatabaseCredentialProvider($pdo, 0.0))->initialize();
    }

    public function test_mysql_database_provider_resets_session_timezone_before_temporary_credentials(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_CREDENTIAL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_CREDENTIAL_DSN for the disposable MySQL timezone probe.');
        }
        if (preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::fail('The MySQL timezone probe requires a disposable s3audit_ database.');
        }

        $accessKeyId = 'AKIAUTC' . strtoupper(bin2hex(random_bytes(8)));
        $password = getenv('S3_TEST_MYSQL_CREDENTIAL_PASSWORD');
        $password = is_string($password) ? $password : 's3audit';
        $expiresAt = new \DateTimeImmutable('2030-01-02 03:04:05', new \DateTimeZone('Pacific/Auckland'));
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');

        try {
            $writerPdo = new \PDO($dsn, 'root', $password);
            $writerPdo->exec("SET time_zone = '-10:00'");
            $writer = new DatabaseCredentialProvider($writerPdo, 0.0);
            $writer->initialize();
            $timezoneQuery = $writerPdo->query('SELECT @@session.time_zone');
            self::assertInstanceOf(\PDOStatement::class, $timezoneQuery);
            self::assertSame('+00:00', $timezoneQuery->fetchColumn());
            $writer->putCredential(new Credential(
                accessKeyId: $accessKeyId,
                secretAccessKey: 'test-secret',
                ownerId: 'timezone-test',
                expiresAt: $expiresAt,
            ));

            $readerPdo = new \PDO($dsn, 'root', $password);
            $readerPdo->exec("SET time_zone = '+12:00'");
            $reader = new DatabaseCredentialProvider($readerPdo, 0.0);
            $reader->initialize();
            $timezoneQuery = $readerPdo->query('SELECT @@session.time_zone');
            self::assertInstanceOf(\PDOStatement::class, $timezoneQuery);
            self::assertSame('+00:00', $timezoneQuery->fetchColumn());
            $stored = $reader->getCredential($accessKeyId);

            self::assertNotNull($stored);
            self::assertNotNull($stored->expiresAt);
            self::assertSame($expiresAt->getTimestamp(), $stored->expiresAt->getTimestamp());
            $reader->deleteCredential($accessKeyId);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_mysql_atomic_activation_does_not_restore_a_revoked_credential(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_CREDENTIAL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_CREDENTIAL_DSN for the disposable MySQL activation race probe.');
        }
        if (preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::fail('The MySQL activation race probe requires a disposable s3audit_ database.');
        }
        $password = getenv('S3_TEST_MYSQL_CREDENTIAL_PASSWORD');
        $this->assertAtomicActivationAcrossNodes($dsn, 'root', is_string($password) ? $password : 's3audit');
    }

    public function test_postgres_atomic_activation_does_not_restore_a_revoked_credential(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_CREDENTIAL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_CREDENTIAL_DSN for the disposable PostgreSQL activation race probe.');
        }
        if (preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::fail('The PostgreSQL activation race probe requires a disposable s3audit_ database.');
        }
        $username = getenv('S3_TEST_POSTGRES_CREDENTIAL_USERNAME');
        $password = getenv('S3_TEST_POSTGRES_CREDENTIAL_PASSWORD');
        $this->assertAtomicActivationAcrossNodes($dsn, is_string($username) ? $username : 'postgres', is_string($password) ? $password : 's3audit');
    }

    private function assertAtomicActivationAcrossNodes(string $dsn, string $username, string $password): void
    {
        $reader = new DatabaseCredentialProvider(new \PDO($dsn, $username, $password), 60.0);
        $writer = new DatabaseCredentialProvider(new \PDO($dsn, $username, $password), 0.0);
        $reader->initialize();
        $writer->initialize();

        foreach ([null, 'session-race-token'] as $sessionToken) {
            $keyId = 'AKIARACE' . strtoupper(bin2hex(random_bytes(7)));
            try {
                $manager = new CredentialManager($reader);
                $created = $manager->createCredential('race-owner', accessKeyId: $keyId, secretAccessKey: 'race-secret', sessionToken: $sessionToken);
                self::assertTrue($manager->setActive($keyId, true)->isActive);
                // PostgreSQL counts an unchanged UPDATE as one row and clears
                // the cache; explicitly prime the stale reader before revocation.
                self::assertTrue($manager->getCredential($keyId)?->isActive);
                self::assertFalse((new CredentialManager($writer))->setActive($keyId, false)->isActive);
                try {
                    $manager->setActive($keyId, true);
                    self::fail('Activation undid a concurrent deactivation.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('changed', $error->getMessage());
                }
                self::assertFalse($reader->setActiveIfCurrent($created, false));
                self::assertFalse($writer->getCredential($keyId)?->isActive);
                self::assertFalse($manager->setActive($keyId, false)->isActive);
                self::assertNotNull($manager->getCredential($keyId));
                $writer->deleteCredential($keyId);

                try {
                    $manager->setActive($keyId, true);
                    self::fail('Activation restored a revoked credential.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('revoked', $error->getMessage());
                }
                self::assertNull($writer->getCredential($keyId));

                $manager->createCredential('race-owner', accessKeyId: $keyId, secretAccessKey: 'race-secret', sessionToken: $sessionToken);
                // Keep this second connection's original lookup cached while
                // the first reader exercises its separate stale-status path.
                $staleDeleter = new DatabaseCredentialProvider(new \PDO($dsn, $username, $password), 60.0);
                $staleManager = new CredentialManager($staleDeleter);
                self::assertSame('race-secret', $staleManager->getCredential($keyId)?->secretAccessKey);
                $writer->putCredential(new Credential(
                    accessKeyId: $keyId,
                    secretAccessKey: 'replacement-secret',
                    ownerId: 'different-owner',
                    isActive: false,
                    sessionToken: $sessionToken,
                ));
                try {
                    $manager->setActive($keyId, true);
                    self::fail('Activation changed a replacement credential.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('changed', $error->getMessage());
                }
                self::assertFalse($writer->getCredential($keyId)?->isActive);
                self::assertFalse($staleManager->deleteCredential($keyId));
                try {
                    $manager->createCredential('third-owner', accessKeyId: $keyId, secretAccessKey: 'third-secret');
                    self::fail('A duplicate create replaced the stored credential.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('already exists', $error->getMessage());
                }
                $replacement = $writer->getCredential($keyId);
                self::assertSame('replacement-secret', $replacement->secretAccessKey);
            } finally {
                $writer->deleteCredential($keyId);
            }
        }
    }

    public function test_mysql_database_provider_keeps_case_distinct_access_keys_separate(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_CREDENTIAL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_CREDENTIAL_DSN for the disposable MySQL access-key probe.');
        }
        if (preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::fail('The MySQL access-key probe requires a disposable s3audit_ database.');
        }

        $password = getenv('S3_TEST_MYSQL_CREDENTIAL_PASSWORD');
        $provider = new DatabaseCredentialProvider(new \PDO($dsn, 'root', is_string($password) ? $password : 's3audit'), 0.0);
        $provider->initialize();
        $suffix = strtoupper(bin2hex(random_bytes(7)));
        $upperKey = 'AKIACASE' . $suffix;
        $lowerKey = strtolower($upperKey);

        try {
            $provider->putCredential(new Credential($upperKey, 'upper-secret', 'upper-owner'));
            self::assertNull($provider->getCredential($lowerKey));

            $provider->putCredential(new Credential($lowerKey, 'lower-secret', 'lower-owner'));
            $upperCredential = $provider->getCredential($upperKey);
            $lowerCredential = $provider->getCredential($lowerKey);
            self::assertNotNull($upperCredential);
            self::assertNotNull($lowerCredential);
            self::assertSame('upper-owner', $upperCredential->ownerId);
            self::assertSame('upper-secret', $upperCredential->secretAccessKey);
            self::assertSame('lower-owner', $lowerCredential->ownerId);
            self::assertSame('lower-secret', $lowerCredential->secretAccessKey);

            $provider->deleteCredential($lowerKey);
            self::assertNotNull($provider->getCredential($upperKey));
        } finally {
            $provider->deleteCredential($upperKey);
            $provider->deleteCredential($lowerKey);
        }
    }

    public function test_sqlite_database_provider_keeps_case_distinct_access_keys_separate(): void
    {
        $provider = new DatabaseCredentialProvider(new \PDO('sqlite::memory:'), 0.0);
        $provider->initialize();
        $upperKey = 'AKIACASE123456789012';
        $lowerKey = strtolower($upperKey);

        $provider->putCredential(new Credential($upperKey, 'upper-secret', 'upper-owner'));
        self::assertNull($provider->getCredential($lowerKey));
        $provider->putCredential(new Credential($lowerKey, 'lower-secret', 'lower-owner'));
        self::assertSame('upper-owner', $provider->getCredential($upperKey)?->ownerId);
        self::assertSame('lower-owner', $provider->getCredential($lowerKey)?->ownerId);
        $provider->deleteCredential($lowerKey);
        self::assertSame('upper-owner', $provider->getCredential($upperKey)?->ownerId);
    }

    public function test_mysql_database_provider_upgrades_legacy_access_key_column_without_losing_credentials(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_CREDENTIAL_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_CREDENTIAL_DSN for the disposable MySQL migration probe.');
        }
        if (preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::fail('The MySQL access-key migration probe requires a disposable s3audit_ database.');
        }

        $password = getenv('S3_TEST_MYSQL_CREDENTIAL_PASSWORD');
        $pdo = new \PDO($dsn, 'root', is_string($password) ? $password : 's3audit');
        $provider = new DatabaseCredentialProvider($pdo, 0.0);
        $provider->initialize();
        $upperKey = 'AKIALEGACY' . strtoupper(bin2hex(random_bytes(7)));
        $lowerKey = strtolower($upperKey);

        try {
            $pdo->exec('ALTER TABLE s3_credentials MODIFY access_key_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
            $provider->putCredential(new Credential($upperKey, 'legacy-secret', 'legacy-owner'));
            self::assertSame('legacy-owner', $provider->getCredential($lowerKey)?->ownerId);

            $upgraded = new DatabaseCredentialProvider($pdo, 0.0);
            $upgraded->initialize();
            $upgraded->initialize();
            self::assertNull($upgraded->getCredential($lowerKey));
            $credential = $upgraded->getCredential($upperKey);
            self::assertNotNull($credential);
            self::assertSame('legacy-owner', $credential->ownerId);
            self::assertSame('legacy-secret', $credential->secretAccessKey);

            $statement = $pdo->query("SHOW COLUMNS FROM s3_credentials LIKE 'access_key_id'");
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $column = $statement->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('varbinary(255)', $column['Type'] ?? null);
        } finally {
            $provider->initialize();
            $provider->deleteCredential($upperKey);
        }
    }

    public function test_database_provider_refreshes_cross_node_revocation_after_cache_ttl(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-revocation-' . bin2hex(random_bytes(4)) . '.sqlite';
        $writer = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 0.0);
        // Leave enough time for the intervening SQLite write even on a busy CI
        // host; a 10 ms TTL made the pre-expiry assertion timing-dependent.
        $reader = new DatabaseCredentialProvider(new \PDO('sqlite:' . $path), 1.0);
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
            usleep(1_100_000);
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
            'INSERT INTO s3_credentials '
            . '(access_key_id, secret_access_key, owner_id, display_name, is_active) '
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

    public function test_database_provider_rejects_unrepresentable_cache_ttls(): void
    {
        foreach ([INF, NAN, (float) PHP_INT_MAX] as $ttl) {
            try {
                new DatabaseCredentialProvider(new \PDO('sqlite::memory:'), $ttl);
                self::fail('Unrepresentable cache TTL was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Credential cache TTL', $error->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        CredentialProviderFactory::create('database', [
            'dsn' => 'sqlite::memory:',
            'cache_ttl' => '1e309',
        ]);
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

    private static function writePrivateCredentialFile(string $path, string $contents): void
    {
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0o600));
    }
}
