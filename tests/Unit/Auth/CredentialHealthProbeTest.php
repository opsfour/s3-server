<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\DeferredFuture;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Parallel\ParallelDatabaseCredentialProvider;
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

    public function test_serving_database_provider_runs_credential_reads_in_its_worker_pool(): void
    {
        $path = sys_get_temp_dir() . '/s3-serving-credentials-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pool = null;
        try {
            $bootstrap = CredentialProviderFactory::create('database', ['dsn' => 'sqlite:' . $path], true);
            $bootstrap->putCredential(new Credential('access-key', 'secret-key', 'owner'));

            $provider = CredentialProviderFactory::createForServing(
                'database',
                ['dsn' => 'sqlite:' . $path],
                1,
                2.0,
            );
            self::assertInstanceOf(ParallelDatabaseCredentialProvider::class, $provider);
            self::assertSame('secret-key', $provider->getCredential('access-key')?->secretAccessKey);
            $provider->transferCredentialWorkerPools(static function (WorkerPool $owned) use (&$pool): void {
                $pool = $owned;
            });
        } finally {
            $pool?->shutdown();
            @unlink($path);
        }
    }

    public function test_serving_chain_offloads_nested_database_providers(): void
    {
        $path = sys_get_temp_dir() . '/s3-serving-credential-chain-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pool = null;
        try {
            CredentialProviderFactory::create('database', ['dsn' => 'sqlite:' . $path], true);
            $provider = CredentialProviderFactory::createForServing('chain', ['providers' => [[
                'driver' => 'database',
                'dsn' => 'sqlite:' . $path,
            ]]], 1, 2.0);

            self::assertInstanceOf(ChainCredentialProvider::class, $provider);
            $provider->transferCredentialWorkerPools(static function (WorkerPool $owned) use (&$pool): void {
                $pool = $owned;
            });
            self::assertInstanceOf(WorkerPool::class, $pool);
        } finally {
            $pool?->shutdown();
            @unlink($path);
        }
    }

    public function test_serving_database_timeout_kills_the_stalled_worker_and_hides_connection_details(): void
    {
        $pending = new DeferredFuture();
        $worker = $this->createMock(Worker::class);
        $worker->expects(self::once())->method('submit')->willReturnCallback(
            fn($task): Execution => new Execution(
                $task,
                $this->createStub(\Amp\Sync\Channel::class),
                $pending->getFuture(),
            ),
        );
        $worker->expects(self::once())->method('kill');
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->expects(self::once())->method('getWorker')->willReturn($worker);
        $pool->expects(self::once())->method('shutdown');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credential database operation timed out.');
        new ParallelDatabaseCredentialProvider(
            'pgsql:host=secret.internal;password=hidden',
            workerLimit: 1,
            operationTimeoutSeconds: 0.01,
            pool: $pool,
        );
    }

    public function test_cluster_identity_includes_cache_ttl_for_direct_and_parallel_providers_without_secrets(): void
    {
        $storeIdentity = str_repeat('a', 32);
        $pdo = new class ('sqlite::memory:') extends \PDO {
            public function getAttribute(int $attribute): mixed
            {
                return $attribute === \PDO::ATTR_DRIVER_NAME ? 'pgsql' : parent::getAttribute($attribute);
            }
        };
        $pdo->exec('CREATE TABLE s3_credential_store_identity (singleton_id SMALLINT PRIMARY KEY, credential_store_identity VARCHAR(32) NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO s3_credential_store_identity (singleton_id, credential_store_identity) VALUES (1, ?)');
        $insert->execute([$storeIdentity]);

        $direct = new DatabaseCredentialProvider($pdo, 30.0);
        $directEquivalent = new DatabaseCredentialProvider($pdo, 30.0);
        $directDifferent = new DatabaseCredentialProvider($pdo, 31.0);
        $dsn = 'pgsql:host=secret.internal;password=hidden';
        $parallel = new ParallelDatabaseCredentialProvider(
            $dsn,
            password: 'parallel-secret',
            cacheTtlSeconds: 30.0,
            workerLimit: 1,
            pool: $this->credentialIdentityPool($storeIdentity),
        );
        $parallelEquivalent = new ParallelDatabaseCredentialProvider(
            $dsn,
            password: 'different-secret',
            cacheTtlSeconds: 30.0,
            workerLimit: 1,
            pool: $this->credentialIdentityPool($storeIdentity),
        );
        $parallelDifferent = new ParallelDatabaseCredentialProvider(
            $dsn,
            cacheTtlSeconds: 31.0,
            workerLimit: 1,
            pool: $this->credentialIdentityPool($storeIdentity),
        );

        $identity = $direct->clusterAuthenticationIdentity();
        self::assertSame($identity, $directEquivalent->clusterAuthenticationIdentity());
        self::assertNotSame($identity, $directDifferent->clusterAuthenticationIdentity());
        self::assertSame($identity, $parallel->clusterAuthenticationIdentity());
        self::assertSame($identity, $parallelEquivalent->clusterAuthenticationIdentity());
        self::assertNotSame($identity, $parallelDifferent->clusterAuthenticationIdentity());
        self::assertStringNotContainsString('secret.internal', $identity);
        self::assertStringNotContainsString('parallel-secret', $identity);
        self::assertStringNotContainsString('different-secret', $identity);
    }

    private function credentialIdentityPool(string $storeIdentity): WorkerPool
    {
        $worker = $this->createStub(Worker::class);
        $worker->method('submit')->willReturnCallback(function (\Amp\Parallel\Worker\Task $task) use ($storeIdentity): Execution {
            $method = (new \ReflectionProperty($task, 'method'))->getValue($task);
            $result = $method === 'credentialStoreIdentity' ? $storeIdentity : null;

            return new Execution(
                $task,
                $this->createStub(\Amp\Sync\Channel::class),
                \Amp\Future::complete($result),
            );
        });
        $pool = $this->createStub(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('getWorker')->willReturn($worker);

        return $pool;
    }
}
