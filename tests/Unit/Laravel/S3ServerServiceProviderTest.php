<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Laravel\S3QuotaCommand;
use OpsFour\S3Server\Laravel\S3ServerCommand;
use OpsFour\S3Server\Laravel\S3ServerServiceProvider;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class S3ServerServiceProviderTest extends TestCase
{
    private string $basePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/s3-laravel-' . bin2hex(random_bytes(5));
        mkdir($this->basePath, 0o700, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->basePath);

        parent::tearDown();
    }

    public function test_provider_resolves_config_and_core_singletons(): void
    {
        $app = $this->application([
            'server' => [],
            'host' => '127.0.0.1',
            'port' => 9444,
            'region' => 'eu-central-1',
            'max_connections' => 123,
            'body_size_limit' => 456_789,
            'enforce_min_part_size' => false,
            'parallel' => [
                'select_workers' => 4,
                'request_body_spool_workers' => 3,
            ],
        ]);

        $config = $app->make(S3ServerConfig::class);
        self::assertInstanceOf(S3ServerConfig::class, $config);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9444, $config->port);
        self::assertSame('eu-central-1', $config->region);
        self::assertSame(123, $config->maxConcurrentConnections);
        self::assertSame(456_789, $config->requestBodySizeLimit);
        self::assertFalse($config->enforceMinPartSize);
        self::assertSame(4, $config->selectWorkerPoolSize);
        self::assertSame(3, $config->requestBodySpoolWorkerPoolSize);

        $registry = $app->make(StorageTierRegistry::class);
        self::assertInstanceOf(StorageTierRegistry::class, $registry);
        $storage = $app->make(StorageBackend::class);
        self::assertInstanceOf(ObservedStorageBackend::class, $storage);
        self::assertInstanceOf(InMemoryBackend::class, $storage->innerBackend());
        self::assertSame($storage, $app->make(StorageBackend::class));
        self::assertInstanceOf(CachedMetadataStoreDecorator::class, $app->make(MetadataStore::class));
        self::assertInstanceOf(CredentialProvider::class, $app->make(CredentialProvider::class));
    }

    public function test_artisan_quota_command_manages_provider_metadata(): void
    {
        $app = $this->application();
        $metadata = $app->make(MetadataStore::class);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $command = $app->make(S3QuotaCommand::class);
        self::assertInstanceOf(S3QuotaCommand::class, $command);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        $exit = $tester->execute([
            'action' => 'set',
            'owner-id' => 'account-a',
            '--max-buckets' => '2',
            '--max-objects-per-bucket' => '10',
            '--max-bytes-per-bucket' => '1024',
            '--max-bytes' => '4096',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $quota = $metadata->getAccountQuota('account-a');
        self::assertNotNull($quota);
        self::assertSame(2, $quota->maxBucketsPerOwner);
        self::assertSame(10, $quota->maxObjectsPerBucket);
        self::assertSame(1024, $quota->maxBytesPerBucket);
        self::assertSame(4096, $quota->maxBytesPerOwner);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        self::assertStringContainsString('account-a', $tester->getDisplay());

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'action' => 'delete',
            'owner-id' => 'account-a',
        ]));
        self::assertNull($metadata->getAccountQuota('account-a'));
    }

    public function test_serve_command_reports_invalid_overrides_as_failure(): void
    {
        $app = $this->application();
        $command = $app->make(S3ServerCommand::class);
        self::assertInstanceOf(S3ServerCommand::class, $command);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--port' => '0']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Port must be between 1 and 65535', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function application(array $overrides = []): Application
    {
        $config = array_replace_recursive([
            'storage' => [
                'driver' => 'memory',
                'path' => $this->basePath,
                'tiers' => [],
            ],
            'metadata' => [
                'driver' => 'sqlite',
                'path' => ':memory:',
                'cache_ttl' => 5.0,
            ],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => 'laravelAccessKey',
                'secret_key' => 'laravelSecretKey',
                'owner_id' => 'laravel-owner',
                'display_name' => 'Laravel Owner',
            ],
        ], $overrides);

        $app = new Application($this->basePath);
        $app->instance('config', new Repository(['s3-server' => $config]));
        $app->register(S3ServerServiceProvider::class);

        return $app;
    }
}
