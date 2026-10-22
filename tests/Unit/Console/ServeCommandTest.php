<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
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
            '--encryption-threshold' => '8192',
            '--metrics-bearer-token' => 'metrics-secret',
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
        self::assertSame(8192, $config->encryptionParallelThreshold);
        self::assertSame('metrics-secret', $config->metricsBearerToken);
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

    public function test_explicit_zero_environment_options_are_not_replaced_by_defaults(): void
    {
        $options = [
            'S3_STORAGE_TEMP_BUDGET' => 'storage-temp-budget',
            'S3_METADATA_CACHE_TTL' => 'metadata-cache-ttl',
            'S3_CREDENTIALS_CACHE_TTL' => 'credentials-cache-ttl',
            'S3_RATE_LIMIT' => 'rate-limit',
            'S3_TRUSTED_PROXY_CIDRS' => 'trusted-proxy-cidrs',
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
