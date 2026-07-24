<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class ServeCommandTest extends TestCase
{
    private string $tempDir = '';

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
            '--base-domain' => 's3.example.test',
            '--strict-bucket-naming' => 'false',
            '--website-host-pattern' => '*.website.example.test',
            '--rate-limit' => '321',
            '--master-key-provider' => 'vault',
            '--max-encrypted-object-size' => '123456',
            '--max-select-object-size' => '654321',
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
        self::assertSame('s3.example.test', $config->baseDomain);
        self::assertFalse($config->strictBucketNaming);
        self::assertSame('*.website.example.test', $config->websiteHostPattern);
        self::assertSame(321, $config->perClientRateLimit);
        self::assertSame('vault', $config->masterKeyProvider);
        self::assertSame(123456, $config->maxEncryptedObjectSize);
        self::assertSame(654321, $config->maxSelectObjectSize);
        self::assertSame(3, $config->sqliteWorkerPoolSize);
        self::assertSame(4, $config->encryptionWorkerPoolSize);
        self::assertSame(6, $config->selectWorkerPoolSize);
        self::assertSame(5, $config->requestBodySpoolWorkerPoolSize);
        self::assertSame(8192, $config->encryptionParallelThreshold);
        self::assertSame('metrics-secret', $config->metricsBearerToken);
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
