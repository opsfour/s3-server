<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Factory;

use Amp\ByteStream\ReadableBuffer;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmissionCapability;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageBackendFactoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/s3server-storage-factory-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $budgetDir = $this->tempDir . '/.opsfour-s3-storage-budget';
        foreach (glob($budgetDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($budgetDir);
        @rmdir($this->tempDir);
    }

    public function test_direct_flysystem_backend_enforces_and_releases_configured_temp_budget(): void
    {
        $adapter = new InMemoryFlysystemAdapter();
        $backend = StorageBackendFactory::create('flysystem', [
            'filesystem' => new Filesystem($adapter),
            'temp_dir' => $this->tempDir,
            'temp_budget_bytes' => 1,
            'worker_pool_size' => 0,
        ]);
        self::assertInstanceOf(FlysystemBackend::class, $backend);

        try {
            $backend->putObject('bucket', 'too-large', new ReadableBuffer('12'));
            self::fail('The direct Flysystem path must reject writes beyond its staging budget.');
        } catch (SlowDownException $error) {
            self::assertSame('SlowDown', $error->getErrorCode());
        }

        self::assertSame(0, $adapter->writeStreamCalls);
        self::assertSame([], $adapter->paths());
        self::assertSame([], glob($this->tempDir . '/s3fw-put-*') ?: []);
        self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/lease-*') ?: []);
        self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/state-*') ?: []);

        $result = $backend->putObject('bucket', 'fits', new ReadableBuffer('1'));

        self::assertSame(1, $adapter->writeStreamCalls);
        self::assertSame('1', $adapter->contents($result->path));
        self::assertSame([], glob($this->tempDir . '/s3fw-put-*') ?: []);
        self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/lease-*') ?: []);
        self::assertSame([], glob($this->tempDir . '/.opsfour-s3-storage-budget/state-*') ?: []);
    }

    public function test_negative_flysystem_worker_count_is_not_treated_as_synchronous_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Flysystem worker_pool_size must be a decimal integer >= 0.');

        StorageBackendFactory::create('flysystem', [
            'filesystem' => new Filesystem(new InMemoryFlysystemAdapter()),
            'temp_dir' => $this->tempDir,
            'worker_pool_size' => -1,
        ]);
    }

    public function test_malformed_flysystem_first_byte_timeout_is_not_truncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Flysystem read_first_byte_timeout_seconds must be a positive decimal integer.');

        StorageBackendFactory::create('flysystem', [
            'temp_dir' => $this->tempDir,
            'worker_pool_size' => 1,
            'read_first_byte_timeout_seconds' => '20seconds',
        ]);
    }

    public function test_invalid_aws_profile_remains_distinct_and_exposes_a_closed_admission(): void
    {
        $backend = StorageBackendFactory::create('aws-s3', [
            'bucket' => 'provider-bucket',
            'region' => 'us-east-1',
            'expected_owner_id' => 'not-an-account',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/attempts',
        ], new MetricsCollector());

        self::assertInstanceOf(AwsS3ProviderAdmissionCapability::class, $backend);
        self::assertNotInstanceOf(ObservedStorageBackend::class, $backend);
        self::assertSame('configuration-invalid', $backend->awsS3ProviderAdmission()->reason);
    }

    public function test_aws_tiers_cannot_overlap_data_or_reserved_namespaces(): void
    {
        $profile = [
            'driver' => 'aws-s3',
            'bucket' => 'provider-bucket',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/attempts',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');
        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => $profile,
                'ARCHIVE' => array_replace($profile, [
                    'data_prefix' => 'archive',
                    'reserved_prefix' => '.opsfour/attempts/archive',
                ]),
            ],
        ]);
    }

    public function test_explicit_aws_and_official_endpoint_flysystem_tiers_cannot_overlap_in_either_order(): void
    {
        $profile = [
            'driver' => 'aws-s3',
            'bucket' => 'provider-bucket',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/attempts',
        ];
        $cases = [
            ['objects/archive', 'https://s3.amazonaws.com', false],
            ['objects/archive', 'https://s3-us-east-1.amazonaws.com', true],
            ['.opsfour', 'https://s3.dualstack.us-east-1.amazonaws.com', false],
            ['.opsfour', 'https://s3-fips.us-east-1.amazonaws.com', true],
        ];

        foreach ($cases as [$prefix, $endpoint, $flysystemFirst]) {
            $flysystem = [
                'driver' => 'flysystem',
                'filesystem_factory' => new AwsS3FlysystemFilesystemFactory(
                    'provider-bucket',
                    'us-east-1',
                    'key',
                    'secret',
                    $endpoint,
                    prefix: $prefix,
                ),
                'worker_pool_size' => 1,
            ];
            $tiers = $flysystemFirst
                ? ['STANDARD' => $flysystem, 'ARCHIVE' => $profile]
                : ['STANDARD' => $profile, 'ARCHIVE' => $flysystem];

            try {
                StorageBackendFactory::createTierRegistry(['tiers' => $tiers]);
                self::fail('Mixed AWS namespaces must collide before backend construction.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('must not share the same physical backend', $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function test_negative_sqlite_worker_count_is_not_treated_as_synchronous_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata worker_pool_size must be a decimal integer >= 0.');

        MetadataStoreFactory::create('sqlite', [
            'path' => $this->tempDir . '/metadata.sqlite',
            'worker_pool_size' => -1,
        ]);
    }
}
