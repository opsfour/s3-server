<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use PHPUnit\Framework\TestCase;

final class CredentialHealthProbeTest extends TestCase
{
    public function test_database_probe_performs_a_read_only_table_query(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $provider = new DatabaseCredentialProvider($pdo);
        $provider->initialize();

        $provider->assertCredentialStoreAvailable();
        $count = $pdo->query('SELECT COUNT(*) FROM s3_credentials');
        if ($count === false) {
            self::fail('Could not verify that the readiness probe left the credential table unchanged.');
        }
        self::assertSame(0, (int) $count->fetchColumn());
        $count->closeCursor();

        $pdo->exec('ALTER TABLE s3_credentials DROP COLUMN display_name');
        $this->expectException(\PDOException::class);
        $provider->assertCredentialStoreAvailable();
    }

    public function test_chain_skips_local_providers_and_probes_every_external_provider(): void
    {
        $firstPdo = new \PDO('sqlite::memory:');
        $first = new DatabaseCredentialProvider($firstPdo);
        $first->initialize();
        $secondPdo = new \PDO('sqlite::memory:');
        $second = new DatabaseCredentialProvider($secondPdo);
        $second->initialize();
        $chain = new ChainCredentialProvider(new InMemoryCredentialProvider(), $first, $second);

        $chain->assertCredentialStoreAvailable();

        $secondPdo->exec('DROP TABLE s3_credentials');
        $this->expectException(\PDOException::class);
        $chain->assertCredentialStoreAvailable();
    }

    public function test_chain_without_external_dependencies_is_ready(): void
    {
        (new ChainCredentialProvider(new InMemoryCredentialProvider()))
            ->assertCredentialStoreAvailable();

        self::addToAssertionCount(1);
    }
}
