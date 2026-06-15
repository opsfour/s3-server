<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class ObservedStorageBackendTest extends TestCase
{
    public function test_records_successful_storage_operations(): void
    {
        $metrics = new MetricsCollector;
        $storage = new ObservedStorageBackend(new InMemoryBackend, $metrics, 'memory', slowThresholdNs: 0);

        $storage->createBucket('bucket');
        $write = $storage->putObject('bucket', 'key.txt', new ReadableBuffer('payload'));

        $this->assertSame(7, $write->size);
        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_backend_operations_total{backend="storage",driver="memory",operation="createBucket"} 1', $rendered);
        $this->assertStringContainsString('s3_server_backend_operations_total{backend="storage",driver="memory",operation="putObject"} 1', $rendered);
        $this->assertStringContainsString('s3_server_backend_slow_operations_total{backend="storage",driver="memory",operation="putObject"} 1', $rendered);
    }

    public function test_records_failed_storage_operations_and_rethrows(): void
    {
        $metrics = new MetricsCollector;
        $storage = new ObservedStorageBackend(new ThrowingStorageBackend, $metrics, 'throwing');

        $this->expectException(\RuntimeException::class);

        try {
            $storage->createBucket('bucket');
        } finally {
            $this->assertStringContainsString(
                's3_server_backend_errors_total{backend="storage",driver="throwing",exception="RuntimeException",operation="createBucket"} 1',
                $metrics->renderPrometheus(),
            );
        }
    }
}

final class ThrowingStorageBackend implements StorageBackend
{
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        throw new \RuntimeException('storage failed');
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        throw new \RuntimeException('storage failed');
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        throw new \RuntimeException('storage failed');
    }

    public function createBucket(string $bucket): void
    {
        throw new \RuntimeException('storage failed');
    }

    public function deleteBucket(string $bucket): void
    {
        throw new \RuntimeException('storage failed');
    }

    public function bucketExists(string $bucket): bool
    {
        throw new \RuntimeException('storage failed');
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        throw new \RuntimeException('storage failed');
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        throw new \RuntimeException('storage failed');
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        throw new \RuntimeException('storage failed');
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        throw new \RuntimeException('storage failed');
    }
}
