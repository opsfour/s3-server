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
final class InMemoryBackend implements StorageBackend
{
    /** @var array<string, string> Object data keyed by storage path. */
    private array $objects = [];

    /** @var array<string, true> Bucket existence tracker. */
    private array $buckets = [];

    /** @var array<string, array<int, string>> Parts keyed by uploadId → partNumber. */
    private array $parts = [];

    /** @var int Auto-incrementing path counter for unique storage paths. */
    private int $pathCounter = 0;

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $data = '';

        while (($chunk = $body->read()) !== null) {
            $calculator->update($chunk);
            $data .= $chunk;
        }

        $checksums = $calculator->finalize();
        $path = $this->generatePath($bucket, $key);

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
        if (! isset($this->objects[$storagePath])) {
            throw new NoSuchKeyException();
        }

        $data = $this->objects[$storagePath];

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
        unset($this->objects[$storagePath]);
    }

    public function createBucket(string $bucket): void
    {
        $this->buckets[$bucket] = true;
    }

    public function deleteBucket(string $bucket): void
    {
        unset($this->buckets[$bucket]);
    }

    public function bucketExists(string $bucket): bool
    {
        return isset($this->buckets[$bucket]);
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $content = '';

        while (($chunk = $data->read()) !== null) {
            $calculator->update($chunk);
            $content .= $chunk;
        }

        $checksums = $calculator->finalize();

        $this->parts[$uploadId][$partNumber] = $content;

        $path = "memory://parts/{$uploadId}/{$partNumber}";

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
        $calculator = new ChecksumCalculator();
        $assembled = '';
        $partMd5s = '';

        foreach ($parts as $part) {
            $partNumber = $part['partNumber'];

            if (! isset($this->parts[$uploadId][$partNumber])) {
                throw new InternalErrorException("Part {$partNumber} not found for upload {$uploadId}.");
            }

            $partData = $this->parts[$uploadId][$partNumber];
            $calculator->update($partData);
            $assembled .= $partData;

            $partMd5s .= hex2bin(md5($partData));
        }

        $checksums = $calculator->finalize();
        $compositeMd5 = hash('md5', $partMd5s);
        $path = $this->generatePath($bucket, $key);

        $this->objects[$path] = $assembled;

        // Clean up part data after successful assembly.
        unset($this->parts[$uploadId]);

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
        unset($this->parts[$uploadId]);
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
