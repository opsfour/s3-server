<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemException;
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
final class FlysystemBackend implements ShutdownAwareStorageBackend, RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend, KnownSizeStorageBackend, KnownSizeRecoverableStorageBackend
{
    private string $tempDir;

    private ?StorageTempBudgetCoordinator $tempBudget;

    private bool $ownsTempBudget;

    /** @var array<string,array{device:int,inode:int,owner:int}> */
    private array $tempIdentities = [];

    public function __construct(
        private readonly FilesystemOperator $filesystem,
        ?string $tempDir = null,
        ?StorageTempBudgetCoordinator $tempBudget = null,
        bool $ownsTempBudget = false,
    ) {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
        $this->tempBudget = $tempBudget;
        $this->ownsTempBudget = $ownsTempBudget;
    }

    /** Bind compatibility instances to the canonical process-wide node budget. */
    public function bindTempBudget(StorageTempBudgetCoordinator $tempBudget): void
    {
        if ($this->tempBudget !== null) {
            if ($this->tempBudget->tempDir() !== $tempBudget->tempDir()
                || $this->tempBudget->budgetBytes() !== $tempBudget->budgetBytes()
                || $this->tempBudget->filesystemReserveBytes() !== $tempBudget->filesystemReserveBytes()) {
                throw new \RuntimeException('Flysystem backend temporary capacity differs from the node contract.');
            }
            if ($this->tempBudget !== $tempBudget) {
                if ($this->ownsTempBudget) {
                    $this->tempBudget->close();
                }
                $this->tempBudget = $tempBudget;
                $this->ownsTempBudget = false;
                $this->tempDir = $tempBudget->tempDir();
            }
            return;
        }
        $this->tempDir = $tempBudget->tempDir();
        $this->tempBudget = $tempBudget;
    }

