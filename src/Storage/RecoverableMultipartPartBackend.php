<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/**
 * Optional storage capability for durable, preplanned multipart part writes.
 *
 * A successful putPartAtPath() return means every worker write to that exact
 * path has settled. After deleteObjectByPath() returns and getObjectByPath()
 * reports NoSuchKey, that absence must be authoritative. The handler relies
 * on these guarantees to retire a failed attempt's durable intent; an
 * uncertain write result keeps the intent for later garbage collection.
 */
interface RecoverableMultipartPartBackend extends StorageBackend
{
    public function planMultipartPartPath(
        string $bucket,
        string $bucketGeneration,
        string $uploadId,
        int $partNumber,
        string $attemptId,
    ): string;

    public function putPartAtPath(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
        string $path,
    ): StorageWriteResult;
}
