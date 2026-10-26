<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\External\ExternalCredentialIssuer;
use OpsFour\S3Server\Auth\External\ExternalIdentity;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicExpiredCredentialPruner;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Parallel\ParallelDatabaseCredentialProvider;
use PHPUnit\Framework\TestCase;

final class ExpiredCredentialPruningTest extends TestCase
{
    public function test_pruning_rejects_zero_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be >= 1');

        (new InMemoryCredentialProvider())->pruneExpiredCredentials(new \DateTimeImmutable('now'), 0);
    }

    public function test_in_memory_pruning_is_bounded_and_preserves_live_and_permanent_credentials(): void
    {
        $now = new \DateTimeImmutable('2026-10-26T12:00:00Z');
        $provider = new InMemoryCredentialProvider(
            $this->credential('AKIAEXPIRED000000001', $now->modify('-2 hours')),
            $this->credential('AKIAEXPIRED000000002', $now),
            $this->credential('AKIAEXPIRED000000003', $now->modify('-1 second')),
            $this->credential('AKIALIVE000000000001', $now->modify('+1 second')),
            $this->credential('AKIAPERMANENT0000001', null),
        );

        self::assertSame(2, $provider->pruneExpiredCredentials($now, 2));
        self::assertCount(3, $provider->listCredentials());
        self::assertSame(1, $provider->pruneExpiredCredentials($now, 2));
        self::assertNotNull($provider->getCredential('AKIALIVE000000000001'));
        self::assertNotNull($provider->getCredential('AKIAPERMANENT0000001'));
    }

    public function test_config_file_pruning_persists_one_bounded_atomic_rewrite(): void
    {
        $path = sys_get_temp_dir() . '/s3-expired-credential-prune-' . bin2hex(random_bytes(8)) . '.json';
        $now = new \DateTimeImmutable('2026-10-26T12:00:00Z');
        try {
            $provider = new ConfigFileCredentialProvider($path);
            $provider->putCredential($this->credential('AKIAEXPIRED000000001', $now->modify('-1 second')));
            $provider->putCredential($this->credential('AKIAEXPIRED000000002', $now));
            $provider->putCredential($this->credential('AKIALIVE000000000001', $now->modify('+1 second')));
            $provider->putCredential($this->credential('AKIAPERMANENT0000001', null));

            self::assertSame(1, $provider->pruneExpiredCredentials($now, 1));

            $reloaded = new ConfigFileCredentialProvider($path);
            self::assertCount(3, $reloaded->listCredentials());
            self::assertNotNull($reloaded->getCredential('AKIALIVE000000000001'));
            self::assertNotNull($reloaded->getCredential('AKIAPERMANENT0000001'));
            self::assertSame([], glob($path . '.tmp.*') ?: []);
        } finally {
            @unlink($path);
        }
    }

    public function test_database_pruning_is_bounded_invalidates_cache_and_has_ordering_index(): void
    {
        $path = sys_get_temp_dir() . '/s3-expired-credential-prune-' . bin2hex(random_bytes(8)) . '.sqlite';
        $now = new \DateTimeImmutable('2026-10-26T12:00:00Z');
        try {
            $provider = DatabaseCredentialProvider::fromDsn('sqlite:' . $path, cacheTtlSeconds: 60, bootstrap: true);
            $provider->putCredential($this->credential('AKIAEXPIRED000000001', $now->modify('-2 seconds')));
            $provider->putCredential($this->credential('AKIAEXPIRED000000002', $now->modify('-1 second')));
            $provider->putCredential($this->credential('AKIALIVE000000000001', $now->modify('+1 second')));
            $provider->putCredential($this->credential('AKIAPERMANENT0000001', null));
            self::assertNotNull($provider->getCredential('AKIAEXPIRED000000001'));

            self::assertSame(1, $provider->pruneExpiredCredentials($now, 1));
            self::assertNull($provider->getCredential('AKIAEXPIRED000000001'));
            self::assertNotNull($provider->getCredential('AKIAEXPIRED000000002'));
            self::assertNotNull($provider->getCredential('AKIALIVE000000000001'));
            self::assertNotNull($provider->getCredential('AKIAPERMANENT0000001'));

            $pdo = new \PDO('sqlite:' . $path);
            $statement = $pdo->query("PRAGMA index_info('idx_s3_credentials_expires_at')");
            self::assertNotFalse($statement);
            self::assertSame(
                ['expires_at', 'access_key_id'],
                array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'name'),
            );
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_database_schema_readiness_rejects_partial_expiration_index(): void
    {
        $path = sys_get_temp_dir() . '/s3-expired-credential-index-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            DatabaseCredentialProvider::fromDsn('sqlite:' . $path, bootstrap: true);
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec('DROP INDEX idx_s3_credentials_expires_at');
            $pdo->exec(
                'CREATE INDEX idx_s3_credentials_expires_at '
                . 'ON s3_credentials (expires_at, access_key_id) WHERE expires_at IS NOT NULL',
            );

            try {
                DatabaseCredentialProvider::fromDsn('sqlite:' . $path);
                self::fail('Serving accepted a partial credential expiration index.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credentials:migrate', $error->getMessage());
            }

            DatabaseCredentialProvider::fromDsn('sqlite:' . $path, bootstrap: true);
            self::assertInstanceOf(
                DatabaseCredentialProvider::class,
                DatabaseCredentialProvider::fromDsn('sqlite:' . $path),
            );
            $indexColumns = $pdo->query("PRAGMA index_info('idx_s3_credentials_expires_at')");
            self::assertNotFalse($indexColumns);
            self::assertSame(
                ['expires_at', 'access_key_id'],
                array_column($indexColumns->fetchAll(\PDO::FETCH_ASSOC), 'name'),
            );
            $indexList = $pdo->query('PRAGMA index_list(s3_credentials)');
            self::assertNotFalse($indexList);
            $indexes = $indexList->fetchAll(\PDO::FETCH_ASSOC);
            $expirationIndex = array_values(array_filter(
                $indexes,
                static fn(array $row): bool => ($row['name'] ?? null) === 'idx_s3_credentials_expires_at',
            ));
            self::assertSame(0, (int) ($expirationIndex[0]['partial'] ?? -1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_config_file_oversize_write_preserves_disk_and_memory_state(): void
    {
        $directory = sys_get_temp_dir() . '/s3-credential-size-' . bin2hex(random_bytes(8));
        $path = $directory . '/credentials.json';
        $prefixes = array_fill(0, 128, str_repeat('p', 1024));
        try {
            $provider = new ConfigFileCredentialProvider($path);
            $credentials = [];
            for ($index = 0; $index < 125; ++$index) {
                $credential = new Credential(
                    sprintf('AKIAFILE%012d', $index),
                    str_repeat('s', 4096),
                    'owner',
                    allowedPrefixes: $prefixes,
                );
                $credentials[$credential->accessKeyId] = $credential;
            }
            (new \ReflectionProperty($provider, 'credentials'))->setValue($provider, $credentials);
            $originalCount = count($provider->listCredentials());
            self::assertDirectoryDoesNotExist($directory);

            try {
                $provider->putCredential(new Credential(
                    'AKIAFILEOVERSIZE001',
                    str_repeat('s', 4096),
                    'owner',
                    allowedPrefixes: $prefixes,
                ));
                self::fail('Oversize credential file write succeeded.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('maximum size', $error->getMessage());
            }

            self::assertDirectoryDoesNotExist($directory);
            self::assertFileDoesNotExist($path);
            self::assertCount($originalCount, $provider->listCredentials());
            self::assertSame($credentials['AKIAFILE000000000000'], $provider->getCredential('AKIAFILE000000000000'));
            self::assertNull($provider->getCredential('AKIAFILEOVERSIZE001'));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function test_parallel_database_provider_prunes_through_worker(): void
    {
        $path = sys_get_temp_dir() . '/s3-expired-credential-worker-' . bin2hex(random_bytes(8)) . '.sqlite';
        $provider = null;
        try {
            DatabaseCredentialProvider::fromDsn('sqlite:' . $path, bootstrap: true);
            $provider = new ParallelDatabaseCredentialProvider('sqlite:' . $path, workerLimit: 1);
            $now = new \DateTimeImmutable('2026-10-26T12:00:00Z');
            $provider->putCredential($this->credential('AKIAEXPIRED000000001', $now));

            self::assertSame(1, $provider->pruneExpiredCredentials($now, 1));
            self::assertNull($provider->getCredential('AKIAEXPIRED000000001'));
        } finally {
            unset($provider);
            gc_collect_cycles();
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_single_provider_chain_delegates_pruning_to_primary(): void
    {
        $now = new \DateTimeImmutable('2026-10-26T12:00:00Z');
        $primary = new InMemoryCredentialProvider($this->credential('AKIAEXPIRED000000001', $now));
        $chain = new ChainCredentialProvider($primary);

        self::assertSame(1, $chain->pruneExpiredCredentials($now, 1));
        self::assertSame([], $primary->listCredentials());
    }

    public function test_external_issuer_prunes_at_most_one_fixed_batch_before_create(): void
    {
        $provider = new InMemoryCredentialProvider();
        $expired = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        for ($index = 0; $index < 101; ++$index) {
            $provider->putCredential($this->credential(sprintf('AKIAEXPIRED%09d', $index), $expired));
        }
        $provider->putCredential($this->credential('AKIAPERMANENT0000001', null));

        $issued = (new ExternalCredentialIssuer(new CredentialManager($provider)))
            ->issue(new ExternalIdentity('subject', '', 'owner'));

        self::assertCount(3, $provider->listCredentials());
        self::assertNotNull($provider->getCredential('AKIAPERMANENT0000001'));
        self::assertNotNull($provider->getCredential($issued->credential->accessKeyId));
    }

    public function test_invalid_identity_does_not_prune_and_prune_failure_prevents_creation(): void
    {
        $expired = $this->credential('AKIAEXPIRED000000001', new \DateTimeImmutable('-1 hour'));
        $provider = new InMemoryCredentialProvider($expired);
        $issuer = new ExternalCredentialIssuer(new CredentialManager($provider));
        try {
            $issuer->issue(new ExternalIdentity('subject', '', 'owner', claims: ['exp' => time() - 1]));
            self::fail('Expired identity was accepted.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($expired, $provider->getCredential($expired->accessKeyId));

        try {
            $issuer->issue(new ExternalIdentity('subject', '', str_repeat('x', 256)));
            self::fail('Invalid persisted identity metadata was accepted.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame($expired, $provider->getCredential($expired->accessKeyId));

        $failing = new class implements AtomicCredentialCreator, AtomicCredentialDeleter, AtomicExpiredCredentialPruner, CredentialProvider {
            public bool $createCalled = false;
            public function pruneExpiredCredentials(\DateTimeImmutable $now, int $limit): int
            {
                throw new \RuntimeException('cleanup failed');
            }
            public function createCredentialIfAbsent(Credential $credential): bool
            {
                $this->createCalled = true;

                return true;
            }
            public function deleteCredentialIfCurrent(Credential $expected): bool
            {
                return false;
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

        try {
            (new ExternalCredentialIssuer(new CredentialManager($failing)))
                ->issue(new ExternalIdentity('subject', '', 'owner'));
            self::fail('Issuance continued after cleanup failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('cleanup failed', $error->getMessage());
        }
        self::assertFalse($failing->createCalled);
    }

    private function credential(string $accessKeyId, ?\DateTimeImmutable $expiresAt): Credential
    {
        return new Credential($accessKeyId, 'secret', 'owner', expiresAt: $expiresAt);
    }
}
