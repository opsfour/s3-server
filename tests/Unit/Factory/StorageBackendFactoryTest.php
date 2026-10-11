<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Factory;

use Amp\ByteStream\ReadableBuffer;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Storage\FlysystemBackend;
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
