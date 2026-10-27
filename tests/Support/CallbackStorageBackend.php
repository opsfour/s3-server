<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\BoundedCopyStorageBackend;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;

final class CallbackStorageBackend implements ShutdownAwareStorageBackend, RecoverableMultipartAssemblyBackend, BoundedCopyStorageBackend
{
    public ?StorageWriteResult $lastWrite = null;

    public ?StorageWriteResult $lastPartWrite = null;

    public int $putCalls = 0;

    public int $copyCalls = 0;

    public int $shutdownCalls = 0;

    public function __construct(
        private readonly StorageBackend $delegate,
        private readonly ?\Closure $afterPut = null,
        private readonly ?\Closure $afterDelete = null,
        private readonly ?\Closure $beforeBucketExists = null,
        private readonly ?\Closure $beforeDelete = null,
        private readonly ?\Closure $afterAssemble = null,
        private readonly ?\Closure $beforeRead = null,
        private readonly ?\Closure $afterCopy = null,
    ) {}

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $this->putCalls++;
        $this->lastWrite = $this->delegate->putObject($bucket, $key, $body);
        if ($this->afterPut !== null) {
            ($this->afterPut)($this->lastWrite);
        }

        return $this->lastWrite;
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        if ($this->beforeRead !== null) {
            ($this->beforeRead)($storagePath);
        }

        return $this->delegate->getObjectByPath($storagePath, $offset, $length);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        if ($this->beforeDelete !== null) {
            ($this->beforeDelete)($storagePath, $bucket);
        }
        $this->delegate->deleteObjectByPath($storagePath, $bucket);
        if ($this->afterDelete !== null) {
            ($this->afterDelete)($storagePath, $bucket);
        }
    }

    public function createBucket(string $bucket): void
    {
        $this->delegate->createBucket($bucket);
    }

    public function deleteBucket(string $bucket): void
    {
        $this->delegate->deleteBucket($bucket);
    }

    public function bucketExists(string $bucket): bool
    {
        if ($this->beforeBucketExists !== null) {
            ($this->beforeBucketExists)($bucket);
        }

        return $this->delegate->bucketExists($bucket);
    }

    public function putPart(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
    ): StorageWriteResult {
        return $this->lastPartWrite = $this->delegate->putPart($bucket, $key, $uploadId, $partNumber, $data);
    }

    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {
        return $this->lastWrite = $this->delegate->assembleMultipartUpload($bucket, $key, $uploadId, $parts);
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return $this->recoverableDelegate()->planMultipartCompletionPaths($bucket, $key, $bucketGeneration, $attemptId);
    }

    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        $this->lastWrite = $this->recoverableDelegate()->assembleMultipartUploadAtPath($bucket, $key, $uploadId, $parts, $path);
        if ($this->afterAssemble !== null) {
            ($this->afterAssemble)($this->lastWrite);
        }

        return $this->lastWrite;
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        $this->putCalls++;
        $this->lastWrite = $this->recoverableDelegate()->putObjectAtPath($bucket, $key, $body, $path);
        if ($this->afterPut !== null) {
            ($this->afterPut)($this->lastWrite);
        }

        return $this->lastWrite;
    }

    private function recoverableDelegate(): RecoverableMultipartAssemblyBackend
    {
        if (! $this->delegate instanceof RecoverableMultipartAssemblyBackend) {
            throw new \LogicException('Storage backend does not support durable multipart completion paths.');
        }

        return $this->delegate;
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->delegate->abortMultipartUpload($bucket, $key, $uploadId);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        $this->copyCalls++;
        $this->lastWrite = $this->delegate->copyObject($srcPath, $dstBucket, $dstKey);
        if ($this->afterCopy !== null) {
            ($this->afterCopy)($this->lastWrite);
        }

        return $this->lastWrite;
    }

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        $this->copyCalls++;
        if ($this->delegate instanceof BoundedCopyStorageBackend) {
            $this->lastWrite = $this->delegate->copyObjectBounded($srcPath, $dstBucket, $dstKey, $maxBytes);
        } else {
            $source = new LimitedReadableStream($this->delegate->getObjectByPath($srcPath), $maxBytes);
            try {
                $this->lastWrite = $this->delegate->putObject($dstBucket, $dstKey, $source);
            } finally {
                $source->close();
            }
        }
        if ($this->afterCopy !== null) {
            ($this->afterCopy)($this->lastWrite);
        }

        return $this->lastWrite;
    }

    public function shutdown(): void
    {
        $this->shutdownCalls++;
        if ($this->delegate instanceof ShutdownAwareStorageBackend) {
            $this->delegate->shutdown();
        }
    }
}
