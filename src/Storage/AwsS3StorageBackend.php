<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/** Provider identity only; Story 4.1 deliberately exposes no storage mutation path. */
final readonly class AwsS3StorageBackend implements StorageBackend, AwsS3ProviderAdmissionCapability
{
    public function __construct(private AwsS3ProviderAdmission $admission) {}

    public function awsS3ProviderAdmission(): AwsS3ProviderAdmission
    {
        return $this->admission;
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        throw $this->unavailable();
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        throw $this->unavailable();
    }

    public function createBucket(string $bucket): void
    {
        throw $this->unavailable();
    }

    public function deleteBucket(string $bucket): void
    {
        throw $this->unavailable();
    }

    public function bucketExists(string $bucket): bool
    {
        throw $this->unavailable();
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        throw $this->unavailable();
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        throw $this->unavailable();
    }

    private function unavailable(): \LogicException
    {
        return new \LogicException('AWS S3 storage operations are unavailable until the provider write fence is complete.');
    }
}
