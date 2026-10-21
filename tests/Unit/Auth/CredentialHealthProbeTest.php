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
}
