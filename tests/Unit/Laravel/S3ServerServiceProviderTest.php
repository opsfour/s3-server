<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Laravel;

use Illuminate\Config\Repository;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Laravel\S3CredentialsCommand;
use OpsFour\S3Server\Laravel\S3QuotaCommand;
use OpsFour\S3Server\Laravel\S3ServerCommand;
use OpsFour\S3Server\Laravel\S3ServerServiceProvider;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmissionCapability;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_laravel_forwards_all_cluster_identity_fields(): void
    {
        $config = $this->application([
            'cluster_id' => 'cluster-a',
            'cluster_node_id' => 'node-a',
            'cluster_database_id' => str_repeat('d', 32),
            'cluster_roles' => 'api,reconcile',
            'cluster_required_roles' => 'webhook,reconcile,api',
        ])->make(S3ServerConfig::class);

        self::assertSame('cluster-a', $config->clusterId);
        self::assertSame('node-a', $config->clusterNodeId);
        self::assertSame(str_repeat('d', 32), $config->clusterDatabaseId);
        self::assertSame(['api', 'reconcile'], $config->clusterRoles);
        self::assertSame(['api', 'reconcile', 'webhook'], $config->clusterRequiredRoles);
    }

    public function test_artisan_serve_checks_postgres_driver_before_resolving_metadata(): void
    {
        if (extension_loaded('pq')) {
            self::markTestSkipped('The negative startup case requires PHP without pecl-pq.');
        }

        $app = $this->application([
            'metadata' => [
                'driver' => 'postgres',
                'dsn' => 'host=127.0.0.1 port=1 dbname=unreachable password=private-dsn-secret',
            ],
        ]);
        $app->singleton(MetadataStore::class, static function (): never {
            throw new \LogicException('Metadata was resolved before the driver check.');
        });
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('PostgreSQL API servers require the pecl-pq PHP extension.', $tester->getDisplay());
        self::assertStringNotContainsString('private-dsn-secret', $tester->getDisplay());
    }

    public function test_default_sqlite_metadata_uses_the_internal_storage_namespace(): void
    {
        $app = $this->application([
            'storage' => ['driver' => 'filesystem', 'path' => $this->basePath],
            'metadata' => ['path' => null, 'cache_ttl' => 0.0],
            'parallel' => ['sqlite_workers' => 0],
        ]);

        $metadata = $app->make(MetadataStore::class);
        self::assertInstanceOf(SqliteMetadataStore::class, $metadata);
        self::assertSame($this->basePath . '/.opsfour/metadata.sqlite', $metadata->databaseFilePath());
        self::assertFileExists($this->basePath . '/.opsfour/metadata.sqlite');

        unset($metadata, $app);
        foreach (glob($this->basePath . '/.opsfour/metadata.sqlite*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->basePath . '/.opsfour');
    }

    public function test_non_filesystem_storage_requires_an_explicit_sqlite_metadata_path(): void
    {
        $app = $this->application([
            'storage' => ['driver' => 'memory'],
            'metadata' => ['driver' => 'sqlite', 'path' => null],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit SQLite metadata path');
        $app->make(MetadataStore::class);
    }

    public function test_laravel_resolves_the_explicit_aws_s3_profile_without_flysystem_services(): void
    {
        $app = $this->application([
            'storage' => [
                'driver' => 'aws-s3',
                'bucket' => 'provider-bucket',
                'region' => 'us-east-1',
                'expected_owner_id' => 'invalid-owner',
                'data_prefix' => 'objects',
                'reserved_prefix' => '.opsfour/attempts',
            ],
            'metadata' => ['path' => ':memory:'],
        ]);

        $backend = $app->make(StorageBackend::class);
        self::assertInstanceOf(AwsS3ProviderAdmissionCapability::class, $backend);
        self::assertSame('configuration-invalid', $backend->awsS3ProviderAdmission()->reason);
    }

    public function test_ambiguous_default_sqlite_files_require_an_explicit_metadata_path(): void
    {
        self::assertTrue(mkdir($this->basePath . '/.opsfour'));
        self::assertNotFalse(file_put_contents($this->basePath . '/metadata.sqlite', 'legacy'));
        self::assertNotFalse(file_put_contents($this->basePath . '/.opsfour/metadata.sqlite', 'internal'));

        try {
            $app = $this->application([
                'storage' => ['driver' => 'filesystem', 'path' => $this->basePath],
                'metadata' => ['path' => null],
            ]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Set S3_METADATA_PATH explicitly');
            $app->make(MetadataStore::class);
        } finally {
            @unlink($this->basePath . '/.opsfour/metadata.sqlite');
            @unlink($this->basePath . '/metadata.sqlite');
            @rmdir($this->basePath . '/.opsfour');
        }
    }

    public function test_artisan_serve_does_not_echo_dependency_exception_details(): void
    {
        $app = $this->application();
        $app->singleton(MetadataStore::class, static function (): never {
            throw new \RuntimeException('database-password-secret-sentinel');
        });
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringNotContainsString('database-password-secret-sentinel', $tester->getDisplay());
        self::assertStringContainsString('bootstrap failed', $tester->getDisplay());
    }

    public function test_artisan_serve_reports_shutdown_failure_with_failure_exit_code(): void
    {
        $occupied = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($occupied);
        $address = stream_socket_get_name($occupied, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willThrowException(new \RuntimeException('private-laravel-storage-secret'));
        $app = $this->application(['allow_unfenced_storage_for_tests' => true]);
        $app->instance(StorageTierRegistry::class, StorageTierRegistry::single($storage));
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);

        try {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['--host' => '127.0.0.1', '--port' => (string) $port]));
            self::assertStringContainsString('runtime failed', $tester->getDisplay());
            self::assertStringContainsString('shutdown failed', $tester->getDisplay());
            self::assertStringNotContainsString('private-laravel-storage-secret', $tester->getDisplay());
        } finally {
            fclose($occupied);
        }
    }

    #[DataProvider('invalidNumericEnvironmentValues')]
    public function test_package_config_rejects_invalid_numeric_environment_values(string $name, string $value): void
    {
        $previous = getenv($name);
        putenv("{$name}={$value}");
        new Application($this->basePath);

        try {
            $this->expectException(\InvalidArgumentException::class);
            require __DIR__ . '/../../../config/s3-server.php';
        } finally {
            putenv($previous === false ? $name : "{$name}={$previous}");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidNumericEnvironmentValues(): iterable
    {
        yield 'rate limit silently disabled' => ['S3_RATE_LIMIT', 'invalid'];
        yield 'quota silently disabled' => ['S3_QUOTA_MAX_BYTES_PER_OWNER', 'invalid'];
        yield 'body limit silently changed' => ['S3_BODY_SIZE_LIMIT', 'invalid'];
        yield 'integer overflow' => ['S3_MAX_CONNECTIONS', '999999999999999999999999999'];
        yield 'lifecycle interval silently changed' => ['S3_LIFECYCLE_INTERVAL_SECONDS', 'invalid'];
    }

    #[DataProvider('booleanEnvironmentValues')]
    public function test_package_config_strictly_parses_boolean_environment_values(
        string $name,
        string $raw,
        ?bool $expected,
    ): void {
        $previous = getenv($name);
        putenv("{$name}={$raw}");
        new Application($this->basePath);

        try {
            if ($expected === null) {
                $this->expectException(\InvalidArgumentException::class);
                $this->expectExceptionMessage("{$name} must be true or false.");
            }

            $config = require __DIR__ . '/../../../config/s3-server.php';
            if ($expected !== null) {
                self::assertSame($expected, self::booleanPackageConfigValue($config, $name));
            }
        } finally {
            putenv($previous === false ? $name : "{$name}={$previous}");
        }
    }

    /** @return iterable<string, array{string, string, bool|null}> */
    public static function booleanEnvironmentValues(): iterable
    {
        yield 'unsafe fence bypass typo fails closed' => ['S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS', 'flase', null];
        yield 'strict naming typo is rejected' => ['S3_STRICT_BUCKET_NAMING', 'treu', null];
        yield 'minimum part typo is rejected' => ['S3_ENFORCE_MIN_PART_SIZE', 'enabled', null];
        yield 'webhook HTTPS typo is rejected' => ['S3_NOTIFICATION_REQUIRE_HTTPS', 'disabled', null];
        yield 'notification worker typo is rejected' => ['S3_NOTIFICATION_WORKER', 'nope', null];
        yield 'unsafe fence bypass explicit false remains false' => ['S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS', 'false', false];
        yield 'strict naming explicit zero is false' => ['S3_STRICT_BUCKET_NAMING', '0', false];
        yield 'minimum part explicit true remains true' => ['S3_ENFORCE_MIN_PART_SIZE', 'true', true];
        yield 'webhook HTTPS explicit one is true' => ['S3_NOTIFICATION_REQUIRE_HTTPS', '1', true];
        yield 'notification worker explicit off is false' => ['S3_NOTIFICATION_WORKER', 'off', false];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function booleanPackageConfigValue(array $config, string $name): bool
    {
        return match ($name) {
            'S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS' => $config['allow_unfenced_storage_for_tests'],
            'S3_STRICT_BUCKET_NAMING' => $config['strict_bucket_naming'],
            'S3_ENFORCE_MIN_PART_SIZE' => $config['enforce_min_part_size'],
            'S3_NOTIFICATION_REQUIRE_HTTPS' => $config['notifications']['require_https'],
            'S3_NOTIFICATION_WORKER' => $config['notifications']['worker_enabled'],
            default => throw new \LogicException("Unknown boolean environment variable {$name}."),
        };
    }

    public function test_package_config_preserves_explicit_zero_numeric_values(): void
    {
        $names = [
            'S3_RATE_LIMIT',
            'S3_QUOTA_MAX_BYTES_PER_OWNER',
            'S3_BODY_SIZE_LIMIT',
            'S3_CREDENTIALS_CACHE_TTL',
        ];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
            putenv("{$name}=0");
        }
        new Application($this->basePath);

        try {
            $config = require __DIR__ . '/../../../config/s3-server.php';
            self::assertSame(0, $config['rate_limit']);
            self::assertSame(0, $config['quotas']['max_bytes_per_owner']);
            self::assertSame(0, $config['body_size_limit']);
            self::assertSame(0.0, $config['credentials']['cache_ttl']);
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }

    public function test_package_config_uses_legacy_post_budget_when_canonical_budget_is_absent(): void
    {
        $canonical = getenv('S3_STORAGE_TEMP_BUDGET');
        $legacy = getenv('S3_POST_SPOOL_BUDGET');
        putenv('S3_STORAGE_TEMP_BUDGET');
        putenv('S3_POST_SPOOL_BUDGET=7654321');
        new Application($this->basePath);

        try {
            $config = require __DIR__ . '/../../../config/s3-server.php';
            self::assertSame(7_654_321, $config['post_spool_budget']);
            self::assertSame(7_654_321, $config['storage']['temp_budget_bytes']);
        } finally {
            putenv($canonical === false ? 'S3_STORAGE_TEMP_BUDGET' : "S3_STORAGE_TEMP_BUDGET={$canonical}");
            putenv($legacy === false ? 'S3_POST_SPOOL_BUDGET' : "S3_POST_SPOOL_BUDGET={$legacy}");
        }
    }

    public function test_package_config_preserves_invalid_external_iam_enabled_for_runtime_validation(): void
    {
        $name = 'S3_EXTERNAL_IAM_ENABLED';
        $previous = getenv($name);
        putenv("{$name}=treu");
        new Application($this->basePath);

        try {
            $config = require __DIR__ . '/../../../config/s3-server.php';
            self::assertSame('treu', $config['external_iam']['enabled']);
        } finally {
            putenv($previous === false ? $name : "{$name}={$previous}");
        }
    }

    public function test_package_config_defaults_sqlite_to_four_workers_and_preserves_zero_opt_out(): void
    {
        $previous = getenv('S3_SQLITE_WORKERS');
        new Application($this->basePath);

        try {
            putenv('S3_SQLITE_WORKERS');
            $config = require __DIR__ . '/../../../config/s3-server.php';
            self::assertSame(4, $config['parallel']['sqlite_workers']);

            putenv('S3_SQLITE_WORKERS=0');
            $config = require __DIR__ . '/../../../config/s3-server.php';
            self::assertSame(0, $config['parallel']['sqlite_workers']);
        } finally {
            putenv($previous === false ? 'S3_SQLITE_WORKERS' : "S3_SQLITE_WORKERS={$previous}");
        }
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
            'trusted_proxy_cidrs' => '127.0.0.1/32, ::1/128',
            'post_spool_budget' => 987_654,
            'storage' => ['temp_budget_bytes' => 987_654],
            'local_write_worker_timeout_seconds' => 12.5,
            'enforce_min_part_size' => false,
            'allow_unfenced_storage_for_tests' => true,
            'shutdown_readiness_delay' => 17,
            'notifications' => ['worker_enabled' => false],
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
        self::assertSame(['127.0.0.1/32', '::1/128'], $config->trustedProxyCidrs);
        self::assertSame(987_654, $config->postSpoolBudgetBytes);
        self::assertSame(987_654, $config->storageTempBudgetBytes);
        self::assertSame(12.5, $config->localWriteWorkerTimeoutSeconds);
        self::assertFalse($config->enforceMinPartSize);
        self::assertTrue($config->allowUnfencedStorageForTests);
        self::assertSame(17, $config->shutdownReadinessDelay);
        self::assertFalse($config->notificationWorkerEnabled);
        self::assertSame(0, $config->sqliteWorkerPoolSize);
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

    public function test_deprecated_post_budget_alone_becomes_the_canonical_laravel_node_budget(): void
    {
        $config = $this->application([
            'post_spool_budget' => 654_321,
        ])->make(S3ServerConfig::class);

        self::assertSame(654_321, $config->postSpoolBudgetBytes);
        self::assertSame(654_321, $config->storageTempBudgetBytes);
    }

    public function test_laravel_rejects_divergent_explicit_post_and_storage_budgets(): void
    {
        $app = $this->application([
            'post_spool_budget' => 654_321,
            'storage' => ['temp_budget_bytes' => 654_322],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deprecated and must equal');
        $app->make(S3ServerConfig::class);
    }

    public function test_provider_registers_all_documented_artisan_commands(): void
    {
        ArtisanApplication::forgetBootstrappers();

        try {
            $app = $this->application();
            $app->boot();
            $artisan = new ArtisanApplication($app, new Dispatcher($app), 'test');

            self::assertInstanceOf(S3ServerCommand::class, $artisan->find('s3:serve'));
            self::assertInstanceOf(S3CredentialsCommand::class, $artisan->find('s3:credentials'));
            self::assertInstanceOf(S3QuotaCommand::class, $artisan->find('s3:quotas'));
        } finally {
            ArtisanApplication::forgetBootstrappers();
        }
    }

    #[DataProvider('invalidMetadataCacheTtlValues')]
    public function test_provider_rejects_non_finite_metadata_cache_ttl(float $ttl): void
    {
        $app = $this->application([
            'metadata' => ['cache_ttl' => $ttl],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata cache TTL');
        $app->make(MetadataStore::class);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidMetadataCacheTtlValues(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    public function test_provider_keeps_zero_metadata_cache_ttl_disabled(): void
    {
        $app = $this->application([
            'metadata' => ['cache_ttl' => 0.0],
        ]);

        self::assertNotInstanceOf(CachedMetadataStoreDecorator::class, $app->make(MetadataStore::class));
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

    public function test_artisan_quota_command_rejects_values_above_the_platform_integer_range(): void
    {
        $app = $this->application();
        $command = $app->make(S3QuotaCommand::class);
        self::assertInstanceOf(S3QuotaCommand::class, $command);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'set',
            'owner-id' => 'account-a',
            '--max-bytes' => (string) PHP_INT_MAX . '0',
        ]));
        self::assertStringContainsString('--max-bytes must be an integer >= 0 within the supported range', $tester->getDisplay());
        self::assertNull($app->make(MetadataStore::class)->getAccountQuota('account-a'));
    }

    public function test_artisan_quota_command_rejects_invalid_owner_before_writing(): void
    {
        $app = $this->application();
        $command = $app->make(S3QuotaCommand::class);
        self::assertInstanceOf(S3QuotaCommand::class, $command);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'set',
            'owner-id' => str_repeat('a', 256),
        ]));
        self::assertStringContainsString('Owner ID must contain 1 to 255 bytes', $tester->getDisplay());
        self::assertSame([], $app->make(MetadataStore::class)->listAccountQuotas());
    }

    public function test_provider_resolves_direct_flysystem_service_like_symfony_integration(): void
    {
        $app = $this->application([
            'storage' => [
                'driver' => 'flysystem',
                'filesystem_service' => 'test.flysystem',
                'worker_pool_size' => 0,
            ],
        ]);
        $app->instance('test.flysystem', new Filesystem(new InMemoryFlysystemAdapter()));

        $backend = $app->make(StorageBackend::class);

        self::assertInstanceOf(ObservedStorageBackend::class, $backend);
        self::assertInstanceOf(FlysystemBackend::class, $backend->innerBackend());
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

    public function test_serve_command_uses_container_encryption_service(): void
    {
        $app = $this->application();
        $service = $this->createStub(EncryptionServiceInterface::class);
        $app->instance(EncryptionServiceInterface::class, $service);
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);
        $resolve = new \ReflectionMethod($command, 'encryptionFromContainer');

        self::assertSame(
            $service,
            $resolve->invoke($command, $app->make(S3ServerConfig::class), $app->make(\OpsFour\S3Server\Observability\MetricsCollector::class)),
        );
    }

    public function test_serve_command_builds_opt_in_laravel_event_bridge(): void
    {
        $app = $this->application([
            'notifications' => [
                'laravel_event_dispatcher' => [
                    'enabled' => true,
                    'pattern' => 's3:ObjectCreated:*',
                    'event_name' => null,
                ],
            ],
        ]);
        $events = new Dispatcher($app);
        $app->instance('events', $events);
        $received = null;
        $events->listen(S3Event::class, static function (S3Event $event) use (&$received): void {
            $received = $event;
        });
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);
        $resolve = new \ReflectionMethod($command, 'notificationListenersFromConfig');

        $listeners = $resolve->invoke($command);
        self::assertIsArray($listeners);
        self::assertCount(1, $listeners);
        self::assertSame('s3:ObjectCreated:*', $listeners[0]['pattern']);
        self::assertIsCallable($listeners[0]['listener']);
        $event = new S3Event('s3:ObjectCreated:Put', 'bucket', 'key');
        ($listeners[0]['listener'])($event);
        self::assertSame($event, $received);
    }

    public function test_serve_command_rejects_non_decimal_port_without_truncating_it(): void
    {
        $app = $this->application();
        $command = $app->make(S3ServerCommand::class);
        self::assertInstanceOf(S3ServerCommand::class, $command);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--port' => '9000junk']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('S3 server bootstrap failed', $tester->getDisplay());
    }

    public function test_explicit_quota_admin_token_is_not_replaced_by_external_iam_token(): void
    {
        $app = $this->application([
            'admin' => ['token' => '0'],
            'external_iam' => ['admin_token' => 'external-secret'],
        ]);
        $command = $app->make(S3ServerCommand::class);
        $resolve = new \ReflectionMethod($command, 'adminTokenFromConfig');

        self::assertSame('0', $resolve->invoke($command));

        $app['config']->set('s3-server.admin.token', null);
        self::assertSame('external-secret', $resolve->invoke($command));

        $app['config']->set('s3-server.admin.token', '');
        self::assertSame('', $resolve->invoke($command));
    }

    public function test_serve_command_rejects_memory_key_override_with_database_credentials(): void
    {
        $app = $this->application([
            'credentials' => [
                'driver' => 'database',
                'dsn' => 'sqlite::memory:',
            ],
        ]);
        $command = $app->make(S3ServerCommand::class);
        $command->setLaravel($app);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--access-key' => 'override']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('require the memory credentials driver', $tester->getDisplay());
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
