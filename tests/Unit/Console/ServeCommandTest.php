<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmissionCapability;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ServeCommandTest extends TestCase
{
    private string $tempDir = '';

    public function test_bootstrap_failure_does_not_echo_backend_configuration_details(): void
    {
        $tester = new CommandTester(new ServeCommand());

        self::assertSame(Command::FAILURE, $tester->execute([
            '--storage-driver' => 'storage-secret-sentinel',
            '--storage-temp-dir' => sys_get_temp_dir(),
        ]));
        self::assertStringNotContainsString('storage-secret-sentinel', $tester->getDisplay());
        self::assertStringContainsString('bootstrap failed', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/s3-serve-command-' . bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '') {
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_portable_runtime_options_are_forwarded_to_server_config(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--allow-unfenced-storage-for-tests' => 'true',
            '--base-domain' => 's3.example.test',
            '--strict-bucket-naming' => 'false',
            '--website-host-pattern' => '*.website.example.test',
            '--rate-limit' => '321',
            '--master-key-provider' => 'vault',
            '--max-encrypted-object-size' => '123456',
            '--max-select-object-size' => '654321',
            '--local-write-worker-timeout' => '12.5',
            '--sqlite-workers' => '3',
            '--encryption-workers' => '4',
            '--select-workers' => '6',
            '--request-body-spool-workers' => '5',
            '--aws-read-workers' => '7',
            '--aws-read-waiters' => '8',
            '--aws-read-admission-timeout' => '9',
            '--aws-read-first-byte-timeout' => '10',
            '--encryption-threshold' => '8192',
            '--metrics-bearer-token' => 'metrics-secret',
            '--shutdown-readiness-delay' => '17',
        ]);

        $config = $this->invoke($command, 'configFromInput', [$input, null]);

        self::assertInstanceOf(S3ServerConfig::class, $config);
        self::assertSame($this->tempDir, $config->storagePath);
        self::assertTrue($config->allowUnfencedStorageForTests);
        self::assertSame('s3.example.test', $config->baseDomain);
        self::assertFalse($config->strictBucketNaming);
        self::assertSame('*.website.example.test', $config->websiteHostPattern);
        self::assertSame(321, $config->perClientRateLimit);
        self::assertSame('vault', $config->masterKeyProvider);
        self::assertSame(123456, $config->maxEncryptedObjectSize);
        self::assertSame(654321, $config->maxSelectObjectSize);
        self::assertSame(12.5, $config->localWriteWorkerTimeoutSeconds);
        self::assertSame(3, $config->sqliteWorkerPoolSize);
        self::assertSame(4, $config->encryptionWorkerPoolSize);
        self::assertSame(6, $config->selectWorkerPoolSize);
        self::assertSame(5, $config->requestBodySpoolWorkerPoolSize);
        self::assertSame(7, $config->awsReadWorkerPoolSize);
        self::assertSame(8, $config->awsReadWaiterLimit);
        self::assertSame(9, $config->awsReadAdmissionTimeoutSeconds);
        self::assertSame(10, $config->awsReadFirstByteTimeoutSeconds);
        self::assertSame(8192, $config->encryptionParallelThreshold);
        self::assertSame('metrics-secret', $config->metricsBearerToken);
        self::assertSame(17, $config->shutdownReadinessDelay);
    }

    public function test_aws_read_environment_options_forward_distinct_values(): void
    {
        $values = [
            'S3_AWS_READ_WORKERS' => '11',
            'S3_AWS_READ_WAITERS' => '12',
            'S3_AWS_READ_ADMISSION_TIMEOUT_SECONDS' => '13',
            'S3_AWS_READ_FIRST_BYTE_TIMEOUT_SECONDS' => '14',
        ];
        $originals = [];
        foreach ($values as $name => $value) {
            $originals[$name] = getenv($name);
            putenv("{$name}={$value}");
        }

        try {
            $command = new ServeCommand();
            $config = $this->invoke($command, 'configFromInput', [
                $this->input($command, ['--storage-temp-dir' => $this->tempDir]),
                null,
            ]);

            self::assertSame(11, $config->awsReadWorkerPoolSize);
            self::assertSame(12, $config->awsReadWaiterLimit);
            self::assertSame(13, $config->awsReadAdmissionTimeoutSeconds);
            self::assertSame(14, $config->awsReadFirstByteTimeoutSeconds);
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : "{$name}={$original}");
            }
        }
    }

    public function test_cluster_identity_options_are_forwarded_without_blank_normalization(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--cluster-id' => 'cluster-a',
            '--cluster-node-id' => 'node-a',
            '--cluster-database-id' => str_repeat('d', 32),
            '--cluster-roles' => 'api,reconcile',
            '--cluster-required-roles' => 'webhook,reconcile,api',
        ]);
        $config = $this->invoke($command, 'configFromInput', [$input, null]);

        self::assertSame('cluster-a', $config->clusterId);
        self::assertSame('node-a', $config->clusterNodeId);
        self::assertSame(str_repeat('d', 32), $config->clusterDatabaseId);
        self::assertSame(['api', 'reconcile'], $config->clusterRoles);
        self::assertSame(['api', 'reconcile', 'webhook'], $config->clusterRequiredRoles);

        $blank = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--cluster-id' => '   ',
            '--cluster-node-id' => '   ',
            '--cluster-database-id' => '   ',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke($command, 'configFromInput', [$blank, null]);
    }

    public function test_sqlite_workers_default_to_four_and_zero_remains_an_explicit_opt_out(): void
    {
        $previous = getenv('S3_SQLITE_WORKERS');
        putenv('S3_SQLITE_WORKERS');

        try {
            $command = new ServeCommand();
            $default = $this->input($command, ['--storage-temp-dir' => $this->tempDir]);
            $disabled = $this->input($command, [
                '--storage-temp-dir' => $this->tempDir,
                '--sqlite-workers' => '0',
            ]);

            self::assertSame(4, $this->invoke($command, 'configFromInput', [$default, null])->sqliteWorkerPoolSize);
            self::assertSame(0, $this->invoke($command, 'configFromInput', [$disabled, null])->sqliteWorkerPoolSize);
        } finally {
            putenv($previous === false ? 'S3_SQLITE_WORKERS' : "S3_SQLITE_WORKERS={$previous}");
        }
    }

    public function test_in_memory_sqlite_keeps_the_synchronous_compatibility_mode(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--metadata-path' => ':memory:',
        ]);

        self::assertSame(0, $this->invoke($command, 'configFromInput', [$input, null])->sqliteWorkerPoolSize);
    }

    public function test_non_filesystem_storage_requires_an_explicit_sqlite_metadata_path(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'memory',
            '--storage-temp-dir' => $this->tempDir,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--metadata-path is required when SQLite is used with non-filesystem storage.');
        $this->invoke($command, 'configFromInput', [$input, null]);
    }

    public function test_ambiguous_default_sqlite_files_fail_before_server_start(): void
    {
        self::assertTrue(mkdir($this->tempDir . '/.opsfour'));
        self::assertNotFalse(file_put_contents($this->tempDir . '/metadata.sqlite', 'legacy'));
        self::assertNotFalse(file_put_contents($this->tempDir . '/.opsfour/metadata.sqlite', 'internal'));

        try {
            $command = new ServeCommand();
            $input = $this->input($command, ['--storage-path' => $this->tempDir]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Set S3_METADATA_PATH explicitly');
            $this->invoke($command, 'buildMetadataStore', [
                $input,
                'sqlite',
                '',
                $this->tempDir,
                new MetricsCollector(),
            ]);
        } finally {
            @unlink($this->tempDir . '/.opsfour/metadata.sqlite');
            @unlink($this->tempDir . '/metadata.sqlite');
            @rmdir($this->tempDir . '/.opsfour');
        }
    }

    public function test_cli_memory_and_external_metadata_options_reach_the_start_guard(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'memory',
            '--metadata-driver' => 'mysql',
            '--storage-temp-dir' => $this->tempDir,
        ]);
        $config = $this->invoke($command, 'configFromInput', [$input, null]);

        self::assertInstanceOf(S3ServerConfig::class, $config);
        self::assertSame('mysql', $config->metadataDriver);
        self::assertFalse($config->allowUnfencedStorageForTests);
        $tiers = StorageBackendFactory::createTierRegistry(['driver' => (string) $input->getOption('storage-driver')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ephemeral in-memory object storage');
        StorageWriteFenceStartupGuard::assertReady($tiers, $config->allowUnfencedStorageForTests, $config->metadataDriver);
    }

    public function test_explicit_zero_environment_limits_are_not_replaced_by_defaults(): void
    {
        foreach ([
            'S3_MAX_CONNECTIONS' => ['--max-connections', 'maxConcurrentConnections must be >= 1'],
            'S3_MAX_ENCRYPTED_OBJECT_SIZE' => ['--max-encrypted-object-size', 'maxEncryptedObjectSize must be >= 1'],
            'S3_MAX_SELECT_OBJECT_SIZE' => ['--max-select-object-size', 'maxSelectObjectSize must be >= 1'],
        ] as $name => [$option, $message]) {
            $previous = getenv($name);
            putenv("{$name}=0");

            try {
                $command = new ServeCommand();
                $input = $this->input($command, ['--storage-temp-dir' => $this->tempDir]);
                self::assertSame('0', $input->getOption(ltrim($option, '-')));

                try {
                    $this->invoke($command, 'configFromInput', [$input, null]);
                    self::fail("{$name}=0 was accepted.");
                } catch (\InvalidArgumentException $exception) {
                    self::assertStringContainsString($message, $exception->getMessage());
                }
            } finally {
                putenv($previous === false ? $name : "{$name}={$previous}");
            }
        }

        $name = 'S3_BODY_SIZE_LIMIT';
        $previous = getenv($name);
        putenv("{$name}=0");
        try {
            $command = new ServeCommand();
            $config = $this->invoke($command, 'configFromInput', [
                $this->input($command, ['--storage-temp-dir' => $this->tempDir]),
                null,
            ]);
            self::assertInstanceOf(S3ServerConfig::class, $config);
            self::assertSame(0, $config->requestBodySizeLimit);
        } finally {
            putenv($previous === false ? $name : "{$name}={$previous}");
        }
    }

    public function test_standalone_flysystem_builds_bounded_s3_worker_backend(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'flysystem',
            '--storage-temp-dir' => $this->tempDir,
            '--flysystem-workers' => '2',
            '--backing-bucket' => 'backing-bucket',
            '--backing-region' => 'eu-central-1',
            '--backing-access-key' => 'access-key',
            '--backing-secret-key' => 'secret-key',
            '--backing-endpoint' => 'https://objects.example.test',
            '--backing-path-style' => 'true',
            '--backing-prefix' => 'tenant-a',
        ]);

        $registry = $this->invoke($command, 'buildStorageTierRegistry', [
            $input,
            'flysystem',
            null,
            new MetricsCollector(),
        ]);

        self::assertInstanceOf(StorageTierRegistry::class, $registry);
        $storage = $registry->defaultBackend();
        self::assertInstanceOf(ObservedStorageBackend::class, $storage);
        self::assertInstanceOf(ParallelFlysystemBackend::class, $storage->innerBackend());
        $storage->shutdown();
    }

    public function test_standalone_aws_s3_profile_uses_distinct_options_and_never_falls_back_to_flysystem(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'aws-s3',
            '--aws-s3-bucket' => 'provider-bucket',
            '--aws-s3-region' => 'us-east-1',
            '--aws-s3-expected-owner-id' => 'invalid-owner',
            '--aws-s3-data-prefix' => 'objects',
            '--aws-s3-reserved-prefix' => '.opsfour/attempts',
        ]);

        $registry = $this->invoke($command, 'buildStorageTierRegistry', [
            $input,
            'aws-s3',
            null,
            new MetricsCollector(),
        ]);
        $backend = $registry->defaultBackend();

        self::assertInstanceOf(AwsS3StorageBackend::class, $backend);
        self::assertInstanceOf(AwsS3ProviderAdmissionCapability::class, $backend);
        self::assertSame('configuration-invalid', $backend->awsS3ProviderAdmission()->reason);
    }

    public function test_standalone_flysystem_forwards_explicit_insecure_http_test_opt_in(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'flysystem',
            '--storage-temp-dir' => $this->tempDir,
            '--flysystem-workers' => '1',
            '--backing-bucket' => 'backing-bucket',
            '--backing-region' => 'eu-central-1',
            '--backing-access-key' => 'access-key',
            '--backing-secret-key' => 'secret-key',
            '--backing-endpoint' => 'http://127.0.0.1:9001',
            '--backing-allow-insecure-http-for-tests' => 'true',
        ]);

        $registry = $this->invoke($command, 'buildStorageTierRegistry', [
            $input,
            'flysystem',
            null,
            new MetricsCollector(),
        ]);

        self::assertInstanceOf(ParallelFlysystemBackend::class, $registry->defaultBackend()->innerBackend());
        $registry->defaultBackend()->shutdown();
    }

    public function test_standalone_flysystem_rejects_blocking_mode(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'flysystem',
            '--storage-temp-dir' => $this->tempDir,
            '--flysystem-workers' => '0',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--flysystem-workers >= 1');

        $this->invoke($command, 'buildStorageTierRegistry', [
            $input,
            'flysystem',
            null,
            new MetricsCollector(),
        ]);
    }

    public function test_standalone_flysystem_rejects_invalid_shared_temp_budget(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-driver' => 'flysystem',
            '--storage-temp-dir' => $this->tempDir,
            '--storage-temp-budget' => 'unlimited',
            '--flysystem-workers' => '1',
            '--backing-bucket' => 'backing-bucket',
            '--backing-region' => 'eu-central-1',
            '--backing-access-key' => 'access-key',
            '--backing-secret-key' => 'secret-key',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--storage-temp-budget must be a decimal integer');

        $this->invoke($command, 'buildStorageTierRegistry', [
            $input,
            'flysystem',
            null,
            new MetricsCollector(),
        ]);
    }

    public function test_deprecated_post_budget_environment_alone_becomes_the_cli_canonical_budget(): void
    {
        $canonical = getenv('S3_STORAGE_TEMP_BUDGET');
        $alias = getenv('S3_POST_SPOOL_BUDGET');
        try {
            putenv('S3_STORAGE_TEMP_BUDGET');
            putenv('S3_POST_SPOOL_BUDGET=7654321');
            $command = new ServeCommand();
            $config = $this->invoke($command, 'configFromInput', [
                $this->input($command, ['--storage-temp-dir' => $this->tempDir]),
                null,
            ]);

            self::assertSame(7_654_321, $config->storageTempBudgetBytes);
            self::assertSame(7_654_321, $config->postSpoolBudgetBytes);
        } finally {
            putenv($canonical === false ? 'S3_STORAGE_TEMP_BUDGET' : "S3_STORAGE_TEMP_BUDGET={$canonical}");
            putenv($alias === false ? 'S3_POST_SPOOL_BUDGET' : "S3_POST_SPOOL_BUDGET={$alias}");
        }
    }

    public function test_deprecated_post_budget_option_alone_becomes_the_cli_canonical_budget(): void
    {
        $canonical = getenv('S3_STORAGE_TEMP_BUDGET');
        $alias = getenv('S3_POST_SPOOL_BUDGET');
        try {
            putenv('S3_STORAGE_TEMP_BUDGET');
            putenv('S3_POST_SPOOL_BUDGET');
            $command = new ServeCommand();
            $config = $this->invoke($command, 'configFromInput', [
                $this->input($command, [
                    '--storage-temp-dir' => $this->tempDir,
                    '--post-spool-budget' => '7654321',
                ]),
                null,
            ]);

            self::assertSame(7_654_321, $config->storageTempBudgetBytes);
            self::assertSame(7_654_321, $config->postSpoolBudgetBytes);
        } finally {
            putenv($canonical === false ? 'S3_STORAGE_TEMP_BUDGET' : "S3_STORAGE_TEMP_BUDGET={$canonical}");
            putenv($alias === false ? 'S3_POST_SPOOL_BUDGET' : "S3_POST_SPOOL_BUDGET={$alias}");
        }
    }

    public function test_deprecated_post_budget_option_binds_backend_to_the_same_runtime_contract(): void
    {
        $canonical = getenv('S3_STORAGE_TEMP_BUDGET');
        $alias = getenv('S3_POST_SPOOL_BUDGET');
        $storageRoot = $this->tempDir . '/storage';
        self::assertTrue(mkdir($storageRoot, 0700));
        try {
            putenv('S3_STORAGE_TEMP_BUDGET');
            putenv('S3_POST_SPOOL_BUDGET');
            $command = new ServeCommand();
            $input = $this->input($command, [
                '--storage-driver' => 'filesystem',
                '--storage-path' => $storageRoot,
                '--storage-temp-dir' => $this->tempDir,
                '--storage-temp-reserve' => '0',
                '--post-spool-budget' => '7654321',
            ]);
            $config = $this->invoke($command, 'configFromInput', [$input, $storageRoot]);
            $registry = $this->invoke($command, 'buildStorageTierRegistry', [
                $input,
                'filesystem',
                $storageRoot,
                new MetricsCollector(),
            ]);
            $server = new S3Server($config, storage: $registry->defaultBackend());
            try {
                self::assertSame(7_654_321, $config->storageTempBudgetBytes);
                self::assertSame(7_654_321, $server->storageTempBudgetCoordinator()->budgetBytes());
                self::assertCount(1, glob($this->tempDir . '/.opsfour-s3-storage-budget/process-lease-*') ?: []);
            } finally {
                $server->stop();
                $registry->defaultBackend()->shutdown();
            }
            self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/process-lease-*') ?: []);
            foreach (glob($this->tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tempDir . '/.opsfour-s3-storage-budget');
        } finally {
            putenv($canonical === false ? 'S3_STORAGE_TEMP_BUDGET' : "S3_STORAGE_TEMP_BUDGET={$canonical}");
            putenv($alias === false ? 'S3_POST_SPOOL_BUDGET' : "S3_POST_SPOOL_BUDGET={$alias}");
            @rmdir($storageRoot);
        }
    }

    public function test_cli_rejects_divergent_explicit_canonical_and_post_budget_environments(): void
    {
        $canonical = getenv('S3_STORAGE_TEMP_BUDGET');
        $alias = getenv('S3_POST_SPOOL_BUDGET');
        try {
            putenv('S3_STORAGE_TEMP_BUDGET=7654321');
            putenv('S3_POST_SPOOL_BUDGET=7654322');
            $command = new ServeCommand();

            try {
                $this->invoke($command, 'configFromInput', [
                    $this->input($command, ['--storage-temp-dir' => $this->tempDir]),
                    null,
                ]);
                self::fail('Divergent explicit CLI temporary budgets were accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('deprecated and must equal', $error->getMessage());
            }
        } finally {
            putenv($canonical === false ? 'S3_STORAGE_TEMP_BUDGET' : "S3_STORAGE_TEMP_BUDGET={$canonical}");
            putenv($alias === false ? 'S3_POST_SPOOL_BUDGET' : "S3_POST_SPOOL_BUDGET={$alias}");
        }
    }

    public function test_explicit_zero_environment_options_are_not_replaced_by_defaults(): void
    {
        $options = [
            'S3_HOST' => 'host',
            'S3_PORT' => 'port',
            'S3_STORAGE_PATH' => 'storage-path',
            'S3_STORAGE_DRIVER' => 'storage-driver',
            'S3_ALLOW_UNFENCED_STORAGE_FOR_TESTS' => 'allow-unfenced-storage-for-tests',
            'S3_STORAGE_TEMP_DIR' => 'storage-temp-dir',
            'S3_STORAGE_TEMP_BUDGET' => 'storage-temp-budget',
            'S3_FLYSYSTEM_WORKERS' => 'flysystem-workers',
            'S3_FLYSYSTEM_FIRST_BYTE_TIMEOUT_SECONDS' => 'flysystem-first-byte-timeout',
            'S3_BACKING_BUCKET' => 'backing-bucket',
            'S3_BACKING_REGION' => 'backing-region',
            'S3_BACKING_ACCESS_KEY' => 'backing-access-key',
            'S3_BACKING_SECRET_KEY' => 'backing-secret-key',
            'S3_BACKING_ENDPOINT' => 'backing-endpoint',
            'S3_BACKING_ALLOW_INSECURE_HTTP_FOR_TESTS' => 'backing-allow-insecure-http-for-tests',
            'S3_BACKING_PATH_STYLE' => 'backing-path-style',
            'S3_BACKING_PREFIX' => 'backing-prefix',
            'S3_BACKING_CONNECT_TIMEOUT_SECONDS' => 'backing-connect-timeout',
            'S3_BACKING_READ_TIMEOUT_SECONDS' => 'backing-read-timeout',
            'S3_BACKING_REQUEST_TIMEOUT_SECONDS' => 'backing-request-timeout',
            'S3_REGION' => 'region',
            'S3_METADATA_DRIVER' => 'metadata-driver',
            'S3_METADATA_DSN' => 'metadata-dsn',
            'S3_METADATA_PATH' => 'metadata-path',
            'S3_METADATA_CACHE_TTL' => 'metadata-cache-ttl',
            'S3_TLS_CERT' => 'tls-cert',
            'S3_TLS_KEY' => 'tls-key',
            'S3_CREDENTIALS_CACHE_TTL' => 'credentials-cache-ttl',
            'S3_MAX_CONNECTIONS' => 'max-connections',
            'S3_CONNECTION_IDLE_TIMEOUT' => 'connection-idle-timeout',
            'S3_BODY_SIZE_LIMIT' => 'body-size-limit',
            'S3_POST_SPOOL_BUDGET' => 'post-spool-budget',
            'S3_LOCAL_WRITE_WORKER_TIMEOUT_SECONDS' => 'local-write-worker-timeout',
            'S3_READ_TIMEOUT' => 'read-timeout',
            'S3_WRITE_TIMEOUT' => 'write-timeout',
            'S3_SHUTDOWN_DRAIN_TIMEOUT' => 'shutdown-drain-timeout',
            'S3_SHUTDOWN_READINESS_DELAY' => 'shutdown-readiness-delay',
            'S3_REQUEST_BODY_SPOOL_WORKERS' => 'request-body-spool-workers',
            'S3_AWS_READ_WORKERS' => 'aws-read-workers',
            'S3_AWS_READ_WAITERS' => 'aws-read-waiters',
            'S3_AWS_READ_ADMISSION_TIMEOUT_SECONDS' => 'aws-read-admission-timeout',
            'S3_AWS_READ_FIRST_BYTE_TIMEOUT_SECONDS' => 'aws-read-first-byte-timeout',
            'S3_SQLITE_WORKERS' => 'sqlite-workers',
            'S3_ENCRYPTION_WORKERS' => 'encryption-workers',
            'S3_SELECT_WORKERS' => 'select-workers',
            'S3_ENCRYPTION_THRESHOLD' => 'encryption-threshold',
            'S3_ENFORCE_MIN_PART_SIZE' => 'enforce-min-part-size',
            'S3_NOTIFICATION_REQUIRE_HTTPS' => 'notification-require-https',
            'S3_NOTIFICATION_WORKER' => 'notification-worker',
            'S3_BASE_DOMAIN' => 'base-domain',
            'S3_STRICT_BUCKET_NAMING' => 'strict-bucket-naming',
            'S3_WEBSITE_HOST_PATTERN' => 'website-host-pattern',
            'S3_RATE_LIMIT' => 'rate-limit',
            'S3_TRUSTED_PROXY_CIDRS' => 'trusted-proxy-cidrs',
            'S3_MASTER_KEY_PROVIDER' => 'master-key-provider',
            'S3_MAX_ENCRYPTED_OBJECT_SIZE' => 'max-encrypted-object-size',
            'S3_MAX_SELECT_OBJECT_SIZE' => 'max-select-object-size',
            'S3_METRICS_BEARER_TOKEN' => 'metrics-bearer-token',
            'S3_CREDENTIALS_DRIVER' => 'credentials-driver',
            'S3_ACCESS_KEY' => 'access-key',
            'S3_SECRET_KEY' => 'secret-key',
            'S3_OWNER_ID' => 'owner-id',
            'S3_DISPLAY_NAME' => 'display-name',
            'S3_CREDENTIALS_DSN' => 'credentials-dsn',
            'S3_CREDENTIALS_USERNAME' => 'credentials-username',
            'S3_CREDENTIALS_PASSWORD' => 'credentials-password',
            'S3_CREDENTIALS_WORKERS' => 'credentials-workers',
            'S3_CREDENTIALS_OPERATION_TIMEOUT_SECONDS' => 'credentials-operation-timeout',
            'S3_CREDENTIALS_PATH' => 'credentials-file',
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => 'quota-max-buckets-per-owner',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => 'quota-max-objects-per-bucket',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => 'quota-max-bytes-per-bucket',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => 'quota-max-bytes-per-owner',
            'S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_BUCKET' => 'quota-max-multipart-uploads-per-bucket',
            'S3_QUOTA_MAX_MULTIPART_UPLOADS_PER_OWNER' => 'quota-max-multipart-uploads-per-owner',
            'S3_QUOTA_MAX_MULTIPART_BYTES_PER_BUCKET' => 'quota-max-multipart-bytes-per-bucket',
            'S3_QUOTA_MAX_MULTIPART_BYTES_PER_OWNER' => 'quota-max-multipart-bytes-per-owner',
            'S3_LIFECYCLE_INTERVAL_SECONDS' => 'lifecycle-interval-seconds',
            'S3_LIFECYCLE_BATCH_SIZE' => 'lifecycle-batch-size',
            'S3_LIFECYCLE_MAX_ACTIONS_PER_RUN' => 'lifecycle-max-actions-per-run',
            'S3_LIFECYCLE_LOCK_TTL_SECONDS' => 'lifecycle-lock-ttl-seconds',
            'S3_MULTIPART_MAX_AGE_SECONDS' => 'multipart-max-age-seconds',
        ];
        $originals = [];
        foreach ($options as $name => $_) {
            $originals[$name] = getenv($name);
            putenv($name . '=0');
        }
        try {
            $command = new ServeCommand();
            foreach ($options as $option) {
                self::assertSame('0', $command->getDefinition()->getOption($option)->getDefault());
            }
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : $name . '=' . $original);
            }
        }
    }

    public function test_explicit_empty_environment_options_are_left_for_downstream_validation(): void
    {
        $options = [
            'S3_HOST' => 'host',
            'S3_STORAGE_PATH' => 'storage-path',
            'S3_STORAGE_DRIVER' => 'storage-driver',
            'S3_METADATA_PATH' => 'metadata-path',
            'S3_REGION' => 'region',
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => 'quota-max-buckets-per-owner',
        ];
        $originals = [];
        foreach ($options as $name => $_) {
            $originals[$name] = getenv($name);
            putenv($name . '=');
        }

        try {
            $command = new ServeCommand();
            foreach ($options as $option) {
                self::assertSame('', $command->getDefinition()->getOption($option)->getDefault());
            }
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : $name . '=' . $original);
            }
        }
    }

    public function test_absent_environment_options_keep_their_previous_defaults(): void
    {
        $expected = [
            'S3_HOST' => ['host', '0.0.0.0'],
            'S3_STORAGE_PATH' => ['storage-path', null],
            'S3_STORAGE_DRIVER' => ['storage-driver', 'filesystem'],
            'S3_BACKING_PREFIX' => ['backing-prefix', ''],
            'S3_METADATA_PATH' => ['metadata-path', null],
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => ['quota-max-buckets-per-owner', '0'],
        ];
        $originals = [];
        foreach ($expected as $name => $_) {
            $originals[$name] = getenv($name);
            putenv($name);
        }

        try {
            $command = new ServeCommand();
            foreach ($expected as [$option, $default]) {
                self::assertSame($default, $command->getDefinition()->getOption($option)->getDefault());
            }
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : $name . '=' . $original);
            }
        }
    }

    public function test_zero_metrics_token_from_environment_remains_required(): void
    {
        $original = getenv('S3_METRICS_BEARER_TOKEN');
        putenv('S3_METRICS_BEARER_TOKEN=0');
        try {
            $command = new ServeCommand();
            $input = $this->input($command, ['--storage-temp-dir' => $this->tempDir]);
            $config = $this->invoke($command, 'configFromInput', [$input, null]);

            self::assertSame('0', $command->getDefinition()->getOption('metrics-bearer-token')->getDefault());
            self::assertSame('0', $config->metricsBearerToken);
        } finally {
            putenv($original === false ? 'S3_METRICS_BEARER_TOKEN' : 'S3_METRICS_BEARER_TOKEN=' . $original);
        }
    }

    public function test_explicit_empty_quota_admin_token_does_not_fall_back_to_external_iam_token(): void
    {
        $names = ['S3_ADMIN_API_TOKEN', 'S3_EXTERNAL_IAM_ADMIN_TOKEN'];
        $originals = [];
        foreach ($names as $name) {
            $originals[$name] = getenv($name);
        }
        putenv('S3_ADMIN_API_TOKEN=');
        putenv('S3_EXTERNAL_IAM_ADMIN_TOKEN=external-secret');
        try {
            self::assertSame('', $this->invoke(new ServeCommand(), 'adminApiTokenFromEnv', []));
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : $name . '=' . $original);
            }
        }
    }

    public function test_explicit_empty_metrics_token_does_not_publish_metrics(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--metrics-bearer-token' => '',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metricsBearerToken must not be empty');

        $this->invoke($command, 'configFromInput', [$input, null]);
    }

    public function test_zero_external_iam_environment_values_are_preserved(): void
    {
        $names = [
            'S3_EXTERNAL_IAM_ADMIN_TOKEN' => 'admin_token',
            'S3_EXTERNAL_IAM_OWNER_PREFIX' => 'owner_prefix',
            'S3_EXTERNAL_IAM_OWNER_CLAIM' => 'owner_claim',
            'S3_EXTERNAL_IAM_ALLOWED_PREFIXES_CLAIM' => 'allowed_prefixes_claim',
            'S3_EXTERNAL_IAM_CLOCK_SKEW_SECONDS' => 'clock_skew_seconds',
        ];
        $originals = [];
        foreach ($names as $name => $_) {
            $originals[$name] = getenv($name);
            putenv($name . '=0');
        }
        try {
            $config = $this->invoke(new ServeCommand(), 'externalIamConfigFromEnv', []);
            foreach ($names as $field) {
                self::assertSame('0', $config[$field]);
            }
        } finally {
            foreach ($originals as $name => $original) {
                putenv($original === false ? $name : $name . '=' . $original);
            }
        }
    }

    public function test_invalid_worker_configuration_is_rejected_before_startup(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--sqlite-workers' => '-1',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sqliteWorkerPoolSize must be >= 0');

        $this->invoke($command, 'configFromInput', [$input, null]);
    }

    public function test_malformed_quota_limit_cannot_silently_become_unlimited(): void
    {
        $command = new ServeCommand();
        $input = $this->input($command, [
            '--storage-temp-dir' => $this->tempDir,
            '--quota-max-bytes-per-owner' => 'unlimited',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--quota-max-bytes-per-owner must be a decimal integer');

        $this->invoke($command, 'configFromInput', [$input, null]);
    }

    public function test_integer_overflow_and_numeric_suffix_are_rejected_before_startup(): void
    {
        $command = new ServeCommand();
        foreach ([
            ['--quota-max-bytes-per-owner' => '99999999999999999999999999999'],
            ['--rate-limit' => '25junk'],
        ] as $options) {
            try {
                $this->invoke($command, 'configFromInput', [
                    $this->input($command, ['--storage-temp-dir' => $this->tempDir] + $options),
                    null,
                ]);
                self::fail('Malformed numeric option was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('must be a decimal integer', $exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $options
     */
    private function input(ServeCommand $command, array $options): ArrayInput
    {
        $input = new ArrayInput($options);
        $input->bind($command->getDefinition());

        return $input;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invoke(ServeCommand $command, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($command, $method))->invokeArgs($command, $arguments);
    }
}
