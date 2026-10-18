<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\File\FilesystemException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\Parallel\Worker\TaskFailureException;
use OpsFour\S3Server\Parallel\LocalMultipartPublishTask;
use OpsFour\S3Server\Parallel\LocalMultipartAssemblyTask;
use OpsFour\S3Server\Parallel\LocalMultipartSealTask;
use OpsFour\S3Server\Parallel\LocalDurableFilesystemTask;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;

/**
 * Filesystem-backed storage implementation using amphp/file for async I/O.
 *
 * Storage layout:
 *   Objects: {basePath}/{bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
 *   Temp writes: {basePath}/.tmp/{uuid}
 *   Multipart parts: {basePath}/.parts/{uploadId}/{partNumber}
 *
 * Write strategy: data is first written to a temp file, synchronized, and
 * linked create-only to its final path. This prevents partial reads and
 * replacement of an already published path.
 */
final class FilesystemBackend implements ShutdownAwareStorageBackend, RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, BoundedCopyStorageBackend
{
    private const int IO_CHUNK_SIZE = 1_048_576;

    private readonly string $basePath;

    private readonly File\Filesystem $fileFilesystem;

    private readonly File\Filesystem $metadataFilesystem;

    /** @var list<ContextWorkerPool> */
    private array $ownedWorkerPools = [];

    private ?ContextWorkerPool $multipartFencePool = null;

    private ?ContextWorkerPool $durabilityPool = null;

    /** IPC uncertainty retains a slot until backend shutdown. */
    private int $multipartFenceAdmissions = 0;

    /**
     * @param  string  $basePath  Root directory for all storage operations.
     *                            Must be an absolute path. Trailing slash is stripped.
     */
    public function __construct(
        string $basePath,
        ?File\Filesystem $fileFilesystem = null,
        ?File\Filesystem $metadataFilesystem = null,
    ) {
        $root = rtrim($basePath, '/');
        if ($root === '' || ! str_starts_with($root, '/') || str_contains($basePath, '//')) {
            throw new \InvalidArgumentException('Filesystem storage root must be an absolute directory below /.');
        }
        $this->basePath = $root;
        if ($fileFilesystem === null) {
            $dataPool = new ContextWorkerPool(8);
            $this->ownedWorkerPools[] = $dataPool;
            $fileFilesystem = new File\Filesystem(new ParallelFilesystemDriver($dataPool));
        }
        $this->fileFilesystem = $fileFilesystem;

        if ($metadataFilesystem === null) {
            $metadataPool = new ContextWorkerPool(8);
            $this->ownedWorkerPools[] = $metadataPool;
            $metadataFilesystem = new File\Filesystem(new ParallelFilesystemDriver($metadataPool));
        }
        $this->metadataFilesystem = $metadataFilesystem;
    }

    public function shutdown(): void
    {
        foreach ($this->ownedWorkerPools as $pool) {
            $pool->shutdown();
        }
        $this->ownedWorkerPools = [];
        $this->multipartFencePool = null;
        $this->durabilityPool = null;
        $this->multipartFenceAdmissions = 0;
    }

    public function __destruct()
    {
        try {
            $this->shutdown();
        } catch (\Throwable) {
            // Process shutdown remains best-effort if the event loop is gone.
        }
    }

