<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class ObservedStorageBackendTest extends TestCase
{
    public function test_records_successful_storage_operations(): void
    {
        $metrics = new MetricsCollector();
        $storage = new ObservedStorageBackend(new InMemoryBackend(), $metrics, 'memory', slowThresholdNs: 0);

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
        $metrics = new MetricsCollector();
        $storage = new ObservedStorageBackend(new ThrowingStorageBackend(), $metrics, 'throwing');

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

    public function test_shutdown_is_forwarded_to_shutdown_aware_backend(): void
    {
        $inner = new ShutdownRecordingStorageBackend();
        $storage = new ObservedStorageBackend($inner, new MetricsCollector(), 'recording');

        $storage->shutdown();

        self::assertTrue($inner->shutdownCalled);
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

final class ShutdownRecordingStorageBackend implements ShutdownAwareStorageBackend, StorageBackend
{
    public bool $shutdownCalled = false;

    private InMemoryBackend $inner;

    public function __construct()
    {
        $this->inner = new InMemoryBackend();
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->inner->putObject($bucket, $key, $body);
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        return $this->inner->getObjectByPath($storagePath, $offset, $length);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $this->inner->deleteObjectByPath($storagePath, $bucket);
    }

    public function createBucket(string $bucket): void
    {
        $this->inner->createBucket($bucket);
    }

    public function deleteBucket(string $bucket): void
    {
        $this->inner->deleteBucket($bucket);
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->inner->bucketExists($bucket);
    }

    public function putPart(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
    ): StorageWriteResult {
        return $this->inner->putPart($bucket, $key, $uploadId, $partNumber, $data);
    }

    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {
        return $this->inner->assembleMultipartUpload($bucket, $key, $uploadId, $parts);
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->inner->abortMultipartUpload($bucket, $key, $uploadId);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        return $this->inner->copyObject($srcPath, $dstBucket, $dstKey);
    }

    public function shutdown(): void
    {
        $this->shutdownCalled = true;
    }
}
