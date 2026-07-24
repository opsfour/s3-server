<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;

/**
 * Storage backend wrapping League Flysystem for access to any adapter.
 *
 * Bridges Amp streams with Flysystem's synchronous API. This direct adapter
 * mode blocks the calling event loop and is retained for compatibility and
 * development. Use ParallelFlysystemBackend for production remote storage.
 *
 * Storage layout mirrors FilesystemBackend:
 *   Objects: {bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
 */
final class FlysystemBackend implements StorageBackend
{
    private readonly string $tempDir;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        ?string $tempDir = null,
    ) {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        $tmpFile = $this->createTempFile('s3put_');
        $tmpStream = fopen($tmpFile, 'w+b');
        if ($tmpStream === false) {
            @unlink($tmpFile);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        try {
            while (($chunk = $body->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            $storagePath = $this->objectPath($bucket, $key);

            try {
                $this->filesystem->writeStream($storagePath, $tmpStream);
            } catch (\Throwable $e) {
                throw new InternalErrorException('Failed to write object to Flysystem backend: ' . $e->getMessage(), $e);
            }
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            @unlink($tmpFile);
        }

        return new StorageWriteResult(
            path: $storagePath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        try {
            $resource = $this->filesystem->readStream($storagePath);
        } catch (\Throwable) {
            throw new NoSuchKeyException();
        }

        if ($offset !== null && $offset > 0) {
            self::skipBytes($resource, $offset);
        }

        if ($length !== null) {
            return new LimitedReadableStream(new ReadableResourceStream($resource), $length);
        }

        return new ReadableResourceStream($resource);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        try {
            $this->filesystem->delete($storagePath);
        } catch (UnableToDeleteFile) {
            // Idempotent: silently succeed if already gone.
        }
    }

    public function createBucket(string $bucket): void
    {
        $this->filesystem->createDirectory($bucket);
    }

    public function deleteBucket(string $bucket): void
    {
        try {
            $this->filesystem->deleteDirectory($bucket);
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }

    public function bucketExists(string $bucket): bool
    {
        return $this->filesystem->directoryExists($bucket);
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        $tmpFile = $this->createTempFile('s3part_');
        $tmpStream = fopen($tmpFile, 'w+b');
        if ($tmpStream === false) {
            @unlink($tmpFile);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        try {
            while (($chunk = $data->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            $partPath = sprintf(
                '.parts/%s/%d-%s',
                $uploadId,
                $partNumber,
                bin2hex(random_bytes(16)),
            );

            try {
                $this->filesystem->writeStream($partPath, $tmpStream);
            } catch (\Throwable $e) {
                throw new InternalErrorException('Failed to write multipart part to Flysystem backend: ' . $e->getMessage(), $e);
            }
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            @unlink($tmpFile);
        }

        return new StorageWriteResult(
            path: $partPath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        $tmpFile = $this->createTempFile('s3mpu_');

        $outHandle = fopen($tmpFile, 'w');
        if ($outHandle === false) {
            unlink($tmpFile);
            throw new InternalErrorException('Failed to open temp file for multipart assembly.');
        }

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        try {
            foreach ($parts as $part) {
                $partNumber = $part['partNumber'];
                $partPath = $part['storagePath'];

                try {
                    $partStream = $this->filesystem->readStream($partPath);
                } catch (\Throwable) {
                    fclose($outHandle);
                    throw new InternalErrorException("Part {$partNumber} not found for upload {$uploadId}.");
                }

                $partMd5Context = hash_init('md5');

                try {
                    self::consumeResource($partStream, function (string $chunk) use (
                        $calculator,
                        $partMd5Context,
                        $outHandle,
                        &$size,
                    ): void {
                        $calculator->update($chunk);
                        hash_update($partMd5Context, $chunk);
                        $size += strlen($chunk);
                        self::writeAll($outHandle, $chunk);
                    });
                } finally {
                    fclose($partStream);
                }

                $partMd5s .= hex2bin(hash_final($partMd5Context));
            }

            fclose($outHandle);

            $checksums = $calculator->finalize();
            $compositeMd5 = hash('md5', $partMd5s);
            $storagePath = $this->objectPath($bucket, $key);

            $resource = fopen($tmpFile, 'r');
            if ($resource === false) {
                throw new InternalErrorException('Failed to read assembled file.');
            }

            try {
                try {
                    $this->filesystem->writeStream($storagePath, $resource);
                } catch (\Throwable $e) {
                    throw new InternalErrorException('Failed to write assembled multipart object to Flysystem backend: ' . $e->getMessage(), $e);
                }
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return new StorageWriteResult(
            path: $storagePath,
            size: $size,
            md5Hex: $compositeMd5 . '-' . count($parts),
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        try {
            $this->filesystem->deleteDirectory(".parts/{$uploadId}");
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        try {
            if (! $this->filesystem->fileExists($srcPath)) {
                throw new NoSuchKeyException();
            }
        } catch (NoSuchKeyException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new NoSuchKeyException();
        }

        $storagePath = $this->objectPath($dstBucket, $dstKey);

        try {
            $this->filesystem->copy($srcPath, $storagePath);
        } catch (\Throwable $error) {
            try {
                $this->filesystem->delete($storagePath);
            } catch (\Throwable) {
            }

            throw new InternalErrorException('Failed to copy object in Flysystem backend: ' . $error->getMessage(), $error);
        }

        $calculator = new ChecksumCalculator();
        $size = 0;

        try {
            $copiedStream = $this->filesystem->readStream($storagePath);
            try {
                self::consumeResource($copiedStream, static function (string $chunk) use (
                    $calculator,
                    &$size,
                ): void {
                    $calculator->update($chunk);
                    $size += strlen($chunk);
                });
            } finally {
                fclose($copiedStream);
            }
        } catch (\Throwable $error) {
            try {
                $this->filesystem->delete($storagePath);
            } catch (\Throwable) {
            }

            throw new InternalErrorException('Failed to verify copied Flysystem object: ' . $error->getMessage(), $error);
        }

        $checksums = $calculator->finalize();

        return new StorageWriteResult(
            path: $storagePath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * Generate a content-addressed storage path matching FilesystemBackend layout.
     */
    private function objectPath(string $bucket, string $key): string
    {
        $hash = hash('sha256', $key);
        $uuid = bin2hex(random_bytes(16));

        return sprintf(
            '%s/%s/%s/%s',
            $bucket,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $uuid,
        );
    }

    /**
     * @param resource $resource
     */
    private static function skipBytes($resource, int $bytes): void
    {
        $metadata = stream_get_meta_data($resource);
        if ($metadata['seekable'] === true) {
            if (fseek($resource, $bytes) === 0) {
                return;
            }
        }

        $remaining = $bytes;
        while ($remaining > 0 && ! feof($resource)) {
            $chunk = fread($resource, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }

            $remaining -= strlen($chunk);
        }
    }

    private function createTempFile(string $prefix): string
    {
        $tmpFile = tempnam($this->tempDir, $prefix);
        if ($tmpFile === false) {
            throw new InternalErrorException("Failed to create temporary file in {$this->tempDir}.");
        }

        return $tmpFile;
    }

    /**
     * @param resource $resource
     */
    private static function writeAll($resource, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $written = fwrite($resource, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new InternalErrorException('Failed to write temporary object data.');
            }

            $offset += $written;
        }
    }

    /**
     * @param resource $resource
     * @param callable(string): void $consumer
     */
    private static function consumeResource($resource, callable $consumer): void
    {
        $emptySince = null;

        while (! feof($resource)) {
            $chunk = fread($resource, 65536);
            if ($chunk === false) {
                throw new \RuntimeException('Failed to read Flysystem stream.');
            }
            if ($chunk === '') {
                $emptySince ??= microtime(true);
                if (microtime(true) - $emptySince >= 30) {
                    throw new \RuntimeException('Flysystem stream made no progress for 30 seconds.');
                }

                usleep(1000);
                continue;
            }

            $emptySince = null;
            $consumer($chunk);
        }
    }

}