    public function shutdown(): void
    {
        if ($this->ownsTempBudget) {
            $this->tempBudget?->close();
            $this->ownsTempBudget = false;
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        return $this->writeObjectAtPath($body, $this->objectPath($bucket, $key));
    }

    public function putObjectKnownSize(string $bucket, string $key, ReadableStream $body, int $knownSize, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        return $this->writeObjectAtPath($body, $this->objectPath($bucket, $key), $knownSize, $capacity);
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

    public function putObjectAtPathKnownSize(string $bucket, string $key, ReadableStream $body, string $path, int $knownSize, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        StoragePath::assertObjectWritePath('', $path, $bucket, $key);

        return $this->writeObjectAtPath($body, $path, $knownSize, $capacity);
    }

    private function writeObjectAtPath(ReadableStream $body, string $storagePath, ?int $knownMaximumBytes = null, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        [$tmpFile, $reservation] = $this->createBudgetedTempFile('s3put_', 's3fw-put-', $capacity);
        $tmpStream = $this->openBudgetedTempStream($tmpFile, $reservation, 'w+b');
        if ($tmpStream === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        $cancellation = null;
        try {
            if ($knownMaximumBytes !== null && $capacity === null) {
                $this->reserveTempBytes($reservation, $knownMaximumBytes);
            }
            $reservedBytes = $knownMaximumBytes ?? 0;
            while (($chunk = $body->read()) !== null) {
                $nextSize = $size + strlen($chunk);
                if ($nextSize > $reservedBytes) {
                    $this->reserveTempBytes($reservation, $nextSize - $reservedBytes);
                    $reservedBytes = $nextSize;
                }
                $calculator->update($chunk);
                $size = $nextSize;
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            try {
                $this->filesystem->writeStream($storagePath, $tmpStream);
            } catch (CancelledException $e) {
                $this->deleteFailedWrite($storagePath, $e);
                throw $e;
            } catch (\Throwable $e) {
                $this->deleteFailedWrite($storagePath);
                throw new InternalErrorException('Failed to write object to Flysystem backend.', $e);
            }
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation, $cancellation);
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

    public function putPartKnownSize(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, int $knownSize, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $path = sprintf('.parts/%s/%d-%s', $uploadId, $partNumber, bin2hex(random_bytes(16)));

        return $this->writePartAtPath($data, $path, $knownSize, $capacity);
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

    public function putPartAtPathKnownSize(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path, int $knownSize, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        StoragePath::assertPlannedPartPath('', $path, $uploadId, $partNumber);

        return $this->writePartAtPath($data, $path, $knownSize, $capacity);
    }

    private function writePartAtPath(ReadableStream $data, string $partPath, ?int $knownSize = null, ?StorageTempReservation $capacity = null): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        [$tmpFile, $reservation] = $this->createBudgetedTempFile('s3part_', 's3fw-part-', $capacity);
        $tmpStream = $this->openBudgetedTempStream($tmpFile, $reservation, 'w+b');
        if ($tmpStream === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp stream.');
        }

        $cancellation = null;
        try {
            if ($knownSize !== null && $capacity === null) {
                if ($knownSize < 0) {
                    throw new \InvalidArgumentException('Known multipart part size must be non-negative.');
                }
                $this->reserveTempBytes($reservation, $knownSize);
            }
            $reservedBytes = $knownSize ?? 0;
            while (($chunk = $data->read()) !== null) {
                $nextSize = $size + strlen($chunk);
                if ($nextSize > $reservedBytes) {
                    $this->reserveTempBytes($reservation, $nextSize - $reservedBytes);
                    $reservedBytes = $nextSize;
                }
                $calculator->update($chunk);
                $size = $nextSize;
                self::writeAll($tmpStream, $chunk);
            }

            rewind($tmpStream);

            $checksums = $calculator->finalize();
            try {
                $this->filesystem->writeStream($partPath, $tmpStream);
            } catch (CancelledException $e) {
                $this->deleteFailedWrite($partPath, $e);
                throw $e;
            } catch (\Throwable $e) {
                $this->deleteFailedWrite($partPath);
                throw new InternalErrorException('Failed to write multipart part to Flysystem backend.', $e);
            }
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation, $cancellation);
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

        $outHandle = $this->openBudgetedTempStream($tmpFile, $reservation, 'w');
        if ($outHandle === false) {
            $this->releaseBudgetedTempFile($tmpFile, $reservation);
            throw new InternalErrorException('Failed to open temp file for multipart assembly.');
        }

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        $cancellation = null;
        try {
            $knownBytes = 0;
            $knownPartSizes = [];
            foreach ($parts as $index => $part) {
                $partSize = $part['size'] ?? $this->filesystem->fileSize($part['storagePath']);
                if (! is_int($partSize) || $partSize < 0 || $partSize > PHP_INT_MAX - $knownBytes) {
                    throw new InternalErrorException('Multipart assembly size exceeds node accounting range.');
                }
                $knownBytes += $partSize;
                $knownPartSizes[$index] = $partSize;
            }
            $this->reserveTempBytes($reservation, $knownBytes);
            foreach ($parts as $index => $part) {
                $partNumber = $part['partNumber'];
                $partPath = $part['storagePath'];
                FlysystemPathScope::assertStoredPath($partPath);
                $remainingPartBytes = $knownPartSizes[$index]
                    ?? throw new \LogicException('Multipart part size admission disappeared.');

                try {
                    $partStream = $this->filesystem->readStream($partPath);
                } catch (CancelledException $error) {
                    throw $error;
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
                        &$size,
                        &$remainingPartBytes,
                    ): void {
                        $chunkBytes = strlen($chunk);
                        if ($chunkBytes > $remainingPartBytes) {
                            throw new InternalErrorException('Multipart part exceeded its admitted size.');
                        }
                        $calculator->update($chunk);
                        hash_update($partMd5Context, $chunk);
                        $size += $chunkBytes;
                        $remainingPartBytes -= $chunkBytes;
                        self::writeAll($outHandle, $chunk);
                    });
                } finally {
                    fclose($partStream);
                }
                if ($remainingPartBytes !== 0) {
                    throw new InternalErrorException('Multipart part was shorter than its admitted size.');
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
            $resource = $this->openBudgetedTempStream($tmpFile, $reservation, 'rb');
            if ($resource === false) {
                throw new InternalErrorException('Failed to read assembled file.');
            }

            try {
                try {
                    $this->filesystem->writeStream($storagePath, $resource);
                } catch (CancelledException $e) {
                    $this->deleteFailedWrite($storagePath, $e);
                    throw $e;
                } catch (\Throwable $e) {
                    $this->deleteFailedWrite($storagePath);
                    throw new InternalErrorException('Failed to write assembled multipart object to Flysystem backend.', $e);
                }
            } finally {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            if (is_resource($outHandle)) {
                fclose($outHandle);
            }
            $this->releaseBudgetedTempFile($tmpFile, $reservation, $cancellation);
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
        $prefix = $path . '/';
        try {
            foreach ($this->filesystem->listContents($path, false) as $entry) {
                $entryPath = $entry->path();
                $name = str_starts_with($entryPath, $prefix) ? substr($entryPath, strlen($prefix)) : '';
                if ($entry->isDir() || $name === '' || str_contains($name, '/')) {
                    throw new InternalErrorException('Unexpected multipart directory blocks legacy cleanup.');
                }
                // Generation-bound attempts and permanent seals carry the
                // bucket generation plus one unique attempt token. A legacy
                // directory-abort job cannot prove ownership of those paths.
                if (preg_match('/^(?:[1-9][0-9]{0,3}|10000)-[a-f0-9]{32}-[a-f0-9]{32}$/D', $name) === 1) {
                    continue;
                }
                // Old Flysystem parts used either the part number alone or a
                // part number followed by one random token. Unknown entries
                // keep the durable cleanup job retryable.
                if (preg_match('/^(?:[1-9][0-9]{0,3}|10000)(?:-[a-f0-9]{32})?$/D', $name) !== 1) {
                    throw new InternalErrorException('Unexpected multipart path blocks legacy cleanup.');
                }

                try {
                    $this->filesystem->delete($entryPath);
                } catch (UnableToDeleteFile $error) {
                    if ($this->filesystem->fileExists($entryPath)) {
                        throw new InternalErrorException('Failed to delete multipart part from Flysystem backend.', $error);
                    }
                }
            }
        } catch (CancelledException $error) {
            throw $error;
        } catch (InternalErrorException $error) {
            throw $error;
        } catch (\Throwable $error) {
            try {
                if (! $this->filesystem->directoryExists($path)) {
                    return;
                }
            } catch (CancelledException $existenceError) {
                throw $existenceError;
            } catch (\Throwable $existenceError) {
                throw new InternalErrorException('Failed to inspect multipart parts in Flysystem backend.', $existenceError);
            }
            throw new InternalErrorException('Failed to list multipart parts from Flysystem backend.', $error);
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
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new InternalErrorException('Failed to check source object in Flysystem backend.', $error);
        }

        $storagePath = $this->objectPath($dstBucket, $dstKey);

        try {
            $this->filesystem->copy($srcPath, $storagePath);
        } catch (CancelledException $error) {
            $this->deleteFailedWrite($storagePath, $error);
            throw $error;
        } catch (\Throwable $error) {
            $this->deleteFailedWrite($storagePath);
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
        } catch (CancelledException $error) {
            $this->deleteFailedWrite($storagePath, $error);
            throw $error;
        } catch (\Throwable $error) {
            $this->deleteFailedWrite($storagePath);
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
        $knownCopiedBytes = null;
        try {
            $knownCopiedBytes = min($maxBytes, $this->filesystem->fileSize($srcPath));
        } catch (FilesystemException) {
            // Metadata is an optimization only. The bounded stream remains
            // authoritative when an adapter cannot provide a stable size.
        }
        try {
            return $this->writeObjectAtPath(
                $source,
                $this->objectPath($dstBucket, $dstKey),
                $knownCopiedBytes,
            );
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
    private function deleteFailedWrite(string $path, ?CancelledException $cancellation = null): void
    {
        try {
            $this->filesystem->delete($path);
        } catch (CancelledException $cleanupCancellation) {
            if ($cancellation === null) {
                throw $cleanupCancellation;
            }
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
        $opened = @fstat($handle);
        if ($opened === false || ($opened['mode'] & 0o170000) !== 0o100000
            || ($opened['mode'] & 0o077) !== 0) {
            fclose($handle);
            @unlink($tmpFile);
            throw new InternalErrorException('Failed to identify Flysystem temporary file.');
        }
        $this->tempIdentities[$tmpFile] = [
            'device' => $opened['dev'],
            'inode' => $opened['ino'],
            'owner' => $opened['uid'],
        ];
        fclose($handle);

        return $tmpFile;
    }

    /** @return array{string, StorageTempReservation|null} */
    private function createBudgetedTempFile(string $directPrefix, string $workerPrefix, ?StorageTempReservation $capacity = null): array
    {
        if ($capacity !== null && $this->tempBudget === null) {
            throw new \LogicException('Pre-reserved capacity requires the canonical temporary coordinator.');
        }
        if ($this->tempBudget === null) {
            $path = $this->createTempFile($directPrefix);
            return [$path, null];
        }

        $directory = realpath($this->tempDir);
        if ($directory === false || ! is_dir($directory) || ! is_writable($directory)) {
            throw new InternalErrorException('Failed to allocate Flysystem temporary path.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . $workerPrefix . bin2hex(random_bytes(16));

        if ($capacity !== null) {
            $this->tempBudget->retarget($capacity, $path, 'storage');
            $this->tempIdentities[$path] = $capacity->identity();
            return [$path, $capacity];
        }

        $reservation = $this->tempBudget->start($path);
        $this->tempIdentities[$path] = $reservation->identity();

        return [$path, $reservation];
    }

    private function reserveTempBytes(?StorageTempReservation $reservation, int $bytes): void
    {
        if ($reservation !== null) {
            $this->tempBudget?->reserve($reservation, $bytes);
        }
    }

    /** @return resource|false */
    private function openBudgetedTempStream(
        string $path,
        ?StorageTempReservation $reservation,
        string $mode,
    ): mixed {
        $truncate = str_starts_with($mode, 'w');
        $identity = $this->tempIdentities[$path] ?? null;
        $created = @lstat($path);
        $stream = @fopen($path, $truncate ? 'r+b' : $mode);
        if ($stream === false) {
            return $stream;
        }
        $opened = @fstat($stream);
        if ($created === false || $opened === false || $identity === null
            || ! isset($created['mode'], $created['dev'], $created['ino'], $created['uid'], $opened['mode'], $opened['dev'], $opened['ino'], $opened['uid'])
            || ($created['mode'] & 0o170000) !== 0o100000
            || ($created['mode'] & 0o077) !== 0
            || ($opened['mode'] & 0o170000) !== 0o100000
            || ($opened['mode'] & 0o077) !== 0
            || $created['dev'] !== $identity['device'] || $created['ino'] !== $identity['inode'] || $created['uid'] !== $identity['owner']
            || $opened['dev'] !== $identity['device'] || $opened['ino'] !== $identity['inode'] || $opened['uid'] !== $identity['owner']
            || ! @flock($stream, LOCK_SH | LOCK_NB)) {
            @fclose($stream);
            $this->releaseBudgetedTempFile($path, $reservation);
            throw new InternalErrorException('Flysystem temporary file changed before writing.');
        }
        if ($truncate && ! @ftruncate($stream, 0)) {
            @fclose($stream);
            $this->releaseBudgetedTempFile($path, $reservation);
            throw new InternalErrorException('Failed to truncate Flysystem temporary file.');
        }

        return $stream;
    }

    private function releaseBudgetedTempFile(
        string $path,
        ?StorageTempReservation $reservation,
        ?CancelledException $cancellation = null,
    ): void {
        try {
            if ($reservation === null) {
                $identity = $this->tempIdentities[$path] ?? null;
                clearstatcache(true, $path);
                $entry = @lstat($path);
                if ($identity !== null && $entry !== false
                    && ($entry['mode'] & 0o170000) === 0o100000
                    && $entry['dev'] === $identity['device']
                    && $entry['ino'] === $identity['inode']
                    && $entry['uid'] === $identity['owner']) {
                    @unlink($path);
                }
            } else {
                $this->tempBudget?->cleanup($reservation);
            }
        } catch (\Throwable $releaseError) {
            if ($cancellation === null) {
                throw $releaseError;
            }
        } finally {
            unset($this->tempIdentities[$path]);
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
