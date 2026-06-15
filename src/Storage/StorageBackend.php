<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/**
 * Abstraction over object data storage.
 *
 * All I/O is non-blocking via Amp streams. Implementations must
 * never buffer entire objects in memory.
 */
interface StorageBackend
{
    /**
     * Write an object to storage.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  ReadableStream  $body  The object data stream.
     * @return StorageWriteResult Metadata about the written object (path, size, md5).
     */
    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult;

    /**
     * Read an object from storage by its resolved storage path.
     *
     * @param  string  $storagePath  The absolute storage path (from metadata).
     * @param  int|null  $offset  Byte offset to start reading from.
     * @param  int|null  $length  Number of bytes to read, null for remainder.
     * @return ReadableStream The object data stream.
     */
    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream;

    /**
     * Delete an object from storage by its resolved storage path.
     *
     * @param  string  $storagePath  The absolute storage path (from metadata).
     * @param  string  $bucket  The bucket name (for directory cleanup boundary).
     */
    public function deleteObjectByPath(string $storagePath, string $bucket): void;

    /**
     * Create a bucket's storage directory/namespace.
     *
     * @param  string  $bucket  The bucket name.
     */
    public function createBucket(string $bucket): void;

    /**
     * Remove a bucket's storage directory/namespace.
     *
     * @param  string  $bucket  The bucket name.
     */
    public function deleteBucket(string $bucket): void;

    /**
     * Check whether a bucket's storage exists.
     *
     * @param  string  $bucket  The bucket name.
     * @return bool True if the bucket storage exists.
     */
    public function bucketExists(string $bucket): bool;

    // ---------------------------------------------------------------
    // Multipart upload operations
    // ---------------------------------------------------------------

    /**
     * Write a single part of a multipart upload.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key (for context).
     * @param  string  $uploadId  Multipart upload identifier.
     * @param  int  $partNumber  Part number (1-10000).
     * @param  ReadableStream  $data  Part data stream.
     * @return StorageWriteResult Metadata about the written part.
     */
    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult;

    /**
     * Assemble all parts of a multipart upload into a single object.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @param  string  $uploadId  Upload identifier.
     * @param  array<int, array{partNumber: int, etag: string}>  $parts  Ordered list of parts.
     * @return StorageWriteResult Metadata about the assembled object.
     */
    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult;

    /**
     * Abort a multipart upload by cleaning up all associated part data.
     *
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @param  string  $uploadId  Upload identifier whose parts to delete.
     */
    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void;

    /**
     * Copy an object from one storage path to a new location.
     *
     * @param  string  $srcPath  Absolute path of the source object.
     * @param  string  $dstBucket  Destination bucket name.
     * @param  string  $dstKey  Destination object key.
     * @return StorageWriteResult Metadata about the copied object.
     */
    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult;
}