    /**
     * {@inheritDoc}
     *
     * Writes the body stream to a temp file, computing MD5 incrementally,
     * then durably publishes it to a unique path sharded by the key hash.
     */
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {

        return $this->writeObjectAtPath($body, StoragePath::forObject($this->basePath, $bucket, $key));
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return StoragePath::forMultipartCompletion($this->basePath, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject($this->basePath, $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        StoragePath::assertObjectWritePath($this->basePath, $path, $bucket, $key);

        return $this->writeObjectAtPath(
            $body,
            $path,
            str_starts_with($path, rtrim($this->basePath, '/') . '/.assemblies/'),
        );
    }

    private function writeObjectAtPath(ReadableStream $body, string $finalPath, bool $fencedMultipart = false): StorageWriteResult
    {
        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        try {
            return $this->writeLeasedObjectAtPath($body, $finalPath, $tempPath, $fencedMultipart);
        } finally {
            $tempLease->release();
        }
    }

    private function writeLeasedObjectAtPath(ReadableStream $body, string $finalPath, string $tempPath, bool $fencedMultipart): StorageWriteResult
    {
        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp file, computing checksums as data streams in
        $tempFile = null;
        try {
            $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($body, $tempFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($tempFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write object to filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Create the target directory and durably publish the staged file.
        $targetDir = dirname($finalPath);

        $durablePublicationStarted = false;
        try {
            $this->ensureDirectory($targetDir);
            if ($fencedMultipart) {
                $this->publishFencedMultipartPath($tempPath, $finalPath);
            } else {
                $durablePublicationStarted = true;
                $this->publishDurably($tempPath, $finalPath);
            }
        } catch (CancelledException $e) {
            if (! $durablePublicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw $e;
        } catch (\Throwable $e) {
            if (! $durablePublicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw new InternalErrorException('Failed to finalize object in filesystem backend.', $e);
        }

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $checksums->md5Hex,
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        $storagePath = $this->storedPathForAccess($storagePath);
        if (! $this->assertStoredPathConfined($storagePath)) {
            throw new NoSuchKeyException();
        }

        $file = $this->fileFilesystem->openFile($storagePath, 'r');

        try {
            if ($offset !== null && $offset > 0) {
                $file->seek($offset);
            }
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($file);
            throw $e;
        } catch (\Throwable $e) {
            $file->close();
            throw new InternalErrorException('Failed to seek in object file.', $e);
        }

        $readStream = new FileReadableStream($file, self::IO_CHUNK_SIZE);

        if ($length !== null) {
            return new LimitedReadableStream($readStream, $length);
        }

        return $readStream;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $storagePath = $this->storedPathForAccess($storagePath);
        FlysystemPathScope::assertDeletePath(substr($storagePath, strlen($this->basePath) + 1), $bucket);

        try {
            $this->runDurabilityTask(LocalDurableFilesystemTask::delete($this->basePath, $storagePath));
        } catch (CancelledException $e) {
            throw $e;
        } catch (TaskFailureException|TaskFailureError $e) {
            if ($e->getOriginalClassName() === \InvalidArgumentException::class) {
                throw new \InvalidArgumentException($e->getOriginalMessage(), previous: $e);
            }
            throw new InternalErrorException('Failed to delete object file.', $e);
        } catch (\Throwable $e) {
            throw new InternalErrorException('Failed to delete object file.', $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function createBucket(string $bucket): void
    {
        StoragePath::assertBucketPathSegment($bucket);
        $bucketDir = $this->basePath . '/' . $bucket;

        $this->ensureDirectory($bucketDir);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteBucket(string $bucket): void
    {
        throw new \LogicException('Name-only filesystem bucket deletion is unsafe after bucket-name reuse.');
    }

    /**
     * {@inheritDoc}
     */
    public function bucketExists(string $bucket): bool
    {
        StoragePath::assertBucketPathSegment($bucket);
        return $this->metadataFilesystem->isDirectory($this->basePath . '/' . $bucket);
    }

    // ---------------------------------------------------------------
    // Multipart upload operations (not yet in the interface)
    // ---------------------------------------------------------------

    /**
     * Write a single part of a multipart upload to disk.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key (for logging/context only).
     * @param  string  $uploadId  Multipart upload identifier.
     * @param  int  $partNumber  Part number (1-10000).
     * @param  ReadableStream  $data  Part data stream.
     * @return StorageWriteResult Metadata about the written part.
     */
    public function putPart(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
    ): StorageWriteResult {
        $partPath = StoragePath::forPart($this->basePath, $uploadId, $partNumber);

        return $this->writePartAtPath($bucket, $key, $uploadId, $partNumber, $data, $partPath);
    }

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return StoragePath::forPlannedPart($this->basePath, $bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        StoragePath::assertPlannedPartPath($this->basePath, $path, $uploadId, $partNumber);

        return $this->writePartAtPath($bucket, $key, $uploadId, $partNumber, $data, $path, true);
    }

    private function writePartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $partPath, bool $planned = false): StorageWriteResult
    {
        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure directories exist
        $partDir = dirname($partPath);
        $tempDir = dirname($tempPath);

        $this->ensureDirectory($tempDir);
        $this->ensureDirectory($partDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp first
        $tempFile = null;
        try {
            $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($data, $tempFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($tempFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write multipart part to filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Every attempt receives a unique path. Metadata atomically selects the
        // winning attempt, so a failed replacement cannot destroy the previous part.
        try {
            if ($planned) {
                $this->publishFencedMultipartPath($tempPath, $partPath);
            } else {
                $this->publishDurably($tempPath, $partPath);
            }
        } catch (CancelledException $e) {
            if ($planned) {
                $this->safeDelete($tempPath);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($planned) {
                $this->safeDelete($tempPath);
            }
            throw new InternalErrorException('Failed to finalize multipart part in filesystem backend.', $e);
        }

        $tempLease->release();

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

    /**
     * Assemble all parts of a multipart upload into a single object file.
     *
     * Parts are concatenated in the order specified by the $parts array.
     * After assembly, the individual part files are cleaned up.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @param  string  $uploadId  Upload identifier.
     * @param  array<int, array{partNumber: int, etag: string, storagePath: string}>  $parts  Ordered committed parts.
     * @return StorageWriteResult Metadata about the assembled object.
     */
    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {

        return $this->assembleAtPath(
            $uploadId,
            $parts,
            StoragePath::forObject($this->basePath, $bucket, $key),
            false,
        );
    }

    public function assembleMultipartUploadAtPath(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
        string $path,
    ): StorageWriteResult {
        $this->assertCompletionPath($path);
        StoragePath::assertObjectWritePath($this->basePath, $path, $bucket, $key);

        return $this->assembleAtPath($uploadId, $parts, $path, true);
    }

    /**
     * @internal The caller must first durably arm this exact completion
     * attempt. An IPC failure is ambiguous: retain the SQL intent for sealing.
     *
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     */
    public function assembleMultipartUploadFencedAtPath(
        string $bucket,
        string $key,
        string $generation,
        string $attemptId,
        string $uploadId,
        array $parts,
        string $path,
    ): StorageWriteResult {
        $planned = StoragePath::forMultipartCompletion($this->basePath, $bucket, $key, $generation, $attemptId);
        if ($path !== $planned['assembly']) {
            throw new \InvalidArgumentException('Fenced multipart assembly path differs from its armed attempt.');
        }
        // Keep the SQL-selected paths untouched for the handler's exact-part
        // transaction checks; only the worker's physical paths are canonical.
        foreach ($parts as $index => $part) {
            $parts[$index]['storagePath'] = $this->storedPathForAccess($part['storagePath']);
        }
        $result = $this->runMultipartFenceTask(fn(): StorageWriteResult => $this->multipartFencePool()->submit(
            new LocalMultipartAssemblyTask(
                $this->basePath,
                $bucket,
                $key,
                $generation,
                $attemptId,
                $uploadId,
                $parts,
                $path,
            ),
        )->getFuture()->await());

        return $result;
    }

    /** @param array<int, array{partNumber: int, etag: string, storagePath: string}> $parts */
    private function assembleAtPath(string $uploadId, array $parts, string $finalPath, bool $fenced): StorageWriteResult
    {
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $partPrefix = rtrim($this->basePath, '/') . '/.parts/' . $uploadId . '/';
        foreach ($parts as $index => $part) {
            $partPath = $this->storedPathForAccess($part['storagePath']);
            if (! str_starts_with($partPath, $partPrefix)
                || ! preg_match('/^' . $part['partNumber'] . '-[a-f0-9]{32}(?:-[a-f0-9]{32})?$/D', substr($partPath, strlen($partPrefix)))) {
                throw new \InvalidArgumentException('Multipart part path does not match the upload.');
            }

            if (! $this->assertStoredPathConfined($partPath)) {
                throw new InternalErrorException('Multipart part is unavailable in filesystem backend.');
            }
            $parts[$index]['storagePath'] = $partPath;
        }

        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        // Concatenate all parts into the temp file
        $outFile = null;
        try {
            $outFile = $this->fileFilesystem->openFile($tempPath, 'w');
            foreach ($parts as $part) {
                $partNumber = $part['partNumber'];
                $partPath = $part['storagePath'];

                if (! $this->metadataFilesystem->exists($partPath)) {
                    throw new InternalErrorException('Multipart part is unavailable in filesystem backend.');
                }

                $partFile = $this->fileFilesystem->openFile($partPath, 'r');
                $readStream = new FileReadableStream($partFile, self::IO_CHUNK_SIZE);
                $partMd5Context = hash_init('md5');

                $this->copyStreamToFile($readStream, $outFile, static function (string $chunk) use (
                    $calculator,
                    $partMd5Context,
                    &$size,
                ): void {
                    $calculator->update($chunk);
                    hash_update($partMd5Context, $chunk);
                    $size += strlen($chunk);
                });

                self::closeAndRelease($partFile);

                // Collect raw MD5 bytes of each part for the multipart ETag
                $partMd5 = hash_final($partMd5Context);
                if (strtolower(trim($part['etag'], '"')) !== $partMd5) {
                    throw new InternalErrorException('Multipart part failed physical checksum verification.');
                }

                $partMd5s .= hex2bin($partMd5);
            }

            self::closeAndRelease($outFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($outFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($outFile);
            $this->safeDelete($tempPath);

            if ($e instanceof InternalErrorException) {
                throw $e;
            }

            throw new InternalErrorException('Failed to assemble multipart upload in filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // The multipart ETag is: MD5(concat(part_md5_bytes))-partCount
        $compositeMd5 = hash('md5', $partMd5s);

        // Move assembled file to final path
        $targetDir = dirname($finalPath);

        $durablePublicationStarted = false;
        try {
            $this->ensureDirectory($targetDir);
            if ($fenced) {
                $this->publishFencedMultipartPath($tempPath, $finalPath);
            } else {
                $durablePublicationStarted = true;
                $this->publishDurably($tempPath, $finalPath);
            }
        } catch (CancelledException $e) {
            if (! $durablePublicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw $e;
        } catch (\Throwable $e) {
            if (! $durablePublicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw new InternalErrorException('Failed to finalize assembled object in filesystem backend.', $e);
        }

        $tempLease->release();

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $compositeMd5 . '-' . count($parts),
            crc32Base64: $checksums->crc32Base64(),
            crc32cBase64: $checksums->crc32cBase64(),
            sha1Base64: $checksums->sha1Base64(),
            sha256Base64: $checksums->sha256Base64(),
        );
    }

    /**
     * Abort a multipart upload by deleting all associated part files.
     *
     * @param  string  $bucket  Bucket name (for context only).
     * @param  string  $key  Object key (for context only).
     * @param  string  $uploadId  Upload identifier whose parts to delete.
     */
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $this->cleanupParts($uploadId);
    }

    /**
     * Copy an object from one storage path to a new location.
     *
     * The copy is staged and durably published, same as putObject.
     * MD5 is computed on the copied data.
     *
     * @param  string  $srcPath  Absolute path of the source object.
     * @param  string  $dstBucket  Destination bucket name.
     * @param  string  $dstKey  Destination object key.
     * @return StorageWriteResult Metadata about the copied object.
     */
    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        $srcPath = $this->storedPathForAccess($srcPath);
        if (! $this->assertStoredPathConfined($srcPath)) {
            throw new NoSuchKeyException();
        }

        $tempPath = StoragePath::forTemp($this->basePath);
        $finalPath = StoragePath::forObject($this->basePath, $dstBucket, $dstKey);

        // Ensure temp directory
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);
        $tempLease = StorageTempFileLease::acquire($tempPath);

        $calculator = new ChecksumCalculator();
        $size = 0;

        $srcFile = null;
        $dstFile = null;
        try {
            $srcFile = $this->fileFilesystem->openFile($srcPath, 'r');
            $readStream = new FileReadableStream($srcFile, self::IO_CHUNK_SIZE);
            $dstFile = $this->fileFilesystem->openFile($tempPath, 'w');
            $this->copyStreamToFile($readStream, $dstFile, static function (string $chunk) use ($calculator, &$size): void {
                $calculator->update($chunk);
                $size += strlen($chunk);
            });

            self::closeAndRelease($srcFile);
            self::closeAndRelease($dstFile);
            $this->assertTempFileSize($tempPath, $size);
        } catch (CancelledException $e) {
            self::safeCloseAndRelease($srcFile);
            self::safeCloseAndRelease($dstFile);
            $this->safeDelete($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            self::safeCloseAndRelease($srcFile);
            self::safeCloseAndRelease($dstFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to copy object in filesystem backend.', $e);
        }

        $checksums = $calculator->finalize();

        // Move to final destination
        $targetDir = dirname($finalPath);

        $publicationStarted = false;
        try {
            $this->ensureDirectory($targetDir);
            $publicationStarted = true;
            $this->publishDurably($tempPath, $finalPath);
        } catch (CancelledException $e) {
            if (! $publicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw $e;
        } catch (\Throwable $e) {
            if (! $publicationStarted) {
                $this->safeDelete($tempPath);
            }
            throw new InternalErrorException('Failed to finalize copied object in filesystem backend.', $e);
        }

        $tempLease->release();

        return new StorageWriteResult(
            path: $finalPath,
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
     * Get the base path for this storage backend.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    /**
     * @param callable(string): void $onChunk
     */
    private function copyStreamToFile(ReadableStream $source, File\File $target, callable $onChunk): void
    {
        $buffer = '';

        while (($chunk = $source->read()) !== null) {
            $onChunk($chunk);
            $buffer .= $chunk;

            if (strlen($buffer) >= self::IO_CHUNK_SIZE) {
                $target->write($buffer);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $target->write($buffer);
        }
    }

    private function assertTempFileSize(string $path, int $expectedSize): void
    {
        // Amp File::write() returns void, and some drivers can accept a short
        // local write without throwing. A fresh stat avoids cached file sizes.
        clearstatcache(true, $path);
        if (@filesize($path) !== $expectedSize) {
            throw new InternalErrorException('Filesystem staging file is incomplete.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        try {
            $this->runDurabilityTask(LocalDurableFilesystemTask::ensureDirectory($this->basePath, $path));
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InternalErrorException('Failed to create filesystem storage directory.', $e);
        }
    }

    private function publishDurably(string $from, string $to): void
    {
        try {
            $this->runDurabilityTask(LocalDurableFilesystemTask::publish($this->basePath, $from, $to));
        } catch (CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InternalErrorException('Failed to publish filesystem storage file.', $e);
        }
    }

    private function runDurabilityTask(LocalDurableFilesystemTask $task): void
    {
        $this->durabilityPool()->submit($task)->getFuture()->await();
    }

    private function durabilityPool(): ContextWorkerPool
    {
        if ($this->durabilityPool === null) {
            $this->durabilityPool = new ContextWorkerPool(2);
            $this->ownedWorkerPools[] = $this->durabilityPool;
        }

        return $this->durabilityPool;
    }

    /**
     * Publish only after the caller has committed a one-shot multipart SQL
     * dispatch. A worker may finish after an ambiguous response, so callers
     * must retain the intent and let the collector seal the exact path.
     */
    private function publishFencedMultipartPath(string $tempPath, string $targetPath): void
    {
        $this->runMultipartFenceTask(fn(): null => $this->multipartFencePool()->submit(new LocalMultipartPublishTask(
            $this->basePath,
            $tempPath,
            $targetPath,
        ))->getFuture()->await());
    }

    /** Physical half of a committed multipart collector claim. */
    public function sealFencedMultipartPath(string $path, string $attemptId): void
    {
        $this->runMultipartFenceTask(fn(): null => $this->multipartFencePool()->submit(new LocalMultipartSealTask(
            $this->basePath,
            $path,
            $attemptId,
        ))->getFuture()->await());
    }

    /**
     * Reserve before submitting. A failed submission or lost IPC response may
     * leave a child alive. Collector retries after restart handle retained slots.
     *
     * @template TResult
     * @param \Closure(): TResult $submit
     * @return TResult
     */
    private function runMultipartFenceTask(\Closure $submit): mixed
    {
        if ($this->multipartFenceAdmissions >= 2) {
            throw new SlowDownException('Local multipart fence worker capacity is exhausted.');
        }
        $this->multipartFenceAdmissions++;
        try {
            $result = $submit();
        } catch (TaskFailureException|TaskFailureError $error) {
            $this->multipartFenceAdmissions--;
            throw $error;
        }
        $this->multipartFenceAdmissions--;

        return $result;
    }

    private function multipartFencePool(): ContextWorkerPool
    {
        if ($this->multipartFencePool === null) {
            $this->multipartFencePool = new ContextWorkerPool(2);
            $this->ownedWorkerPools[] = $this->multipartFencePool;
        }

        return $this->multipartFencePool;
    }

    /** @param-out null $file */
    private static function closeAndRelease(?\Amp\File\File &$file): void
    {
        if ($file === null) {
            return;
        }

        try {
            $file->close();
        } finally {
            // ParallelFilesystemDriver releases its reserved worker when the
            // ParallelFile object is destroyed, not merely when close() returns.
            $file = null;
        }
    }

    /** @param-out null $file */
    private static function safeCloseAndRelease(?\Amp\File\File &$file): void
    {
        try {
            self::closeAndRelease($file);
        } catch (\Throwable) {
            // Cleanup must never replace the exception that triggered it.
        } finally {
            $file = null;
        }
    }

    /**
     * Safely delete a file, swallowing any exceptions.
     *
     * Used for cleanup in error paths where we must not mask the original exception.
     */
    private function safeDelete(string $path): void
    {
        try {
            if ($this->metadataFilesystem->exists($path)) {
                $this->metadataFilesystem->deleteFile($path);
            }
        } catch (\Throwable) {
            // Intentionally swallowed: cleanup failure should not mask the original error.
        }
    }

    /** Delete only legacy part files; fenced attempts and seals remain reserved. */
    private function cleanupParts(string $uploadId): void
    {
        $partsDir = $this->basePath . '/.parts/' . $uploadId;

        if (! $this->metadataFilesystem->isDirectory($partsDir)) {
            return;
        }

        $files = $this->metadataFilesystem->listFiles($partsDir);

        foreach ($files as $file) {
            $filePath = $partsDir . '/' . $file;
            if ($this->metadataFilesystem->isDirectory($filePath)) {
                throw new InternalErrorException('Unexpected multipart directory blocks legacy cleanup.');
            }
            // Planned attempts and permanent seals use two 32-byte tokens.
            // Keep their names occupied so a late create-only link fails.
            if (preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}-[a-f0-9]{32}$/D', $file) === 1) {
                continue;
            }
            // Old backend paths had either a numeric name or one random
            // token. Unknown debris must keep the queue job pending.
            if (! ctype_digit($file)
                && preg_match('/^[1-9][0-9]{0,4}-[a-f0-9]{32}$/D', $file) !== 1) {
                throw new InternalErrorException('Unexpected multipart path blocks legacy cleanup.');
            }

            try {
                $this->runDurabilityTask(LocalDurableFilesystemTask::delete($this->basePath, $filePath));
            } catch (CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new InternalErrorException('Failed to delete multipart part from filesystem backend.', $e);
            }
        }

        // Never remove this directory here. A worker can link a planned path
        // or a collector can install its seal after the listing above.
    }

    /**
     * Clean up stale temp files that may remain after client disconnects.
     *
     * Scans the `.tmp` directory and deletes files older than `$maxAgeSeconds`.
     *
     * @return int Number of files cleaned up.
     */
    public function cleanupStaleTempFiles(int $maxAgeSeconds = 3600): int
    {
        $tmpDir = $this->basePath . '/.tmp';

        if (! $this->metadataFilesystem->isDirectory($tmpDir)) {
            return 0;
        }

        $cleaned = 0;
        $cutoff = time() - $maxAgeSeconds;

        foreach ($this->metadataFilesystem->listFiles($tmpDir) as $file) {
            $filePath = $tmpDir . '/' . $file;
            $handle = @fopen($filePath, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                // Writers hold a shared lock until the temp file is moved.
                // A crashed writer releases it, even on a different process.
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $stat = fstat($handle);
                if ($stat !== false && ($stat['mtime'] ?? 0) < $cutoff) {
                    try {
                        $this->runDurabilityTask(LocalDurableFilesystemTask::delete($this->basePath, $filePath));
                        $cleaned++;
                    } catch (CancelledException $e) {
                        throw $e;
                    } catch (\Throwable) {
                        // Do not count uncertain cleanup as successful.
                    }
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return $cleaned;
    }

    private function assertCompletionPath(string $path): void
    {
        StoragePath::assertMultipartCompletionPath($this->basePath, $path);
    }

    /** Preserve legacy SQL spellings while accessing their one physical path. */
    private function storedPathForAccess(string $path): string
    {
        $prefix = $this->basePath . '/';
        if (! str_starts_with($path, $prefix)) {
            throw new \InvalidArgumentException('Stored object path is outside the storage namespace.');
        }
        $relative = substr($path, strlen($prefix));
        // Older configurations ending in "/" persisted one extra separator
        // directly after the root. No other separator normalization is safe.
        if (str_starts_with($relative, '/')) {
            $relative = substr($relative, 1);
        }
        FlysystemPathScope::assertStoredPath($relative);

        return $prefix . $relative;
    }

    private function assertStoredPathConfined(string $path): bool
    {
        clearstatcache(true, $this->basePath);
        clearstatcache(true, $path);
        $root = realpath($this->basePath);
        if ($root === false) {
            throw new InternalErrorException('Filesystem storage root is unavailable.');
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            if ($this->metadataFilesystem->exists($path)) {
                throw new \InvalidArgumentException('Stored object path cannot be resolved safely.');
            }

            return false;
        }

        $prefix = rtrim($root, '/') . '/';
        if (! str_starts_with($resolved, $prefix)) {
            throw new \InvalidArgumentException('Stored object path escapes the storage root.');
        }

        return true;
    }
}
