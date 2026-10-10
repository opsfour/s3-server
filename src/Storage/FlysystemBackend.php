<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteDirectory;
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
final class FlysystemBackend implements RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend
{
    private readonly string $tempDir;

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        ?string $tempDir = null,
        private readonly ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->writeObjectAtPath($body, $this->objectPath($bucket, $key));
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return StoragePath::forMultipartCompletion('', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject('', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        StoragePath::assertObjectWritePath('', $path, $bucket, $key);

        return $this->writeObjectAtPath($body, $path);
    }

    private function writeObjectAtPath(ReadableStream $body, string $storagePath): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        [$tmpFile, $reservation] = $this->createBudgetedTempFile('s3put_', 's3fw-put-');
        $tmpStream = fopen($tmpFile, 'w+b');
        if ($tmpStream === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        try {
            while (($chunk = $body->read()) !== null) {
                $this->reserveTempBytes($reservation, strlen($chunk));
                $calculator->update($chunk);
                $size += strlen($chunk);
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            try {
                $this->filesystem->writeStream($storagePath, $tmpStream);
            } catch (\Throwable $e) {
                $this->deleteFailedWrite($storagePath);
                throw new InternalErrorException('Failed to write object to Flysystem backend.', $e);
            }
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
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
        FlysystemPathScope::assertStoredPath($storagePath);

        try {
            $resource = $this->filesystem->readStream($storagePath);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            try {
                if (! $this->filesystem->fileExists($storagePath)) {
                    throw new NoSuchKeyException();
                }
            } catch (NoSuchKeyException $notFound) {
                throw $notFound;
            } catch (CancelledException $cancelled) {
                throw $cancelled;
            } catch (\Throwable) {
                // A second backend failure cannot establish that the object is absent.
            }
            throw new InternalErrorException('Failed to read object from Flysystem backend.', $error);
        }

        if ($offset !== null && $offset > 0) {
            try {
                self::skipBytes($resource, $offset, $length !== null && $length > 0);
            } catch (\Throwable $error) {
                fclose($resource);
                throw $error;
            }
        }

        if ($length !== null) {
            return new LimitedReadableStream(new ReadableResourceStream($resource), $length);
        }

        return new ReadableResourceStream($resource);
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        FlysystemPathScope::assertDeletePath($storagePath, $bucket);

        try {
            $this->filesystem->delete($storagePath);
        } catch (UnableToDeleteFile $error) {
            // A remote adapter also uses this exception for permission or
            // transport failures. Only a confirmed missing file is success.
            if ($this->filesystem->fileExists($storagePath)) {
                throw new InternalErrorException('Failed to delete object from Flysystem backend.', $error);
            }
        }
    }

    public function createBucket(string $bucket): void
    {
        StoragePath::assertBucketPathSegment($bucket);

        $this->filesystem->createDirectory($bucket);
    }

    public function deleteBucket(string $bucket): void
    {
        throw new \LogicException('Name-only Flysystem bucket deletion is unsafe after bucket-name reuse.');
    }

    public function bucketExists(string $bucket): bool
    {
        StoragePath::assertBucketPathSegment($bucket);

        return $this->filesystem->directoryExists($bucket);
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $path = sprintf('.parts/%s/%d-%s', $uploadId, $partNumber, bin2hex(random_bytes(16)));

        return $this->writePartAtPath($data, $path);
    }

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return StoragePath::forPlannedPart('', $bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        StoragePath::assertPlannedPartPath('', $path, $uploadId, $partNumber);

        return $this->writePartAtPath($data, $path);
    }

    private function writePartAtPath(ReadableStream $data, string $partPath): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        [$tmpFile, $reservation] = $this->createBudgetedTempFile('s3part_', 's3fw-part-');
        $tmpStream = fopen($tmpFile, 'w+b');
        if ($tmpStream === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        try {
            while (($chunk = $data->read()) !== null) {
                $this->reserveTempBytes($reservation, strlen($chunk));
                $calculator->update($chunk);
                $size += strlen($chunk);
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            try {
                $this->filesystem->writeStream($partPath, $tmpStream);
            } catch (\Throwable $e) {
                $this->deleteFailedWrite($partPath);
                throw new InternalErrorException('Failed to write multipart part to Flysystem backend.', $e);
            }
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
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
        return $this->assembleAtPath($uploadId, $parts, $this->objectPath($bucket, $key));
    }

    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        StoragePath::assertMultipartCompletionPath('', $path);
        StoragePath::assertObjectWritePath('', $path, $bucket, $key);

        return $this->assembleAtPath($uploadId, $parts, $path);
    }

    /** @param array<int, array{partNumber: int, etag: string, storagePath: string}> $parts */
    private function assembleAtPath(string $uploadId, array $parts, string $storagePath): StorageWriteResult
    {
        [$tmpFile, $reservation] = $this->createBudgetedTempFile('s3mpu_', 's3fw-mpu-');

        $outHandle = fopen($tmpFile, 'w');
        if ($outHandle === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp file for multipart assembly.');
        }

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        try {
            foreach ($parts as $part) {
                $partNumber = $part['partNumber'];
                $partPath = $part['storagePath'];
                FlysystemPathScope::assertStoredPath($partPath);

                try {
                    $partStream = $this->filesystem->readStream($partPath);
                } catch (\Throwable) {
                    fclose($outHandle);
                    throw new InternalErrorException('Multipart part is unavailable in Flysystem backend.');
                }

                $partMd5Context = hash_init('md5');

                try {
                    self::consumeResource($partStream, function (string $chunk) use (
                        $calculator,
                        $partMd5Context,
                        $outHandle,
                        $reservation,
                        &$size,
                    ): void {
                        $this->reserveTempBytes($reservation, strlen($chunk));
                        $calculator->update($chunk);
                        hash_update($partMd5Context, $chunk);
                        $size += strlen($chunk);
                        self::writeAll($outHandle, $chunk);
                    });
                } finally {
                    fclose($partStream);
                }

                $partMd5 = hash_final($partMd5Context);
                if (strtolower(trim($part['etag'], '"')) !== $partMd5) {
                    throw new InternalErrorException('Multipart part failed physical checksum verification.');
                }

                $partMd5s .= hex2bin($partMd5);
            }

            fclose($outHandle);

            $checksums = $calculator->finalize();
            $compositeMd5 = hash('md5', $partMd5s);
            $resource = fopen($tmpFile, 'r');
            if ($resource === false) {
                throw new InternalErrorException('Failed to read assembled file.');
            }

            try {
                try {
                    $this->filesystem->writeStream($storagePath, $resource);
                } catch (\Throwable $e) {
                    $this->deleteFailedWrite($storagePath);
                    throw new InternalErrorException('Failed to write assembled multipart object to Flysystem backend.', $e);
                }
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        } finally {
            if (is_resource($outHandle)) {
                fclose($outHandle);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
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
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $path = ".parts/{$uploadId}";
        try {
            $this->filesystem->deleteDirectory($path);
        } catch (UnableToDeleteDirectory $error) {
            if ($this->filesystem->directoryExists($path)) {
                throw new InternalErrorException('Failed to delete multipart parts from Flysystem backend.', $error);
            }
        }
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($dstBucket);
        FlysystemPathScope::assertStoredPath($srcPath);

        try {
            if (! $this->filesystem->fileExists($srcPath)) {
                throw new NoSuchKeyException();
            }
        } catch (NoSuchKeyException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new InternalErrorException('Failed to check source object in Flysystem backend.', $error);
        }

        $storagePath = $this->objectPath($dstBucket, $dstKey);

        try {
            $this->filesystem->copy($srcPath, $storagePath);
        } catch (\Throwable $error) {
            try {
                $this->filesystem->delete($storagePath);
            } catch (\Throwable) {
            }

            throw new InternalErrorException('Failed to copy object in Flysystem backend.', $error);
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

            throw new InternalErrorException('Failed to verify copied Flysystem object.', $error);
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

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($dstBucket);

        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum copied bytes must be non-negative.');
        }

        $source = new LimitedReadableStream($this->getObjectByPath($srcPath), $maxBytes);
        try {
            return $this->putObject($dstBucket, $dstKey, $source);
        } finally {
            $source->close();
        }
    }

    /**
     * Generate a unique path sharded by key hash, matching FilesystemBackend layout.
     */
    private function objectPath(string $bucket, string $key): string
    {
        StoragePath::assertBucketPathSegment($bucket);

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

    /** A failed adapter write may already have stored bytes at its unique path. */
    private function deleteFailedWrite(string $path): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (\Throwable) {
            // Preserve the write failure; a cleanup outage must not replace it.
        }
    }

    /**
     * @param resource $resource
     */
    private static function skipBytes($resource, int $bytes, bool $requiresData): void
    {
        $metadata = stream_get_meta_data($resource);
        if ($metadata['seekable'] === true) {
            if (@fseek($resource, $bytes) === 0) {
                // A regular file allows seeking beyond EOF. Reject a range
                // offset past its known physical size before returning a
                // misleading empty stream to the response writer.
                if ($metadata['stream_type'] === 'STDIO') {
                    $stat = @fstat($resource);
                    if ($stat !== false && ($stat['mode'] & 0170000) === 0100000
                        && ($stat['size'] < $bytes || ($requiresData && $stat['size'] === $bytes))) {
                        throw new InternalErrorException('Flysystem stream ended before the requested range offset.');
                    }
                }

                return;
            }
        }

        $remaining = $bytes;
        $emptySince = null;
        while ($remaining > 0 && ! feof($resource)) {
            $chunk = fread($resource, min(65536, $remaining));
            if ($chunk === false) {
                throw new InternalErrorException('Failed to skip Flysystem stream bytes.');
            }
            if ($chunk === '') {
                $emptySince ??= microtime(true);
                if (microtime(true) - $emptySince >= 30) {
                    throw new InternalErrorException('Flysystem stream made no progress while skipping bytes.');
                }

                usleep(1000);
                continue;
            }

            $emptySince = null;
            $remaining -= strlen($chunk);
        }
        if ($remaining > 0 || ($requiresData && feof($resource))) {
            throw new InternalErrorException('Flysystem stream ended before the requested range offset.');
        }
    }

    private function createTempFile(string $prefix): string
    {
        $directory = realpath($this->tempDir);
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new InternalErrorException('Failed to create Flysystem temporary file.');
        }

        // tempnam() silently falls back to the system temp directory when the
        // configured directory becomes unavailable. Create exclusively at the
        // intended path so plaintext staging cannot leave that volume.
        $tmpFile = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
        $handle = @fopen($tmpFile, 'x+b');
        if ($handle === false) {
            throw new InternalErrorException('Failed to create Flysystem temporary file.');
        }

        if (!@chmod($tmpFile, 0600)) {
            fclose($handle);
            @unlink($tmpFile);
            throw new InternalErrorException('Failed to protect Flysystem temporary file.');
        }
        fclose($handle);

        return $tmpFile;
    }

    /** @return array{string, StorageTempReservation|null} */
    private function createBudgetedTempFile(string $directPrefix, string $workerPrefix): array
    {
        $path = $this->createTempFile($this->tempBudget === null ? $directPrefix : $workerPrefix);
        if ($this->tempBudget === null) {
            return [$path, null];
        }

        try {
            return [$path, $this->tempBudget->start($path)];
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    private function reserveTempBytes(?StorageTempReservation $reservation, int $bytes): void
    {
        if ($reservation !== null) {
            $this->tempBudget?->reserve($reservation, $bytes);
        }
    }

    private function releaseBudgetedTempFile(string $path, ?StorageTempReservation $reservation): void
    {
        @unlink($path);
        if ($reservation !== null) {
            $this->tempBudget?->release($reservation);
        }
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
