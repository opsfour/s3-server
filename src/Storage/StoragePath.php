<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Computes deterministic storage paths for objects, temp files, and parts.
 *
 * Storage layout uses SHA-256 hash of the object key to distribute files
 * across a two-level directory hierarchy (first 2 bytes), avoiding
 * filesystem performance degradation from too many files in a single directory.
 */
final class StoragePath
{
    /**
     * Compute the storage path for an object.
     *
     * Layout: {basePath}/{bucket}/{hash[0:2]}/{hash[2:4]}/{uuid}
     *
     * @param  string  $basePath  Root storage directory.
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @return string Absolute path for the object file.
     */
    public static function forObject(string $basePath, string $bucket, string $key): string
    {
        $hash = hash('sha256', $key);
        $uuid = bin2hex(random_bytes(16));

        return sprintf(
            '%s/%s/%s/%s/%s',
            $basePath,
            $bucket,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $uuid,
        );
    }

    /**
     * Compute a temporary file path for write-ahead storage.
     *
     * Layout: {basePath}/.tmp/{uuid}
     *
     * @param  string  $basePath  Root storage directory.
     * @return string Absolute path for the temp file.
     */
    public static function forTemp(string $basePath): string
    {
        $uuid = bin2hex(random_bytes(16));

        return sprintf('%s/.tmp/%s', $basePath, $uuid);
    }

    /**
     * Compute the storage path for a multipart upload part.
     *
     * Layout: {basePath}/.parts/{uploadId}/{partNumber}-{uuid}
     *
     * @param  string  $basePath  Root storage directory.
     * @param  string  $uploadId  Multipart upload identifier.
     * @param  int  $partNumber  Part number (1-based).
     * @return string Absolute path for the part file.
     */
    public static function forPart(string $basePath, string $uploadId, int $partNumber): string
    {
        // Validate uploadId to prevent path traversal (must be hex characters only).
        if (!preg_match('/^[a-fA-F0-9\-]+$/', $uploadId)) {
            throw new \InvalidArgumentException('Invalid uploadId format.');
        }

        return sprintf(
            '%s/.parts/%s/%d-%s',
            $basePath,
            $uploadId,
            $partNumber,
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * Compute the directory that contains objects for a given bucket+key.
     *
     * Layout: {basePath}/{bucket}/{hash[0:2]}/{hash[2:4]}
     *
     * @param  string  $basePath  Root storage directory.
     * @param  string  $bucket  Bucket name.
     * @param  string  $key  Object key.
     * @return string Absolute path for the object's parent directory.
     */
    public static function directoryForObject(string $basePath, string $bucket, string $key): string
    {
        $hash = hash('sha256', $key);

        return sprintf(
            '%s/%s/%s/%s',
            $basePath,
            $bucket,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
        );
    }

    /** Prevent instantiation. */
    private function __construct() {}
}
