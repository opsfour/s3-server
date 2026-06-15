<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\File;
use Amp\File\FilesystemException;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;

/**
 * Filesystem-backed storage implementation using amphp/file for async I/O.
 *
 * Storage layout:
 *   Objects: {basePath}/{bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
 *   Temp writes: {basePath}/.tmp/{uuid}
 *   Multipart parts: {basePath}/.parts/{uploadId}/{partNumber}
 *
 * Write strategy: data is first written to a temp file, then atomically
 * moved to the final path. This prevents partial reads on concurrent access.
 */
final class FilesystemBackend implements StorageBackend
{
    private readonly File\Filesystem $fileFilesystem;

    private readonly File\Filesystem $metadataFilesystem;

    /**
     * @param  string  $basePath  Root directory for all storage operations.
     *                            Must be an absolute path. Trailing slash is stripped.
     */
    public function __construct(
        private readonly string $basePath,
        ?File\Filesystem $fileFilesystem = null,
        ?File\Filesystem $metadataFilesystem = null,
    ) {
        $this->fileFilesystem = $fileFilesystem ?? File\filesystem();
        $this->metadataFilesystem = $metadataFilesystem ?? new File\Filesystem(File\createDefaultDriver());
    }

    /**
     * {@inheritDoc}
     *
     * Writes the body stream to a temp file, computing MD5 incrementally,
     * then atomically moves it to the final content-addressed path.
     */
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $tempPath = StoragePath::forTemp($this->basePath);
        $finalPath = StoragePath::forObject($this->basePath, $bucket, $key);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);

        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp file, computing checksums as data streams in
        $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');

        try {
            while (($chunk = $body->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                $tempFile->write($chunk);
            }

            self::closeAndRelease($tempFile);
        } catch (\Throwable $e) {
            self::closeAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write object: '.$e->getMessage(), $e);
        }

        $checksums = $calculator->finalize();

        // Create target directory and atomically move temp to final path
        $targetDir = dirname($finalPath);

        try {
            $this->ensureDirectory($targetDir);
            $this->atomicMove($tempPath, $finalPath);
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize object: '.$e->getMessage(), $e);
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
        if (! $this->metadataFilesystem->exists($storagePath)) {
            throw new NoSuchKeyException;
        }

        $file = $this->fileFilesystem->openFile($storagePath, 'r');

        try {
            if ($offset !== null && $offset > 0) {
                $file->seek($offset);
            }
        } catch (\Throwable $e) {
            $file->close();
            throw new InternalErrorException('Failed to seek in object file: ' . $e->getMessage(), $e);
        }

        if ($length !== null) {
            return new LimitedReadableStream($file, $length);
        }

        return $file;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        if (! $this->metadataFilesystem->exists($storagePath)) {
            return; // Idempotent: silently succeed if already gone
        }

        try {
            $this->metadataFilesystem->deleteFile($storagePath);
        } catch (FilesystemException) {
            // TOCTOU: file was deleted between exists() and deleteFile() — idempotent success.
            if ($this->metadataFilesystem->exists($storagePath)) {
                throw new InternalErrorException('Failed to delete object file: ' . $storagePath);
            }
            return;
        }

        // Clean up empty parent directories (hash/hash level)
        $bucketDir = $this->basePath.'/'.$bucket;
        $dir = dirname($storagePath);

        while ($dir !== $bucketDir && strlen($dir) > strlen($bucketDir)) {
            if (! $this->metadataFilesystem->isDirectory($dir)) {
                break;
            }

            $entries = $this->metadataFilesystem->listFiles($dir);

            if (count($entries) > 0) {
                break;
            }

            try {
                $this->metadataFilesystem->deleteDirectory($dir);
            } catch (FilesystemException) {
                break; // Concurrent write made directory non-empty — stop climbing.
            }
            $dir = dirname($dir);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function createBucket(string $bucket): void
    {
        $bucketDir = $this->basePath.'/'.$bucket;

        $this->ensureDirectory($bucketDir);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteBucket(string $bucket): void
    {
        $bucketDir = $this->basePath.'/'.$bucket;

        if ($this->metadataFilesystem->isDirectory($bucketDir)) {
            $this->metadataFilesystem->deleteDirectory($bucketDir);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function bucketExists(string $bucket): bool
    {
        return $this->metadataFilesystem->isDirectory($this->basePath.'/'.$bucket);
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
        $tempPath = StoragePath::forTemp($this->basePath);

        // Ensure directories exist
        $partDir = dirname($partPath);
        $tempDir = dirname($tempPath);

        $this->ensureDirectory($tempDir);
        $this->ensureDirectory($partDir);

        $calculator = new ChecksumCalculator();
        $size = 0;

        // Write to temp first
        $tempFile = $this->fileFilesystem->openFile($tempPath, 'w');

        try {
            while (($chunk = $data->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                $tempFile->write($chunk);
            }

            self::closeAndRelease($tempFile);
        } catch (\Throwable $e) {
            self::closeAndRelease($tempFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to write part: '.$e->getMessage(), $e);
        }

        $checksums = $calculator->finalize();

        // Move temp to final part path (overwrite if re-uploading same part number).
        // rename() atomically replaces the destination — no need to delete first.
        try {
            $this->atomicMove($tempPath, $partPath);
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize part: '.$e->getMessage(), $e);
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

    /**
     * Assemble all parts of a multipart upload into a single object file.
     *
     * Parts are concatenated in the order specified by the $parts array.
     * After assembly, the individual part files are cleaned up.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @param  string  $uploadId  Upload identifier.
     * @param  array<int, array{partNumber: int, etag: string}>  $parts  Ordered list of parts with their ETags.
     * @return StorageWriteResult Metadata about the assembled object.
     */
    public function assembleMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        array $parts,
    ): StorageWriteResult {
        $tempPath = StoragePath::forTemp($this->basePath);
        $finalPath = StoragePath::forObject($this->basePath, $bucket, $key);

        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);

        $calculator = new ChecksumCalculator();
        $size = 0;
        $partMd5s = '';

        // Concatenate all parts into the temp file
        $outFile = $this->fileFilesystem->openFile($tempPath, 'w');

        try {
            foreach ($parts as $part) {
                $partNumber = $part['partNumber'];
                $partPath = StoragePath::forPart($this->basePath, $uploadId, $partNumber);

                if (! $this->metadataFilesystem->exists($partPath)) {
                    throw new InternalErrorException("Part {$partNumber} not found for upload {$uploadId}.");
                }

                $partFile = $this->fileFilesystem->openFile($partPath, 'r');
                $partMd5Context = hash_init('md5');

                while (($chunk = $partFile->read()) !== null) {
                    $calculator->update($chunk);
                    hash_update($partMd5Context, $chunk);
                    $size += strlen($chunk);
                    $outFile->write($chunk);
                }

                self::closeAndRelease($partFile);

                // Collect raw MD5 bytes of each part for the multipart ETag
                $partMd5s .= hex2bin(hash_final($partMd5Context));
            }

            self::closeAndRelease($outFile);
        } catch (\Throwable $e) {
            self::closeAndRelease($outFile);
            $this->safeDelete($tempPath);

            if ($e instanceof InternalErrorException) {
                throw $e;
            }

            throw new InternalErrorException('Failed to assemble multipart upload: '.$e->getMessage(), $e);
        }

        $checksums = $calculator->finalize();

        // The multipart ETag is: MD5(concat(part_md5_bytes))-partCount
        $compositeMd5 = hash('md5', $partMd5s);

        // Move assembled file to final path
        $targetDir = dirname($finalPath);

        try {
            $this->ensureDirectory($targetDir);
            $this->atomicMove($tempPath, $finalPath);
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize assembled object: '.$e->getMessage(), $e);
        }

        // Clean up part files after successful assembly.
        $this->cleanupParts($uploadId);

        return new StorageWriteResult(
            path: $finalPath,
            size: $size,
            md5Hex: $compositeMd5.'-'.count($parts),
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
        $this->cleanupParts($uploadId);
    }

    /**
     * Copy an object from one storage path to a new location.
     *
     * The copy is performed via temp file + atomic move, same as putObject.
     * MD5 is computed on the copied data.
     *
     * @param  string  $srcPath  Absolute path of the source object.
     * @param  string  $dstBucket  Destination bucket name.
     * @param  string  $dstKey  Destination object key.
     * @return StorageWriteResult Metadata about the copied object.
     */
    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        if (! $this->metadataFilesystem->exists($srcPath)) {
            throw new NoSuchKeyException;
        }

        $tempPath = StoragePath::forTemp($this->basePath);
        $finalPath = StoragePath::forObject($this->basePath, $dstBucket, $dstKey);

        // Ensure temp directory
        $tempDir = dirname($tempPath);
        $this->ensureDirectory($tempDir);

        $calculator = new ChecksumCalculator();
        $size = 0;

        $srcFile = $this->fileFilesystem->openFile($srcPath, 'r');
        $dstFile = $this->fileFilesystem->openFile($tempPath, 'w');

        try {
            while (($chunk = $srcFile->read()) !== null) {
                $calculator->update($chunk);
                $size += strlen($chunk);
                $dstFile->write($chunk);
            }

            self::closeAndRelease($srcFile);
            self::closeAndRelease($dstFile);
        } catch (\Throwable $e) {
            self::closeAndRelease($srcFile);
            self::closeAndRelease($dstFile);
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to copy object: '.$e->getMessage(), $e);
        }

        $checksums = $calculator->finalize();

        // Move to final destination
        $targetDir = dirname($finalPath);

        try {
            $this->ensureDirectory($targetDir);
            $this->atomicMove($tempPath, $finalPath);
        } catch (\Throwable $e) {
            $this->safeDelete($tempPath);
            throw new InternalErrorException('Failed to finalize copied object: '.$e->getMessage(), $e);
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
     * Get the base path for this storage backend.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private function ensureDirectory(string $path): void
    {
        if ($this->metadataFilesystem->isDirectory($path)) {
            return;
        }

        try {
            $this->metadataFilesystem->createDirectoryRecursively($path, 0755);
        } catch (FilesystemException $e) {
            if ($this->metadataFilesystem->isDirectory($path)) {
                return;
            }

            throw new InternalErrorException('Failed to create directory: '.$path, $e);
        }
    }

    private function atomicMove(string $from, string $to): void
    {
        try {
            $this->metadataFilesystem->move($from, $to);
        } catch (FilesystemException $e) {
            throw new InternalErrorException('Failed to move file: '.$from.' -> '.$to, $e);
        }
    }

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

    /**
     * Delete all part files and the upload directory for a multipart upload.
     *
     * @param  string  $uploadId  The upload identifier.
     */
    private function cleanupParts(string $uploadId): void
    {
        $partsDir = $this->basePath.'/.parts/'.$uploadId;

        if (! $this->metadataFilesystem->isDirectory($partsDir)) {
            return;
        }

        $files = $this->metadataFilesystem->listFiles($partsDir);

        foreach ($files as $file) {
            $filePath = $partsDir.'/'.$file;

            try {
                $this->metadataFilesystem->deleteFile($filePath);
            } catch (FilesystemException) {
                // Best-effort cleanup
            }
        }

        try {
            $this->metadataFilesystem->deleteDirectory($partsDir);
        } catch (FilesystemException) {
            // Best-effort cleanup
        }
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
        $tmpDir = $this->basePath.'/.tmp';

        if (! $this->metadataFilesystem->isDirectory($tmpDir)) {
            return 0;
        }

        $cleaned = 0;
        $cutoff = time() - $maxAgeSeconds;

        foreach ($this->metadataFilesystem->listFiles($tmpDir) as $file) {
            $filePath = $tmpDir.'/'.$file;

            try {
                $stat = $this->metadataFilesystem->getStatus($filePath);
                if ($stat !== null && ($stat['mtime'] ?? 0) < $cutoff) {
                    $this->metadataFilesystem->deleteFile($filePath);
                    $cleaned++;
                }
            } catch (FilesystemException) {
                // Best-effort cleanup
            }
        }

        return $cleaned;
    }
}
