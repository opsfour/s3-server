<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;

final readonly class ObservedStorageBackend implements ShutdownAwareStorageBackend, StorageBackend
{
    public function __construct(
        private StorageBackend $inner,
        private MetricsCollector $metrics,
        private string $driver,
        private int $slowThresholdNs = 250_000_000,
    ) {}

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->observe('putObject', fn() => $this->inner->putObject($bucket, $key, $body));
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        return $this->observe('getObjectByPath', fn() => $this->inner->getObjectByPath($storagePath, $offset, $length));
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $this->observe('deleteObjectByPath', fn() => $this->inner->deleteObjectByPath($storagePath, $bucket));
    }

    public function createBucket(string $bucket): void
    {
        $this->observe('createBucket', fn() => $this->inner->createBucket($bucket));
    }

    public function deleteBucket(string $bucket): void
    {
        $this->observe('deleteBucket', fn() => $this->inner->deleteBucket($bucket));
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->observe('bucketExists', fn() => $this->inner->bucketExists($bucket));
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        return $this->observe('putPart', fn() => $this->inner->putPart($bucket, $key, $uploadId, $partNumber, $data));
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        return $this->observe('assembleMultipartUpload', fn() => $this->inner->assembleMultipartUpload($bucket, $key, $uploadId, $parts));
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->observe('abortMultipartUpload', fn() => $this->inner->abortMultipartUpload($bucket, $key, $uploadId));
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        return $this->observe('copyObject', fn() => $this->inner->copyObject($srcPath, $dstBucket, $dstKey));
    }

    public function innerBackend(): StorageBackend
    {
        return $this->inner;
    }

    public function shutdown(): void
    {
        if ($this->inner instanceof ShutdownAwareStorageBackend) {
            $this->inner->shutdown();
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function observe(string $operation, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $this->metrics->recordBackendOperation(
                backend: 'storage',
                driver: $this->driver,
                operation: $operation,
                durationNs: hrtime(true) - $startedAt,
                success: false,
                slowThresholdNs: $this->slowThresholdNs,
                exception: $e::class,
            );
            throw $e;
        }

        $this->metrics->recordBackendOperation(
            backend: 'storage',
            driver: $this->driver,
            operation: $operation,
            durationNs: hrtime(true) - $startedAt,
            slowThresholdNs: $this->slowThresholdNs,
        );

        return $result;
    }
}
