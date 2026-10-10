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
    /** Validate a bucket as one physical path segment at the backend boundary. */
    public static function assertBucketPathSegment(string $bucket): void
    {
        if ($bucket === '' || $bucket === '.' || $bucket === '..'
            || in_array($bucket, ['.parts', '.assemblies', '.tmp'], true)
            || str_contains($bucket, '/') || str_contains($bucket, '\\')
            || preg_match('/[\x00-\x1F\x7F:]/', $bucket)
        ) {
            throw new \InvalidArgumentException('Invalid bucket storage path segment.');
        }
    }

    /** Reject upload IDs that could escape or normalize away a part directory. */
    public static function assertSafeUploadIdSegment(string $uploadId): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._+=-]{0,254}$/D', $uploadId)) {
            throw new \InvalidArgumentException('Invalid multipart upload ID path segment.');
        }
    }

    public static function forPlannedPart(
        string $root,
        string $bucket,
        string $bucketGeneration,
        string $uploadId,
        int $partNumber,
        string $attemptId,
    ): string {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)
            || ! preg_match('/^[a-f0-9]{32}$/D', $bucketGeneration)
            || ! preg_match('/^[a-f0-9]{32}$/D', $uploadId)
            || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || $partNumber < 1 || $partNumber > 10000
        ) {
            throw new \InvalidArgumentException('Invalid planned multipart part path scope.');
        }

        return self::completionRoot($root) . '.parts/' . $uploadId . '/'
            . $partNumber . '-' . $bucketGeneration . '-' . $attemptId;
    }

    public static function assertPlannedPartPath(string $root, string $path, string $uploadId, int $partNumber): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $uploadId) || $partNumber < 1 || $partNumber > 10000) {
            throw new \InvalidArgumentException('Invalid planned multipart part path.');
        }

        $prefix = self::completionRoot($root) . '.parts/' . $uploadId . '/' . $partNumber . '-';
        if (! str_starts_with($path, $prefix)
            || ! preg_match('/^[a-f0-9]{32}-[a-f0-9]{32}$/D', substr($path, strlen($prefix)))) {
            throw new \InvalidArgumentException('Invalid planned multipart part path.');
        }
    }

    public static function forPlannedObject(
        string $root,
        string $bucket,
        string $key,
        string $bucketGeneration,
        string $attemptId,
    ): string {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)
            || ! preg_match('/^[a-f0-9]{32}$/D', $bucketGeneration)
            || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
        ) {
            throw new \InvalidArgumentException('Invalid planned object path scope.');
        }

        $hash = hash('sha256', $key);

        return self::completionRoot($root) . $bucket . '/' . substr($hash, 0, 2) . '/'
            . substr($hash, 2, 2) . '/' . $bucketGeneration . '-' . $attemptId;
    }

    public static function assertPlannedObjectPath(string $root, string $path, string $bucket, string $key): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)) {
            throw new \InvalidArgumentException('Invalid planned object path.');
        }

        $hash = hash('sha256', $key);
        $prefix = self::completionRoot($root) . $bucket . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/';
        if (! str_starts_with($path, $prefix)
            || ! preg_match('/^[a-f0-9]{32}-[a-f0-9]{32}$/D', substr($path, strlen($prefix)))) {
            throw new \InvalidArgumentException('Invalid planned object path.');
        }
    }

    /**
     * Validate a persisted object-attempt path when the original object key
     * is unavailable to a recovery collector. The two hash shards are
     * structural; the writer must separately verify the complete key hash.
     */
    public static function assertPlannedObjectIntentPath(
        string $root,
        string $path,
        string $bucket,
        string $bucketGeneration,
        string $attemptId,
    ): void {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)
            || ! preg_match('/^[a-f0-9]{32}$/D', $bucketGeneration)
            || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
            throw new \InvalidArgumentException('Invalid planned object intent path.');
        }

        $prefix = self::completionRoot($root) . $bucket . '/';
        $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
        if (! preg_match('~^[a-f0-9]{2}/[a-f0-9]{2}/' . $bucketGeneration . '-' . $attemptId . '$~D', $relative)) {
            throw new \InvalidArgumentException('Planned object intent path does not match its scope.');
        }
    }

    public static function assertObjectWritePath(string $root, string $path, string $bucket, string $key): void
    {
        $completionPrefix = self::completionRoot($root) . '.assemblies/';
        if (str_starts_with($path, $completionPrefix)) {
            self::assertMultipartCompletionPath($root, $path);
            $components = explode('/', substr($path, strlen($completionPrefix)));
            if ($components[0] !== $bucket || ! hash_equals(hash('sha256', $key), $components[2])) {
                throw new \InvalidArgumentException('Multipart completion path does not match the object scope.');
            }

            return;
        }

        self::assertPlannedObjectPath($root, $path, $bucket, $key);
    }
    /**
     * Planned completion paths are outside the bucket's recursively deleted
     * namespace and fenced by its immutable generation and an attempt ID.
     *
     * @return array{assembly: string, final: string}
     */
    public static function forMultipartCompletion(
        string $root,
        string $bucket,
        string $key,
        string $bucketGeneration,
        string $attemptId,
    ): array {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/D', $bucket)
            || ! preg_match('/^[a-f0-9]{32}$/D', $bucketGeneration)
            || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
        ) {
            throw new \InvalidArgumentException('Invalid multipart completion path scope.');
        }

        $prefix = self::completionRoot($root) . '.assemblies/' . $bucket . '/' . $bucketGeneration
            . '/' . hash('sha256', $key) . '/' . $attemptId;

        return ['assembly' => $prefix . '/assembly', 'final' => $prefix . '/final'];
    }

    public static function assertMultipartCompletionPath(string $root, string $path): void
    {
        $prefix = self::completionRoot($root) . '.assemblies/';
        $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
        if (! preg_match('~^[a-z0-9][a-z0-9.-]{1,62}/[a-f0-9]{32}/[a-f0-9]{64}/[a-f0-9]{32}/(?:assembly|final)$~D', $relative)) {
            throw new \InvalidArgumentException('Invalid planned multipart completion path.');
        }
    }

    private static function completionRoot(string $root): string
    {
        if ($root === '' || str_ends_with($root, '://')) {
            return $root;
        }

        return rtrim($root, '/') . '/';
    }

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
        self::assertBucketPathSegment($bucket);
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
        self::assertBucketPathSegment($bucket);
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
