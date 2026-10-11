<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\BoundedCopyStorageBackend;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartPartBackend;
use OpsFour\S3Server\Storage\RecoverableObjectWriteBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;

final readonly class ObservedStorageBackend implements ShutdownAwareStorageBackend, RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend
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

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return $this->partWriteInner()->planMultipartPartPath($bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        return $this->observe('putPartAtPath', fn() => $this->partWriteInner()->putPartAtPath($bucket, $key, $uploadId, $partNumber, $data, $path));
    }

    private function partWriteInner(): RecoverableMultipartPartBackend
    {
        if (! $this->inner instanceof RecoverableMultipartPartBackend) {
            throw new \LogicException('Storage backend does not support durable planned multipart part paths.');
        }

        return $this->inner;
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        return $this->observe('assembleMultipartUpload', fn() => $this->inner->assembleMultipartUpload($bucket, $key, $uploadId, $parts));
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return $this->recoverableInner()->planMultipartCompletionPaths($bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return $this->objectWriteInner()->planObjectWritePath($bucket, $key, $bucketGeneration, $attemptId);
    }

    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        return $this->observe('assembleMultipartUploadAtPath', fn() => $this->recoverableInner()->assembleMultipartUploadAtPath($bucket, $key, $uploadId, $parts, $path));
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        return $this->observe('putObjectAtPath', fn() => ($this->inner instanceof RecoverableObjectWriteBackend
            ? $this->inner : $this->recoverableInner())->putObjectAtPath($bucket, $key, $body, $path));
    }

    private function objectWriteInner(): RecoverableObjectWriteBackend
    {
        if (! $this->inner instanceof RecoverableObjectWriteBackend) {
            throw new \LogicException('Storage backend does not support durable planned object paths.');
        }

        return $this->inner;
    }

    private function recoverableInner(): RecoverableMultipartAssemblyBackend
    {
        if (! $this->inner instanceof RecoverableMultipartAssemblyBackend) {
            throw new \LogicException('Storage backend does not support durable multipart completion paths.');
        }

        return $this->inner;
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->observe('abortMultipartUpload', fn() => $this->inner->abortMultipartUpload($bucket, $key, $uploadId));
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        return $this->observe('copyObject', fn() => $this->inner->copyObject($srcPath, $dstBucket, $dstKey));
    }

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        return $this->observe('copyObjectBounded', function () use ($srcPath, $dstBucket, $dstKey, $maxBytes): StorageWriteResult {
            if ($this->inner instanceof BoundedCopyStorageBackend) {
                return $this->inner->copyObjectBounded($srcPath, $dstBucket, $dstKey, $maxBytes);
            }

            $source = new LimitedReadableStream($this->inner->getObjectByPath($srcPath), $maxBytes);
            $cancellation = null;
            try {
                return $this->inner->putObject($dstBucket, $dstKey, $source);
            } catch (\Amp\CancelledException $error) {
                $cancellation = $error;
                throw $error;
            } finally {
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                    $cancellation,
                    $source->close(...),
                );
            }
        });
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
