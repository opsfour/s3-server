<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/**
 * Writes a multipart completion only to destinations persisted before I/O.
 * The caller must retain both paths until a commit or confirmed cleanup.
 */
interface RecoverableMultipartAssemblyBackend extends StorageBackend
{
    /**
     * @return array{assembly: string, final: string}
     */
    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array;

    /**
     * @param array<int, array{partNumber: int, etag: string, storagePath: string}> $parts
     */
    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult;

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult;
}
