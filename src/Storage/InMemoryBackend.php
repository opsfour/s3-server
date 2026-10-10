<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;

/**
 * In-memory storage backend for testing and development.
 *
 * All object data is held in PHP arrays. Not suitable for production
 * use due to memory constraints. Multipart and copy operations are
 * fully supported.
 */
final class InMemoryBackend implements RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend
{
    /** @var array<string, string> Object data keyed by storage path. */
    private array $objects = [];

    /** @var array<string, true> Bucket existence tracker. */
    private array $buckets = [];

    /** @var array<string, string> Part data keyed by unique storage path. */
    private array $parts = [];

    /** @var array<string, array<string, true>> Part paths keyed by upload ID. */
    private array $partPathsByUpload = [];

    /** @var array<string, string> Upload ID keyed by part storage path. */
    private array $partUploadByPath = [];

    /** @var array<string, string> Bucket name keyed by part storage path. */
    private array $partBucketByPath = [];

    /** @var int Auto-incrementing path counter for unique storage paths. */
    private int $pathCounter = 0;

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->writeObjectAtPath($body, $this->generatePath($bucket, $key));
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return StoragePath::forMultipartCompletion('memory://', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject('memory://', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        StoragePath::assertObjectWritePath('memory://', $path, $bucket, $key);

        return $this->writeObjectAtPath($body, $path);
    }

    private function writeObjectAtPath(ReadableStream $body, string $path): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $data = '';

        while (($chunk = $body->read()) !== null) {
            $calculator->update($chunk);
            $data .= $chunk;
        }

        $checksums = $calculator->finalize();
        $this->objects[$path] = $data;

        return new StorageWriteResult(
            path: $path,
            size: strlen($data),
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        if (! isset($this->objects[$storagePath]) && ! isset($this->parts[$storagePath])) {
            throw new NoSuchKeyException();
        }

        $data = $this->objects[$storagePath] ?? $this->parts[$storagePath];

        if ($offset !== null && $offset > 0) {
            $data = substr($data, $offset);
        }

        if ($length !== null) {
            $data = substr($data, 0, $length);
        }

        return new ReadableBuffer($data);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        StoragePath::assertBucketPathSegment($bucket);
        if (isset($this->objects[$storagePath])
            && ! str_starts_with($storagePath, "memory://{$bucket}/")
            && ! str_starts_with($storagePath, "memory://.assemblies/{$bucket}/")) {
            throw new \InvalidArgumentException('Stored object path does not belong to the bucket.');
        }
        if (isset($this->parts[$storagePath]) && ($this->partBucketByPath[$storagePath] ?? null) !== $bucket) {
            throw new \InvalidArgumentException('Stored multipart part does not belong to the bucket.');
        }

        unset($this->objects[$storagePath]);
        unset($this->parts[$storagePath], $this->partBucketByPath[$storagePath]);
        $uploadId = $this->partUploadByPath[$storagePath] ?? null;
        if ($uploadId !== null) {
            unset($this->partUploadByPath[$storagePath], $this->partPathsByUpload[$uploadId][$storagePath]);
            if ($this->partPathsByUpload[$uploadId] === []) {
                unset($this->partPathsByUpload[$uploadId]);
            }
        }
    }

    public function createBucket(string $bucket): void
    {
        $this->buckets[$bucket] = true;
    }

    public function deleteBucket(string $bucket): void
    {
        unset($this->buckets[$bucket]);

        $objectPrefix = "memory://{$bucket}/";
        foreach (array_keys($this->objects) as $path) {
            if (str_starts_with($path, $objectPrefix)) {
                unset($this->objects[$path]);
            }
        }

        foreach ($this->partBucketByPath as $path => $partBucket) {
            if ($partBucket === $bucket) {
                $this->deleteObjectByPath($path, $bucket);
            }
        }
    }

    public function bucketExists(string $bucket): bool
    {
        return isset($this->buckets[$bucket]);
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        $path = sprintf('memory://parts/%s/%d/%d', $uploadId, $partNumber, ++$this->pathCounter);

        return $this->writePartAtPath($bucket, $uploadId, $data, $path);
    }

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return StoragePath::forPlannedPart('memory://', $bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        StoragePath::assertPlannedPartPath('memory://', $path, $uploadId, $partNumber);

        return $this->writePartAtPath($bucket, $uploadId, $data, $path);
    }

    private function writePartAtPath(string $bucket, string $uploadId, ReadableStream $data, string $path): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $content = '';

        while (($chunk = $data->read()) !== null) {
            $calculator->update($chunk);
            $content .= $chunk;
        }

        $checksums = $calculator->finalize();

        $this->parts[$path] = $content;
        $this->partPathsByUpload[$uploadId][$path] = true;
        $this->partUploadByPath[$path] = $uploadId;
        $this->partBucketByPath[$path] = $bucket;

        return new StorageWriteResult(
            path: $path,
            size: strlen($content),
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        return $this->assembleAtPath($bucket, $uploadId, $parts, $this->generatePath($bucket, $key));
    }

    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        StoragePath::assertMultipartCompletionPath('memory://', $path);
        StoragePath::assertObjectWritePath('memory://', $path, $bucket, $key);

        return $this->assembleAtPath($bucket, $uploadId, $parts, $path);
    }

