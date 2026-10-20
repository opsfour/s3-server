<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PostgresDriverStartupTest extends TestCase
{
    public function test_postgres_api_refuses_to_start_without_pecl_pq(): void
    {
        if (extension_loaded('pq')) {
            self::markTestSkipped('The negative startup case requires PHP without pecl-pq.');
        }

        $server = new S3Server(new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            metadataDriver: 'postgres',
            perClientRateLimit: 0,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
            'us-east-1',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL API servers require the pecl-pq PHP extension.');
        $server->start();
    }

    public function test_standalone_command_checks_driver_before_connecting_to_postgres(): void
    {
        if (extension_loaded('pq')) {
            self::markTestSkipped('The negative startup case requires PHP without pecl-pq.');
        }

        $tester = new CommandTester(new ServeCommand());
        $exit = $tester->execute([
            '--storage-driver' => 'memory',
            '--metadata-driver' => 'postgres',
            '--metadata-dsn' => 'host=127.0.0.1 port=1 dbname=unreachable password=private-dsn-secret',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('PostgreSQL API servers require the pecl-pq PHP extension.', $tester->getDisplay());
        self::assertStringNotContainsString('private-dsn-secret', $tester->getDisplay());
    }

    public function test_runtime_factory_rejects_missing_driver_before_wiring_handlers(): void
    {
        if (extension_loaded('pq')) {
            self::markTestSkipped('The negative startup case requires PHP without pecl-pq.');
        }

        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL API servers require the pecl-pq PHP extension.');
        (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'postgres'),
            metadata: $metadata,
            storage: new InMemoryBackend(),
            credentialProvider: new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
        );
    }
}
