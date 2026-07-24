<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;

final class CallbackStorageBackend implements ShutdownAwareStorageBackend, StorageBackend
{
    public ?StorageWriteResult $lastWrite = null;

    public int $putCalls = 0;

    public int $copyCalls = 0;

    public int $shutdownCalls = 0;

    public function __construct(
        private readonly StorageBackend $delegate,
        private readonly ?\Closure $afterPut = null,
        private readonly ?\Closure $afterDelete = null,
        private readonly ?\Closure $beforeBucketExists = null,
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
        return $this->delegate->getObjectByPath($storagePath, $offset, $length);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
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
        return $this->delegate->putPart($bucket, $key, $uploadId, $partNumber, $data);
    }

    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {
        return $this->delegate->assembleMultipartUpload($bucket, $key, $uploadId, $parts);
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->delegate->abortMultipartUpload($bucket, $key, $uploadId);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        $this->copyCalls++;

        return $this->delegate->copyObject($srcPath, $dstBucket, $dstKey);
    }

    public function shutdown(): void
    {
        $this->shutdownCalls++;
        if ($this->delegate instanceof ShutdownAwareStorageBackend) {
            $this->delegate->shutdown();
        }
    }
}