    /** @param array<int, array{partNumber: int, etag: string, storagePath: string}> $parts */
    private function assembleAtPath(string $bucket, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $assembled = '';
        $partMd5s = '';

        foreach ($parts as $part) {
            $partNumber = $part['partNumber'];
            $partPath = $part['storagePath'];

            if (! isset($this->parts[$partPath], $this->partPathsByUpload[$uploadId][$partPath])
                || ($this->partBucketByPath[$partPath] ?? null) !== $bucket) {
                throw new InternalErrorException("Part {$partNumber} not found for upload {$uploadId}.");
            }

            $partData = $this->parts[$partPath];
            $partMd5 = md5($partData);
            if (strtolower(trim($part['etag'], '"')) !== $partMd5) {
                throw new InternalErrorException("Part {$partNumber} failed physical checksum verification for upload {$uploadId}.");
            }
            $calculator->update($partData);
            $assembled .= $partData;

            $partMd5s .= hex2bin($partMd5);
        }

        $checksums = $calculator->finalize();
        $compositeMd5 = hash('md5', $partMd5s);
        $this->objects[$path] = $assembled;

        return new StorageWriteResult(
            path: $path,
            size: strlen($assembled),
            md5Hex: $compositeMd5 . '-' . count($parts),
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        foreach (array_keys($this->partPathsByUpload[$uploadId] ?? []) as $path) {
            if (($this->partBucketByPath[$path] ?? null) !== $bucket) {
                throw new \InvalidArgumentException('Multipart upload does not belong to the bucket.');
            }
        }
        foreach (array_keys($this->partPathsByUpload[$uploadId] ?? []) as $path) {
            unset($this->parts[$path], $this->partUploadByPath[$path], $this->partBucketByPath[$path]);
        }
        unset($this->partPathsByUpload[$uploadId]);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        if (! isset($this->objects[$srcPath])) {
            throw new NoSuchKeyException();
        }

        $data = $this->objects[$srcPath];
        $calculator = new ChecksumCalculator();
        $calculator->update($data);
        $checksums = $calculator->finalize();
        $path = $this->generatePath($dstBucket, $dstKey);

        $this->objects[$path] = $data;

        return new StorageWriteResult(
            path: $path,
            size: strlen($data),
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum copied bytes must be non-negative.');
        }
        if (! isset($this->objects[$srcPath])) {
            throw new NoSuchKeyException();
        }

        return $this->putObject($dstBucket, $dstKey, new ReadableBuffer(substr($this->objects[$srcPath], 0, $maxBytes)));
    }

    private function generatePath(string $bucket, string $key): string
    {
        $hash = hash('sha256', $key);

        return sprintf(
            'memory://%s/%s/%s/%d',
            $bucket,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            ++$this->pathCounter,
        );
    }
}
