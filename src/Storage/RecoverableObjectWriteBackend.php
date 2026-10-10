<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/**
 * Optional capability for ordinary object paths persisted before physical I/O.
 * The caller must retain the planned exact path until publication or cleanup.
 * An uncertain write result cannot prove that a worker will not publish later.
 */
interface RecoverableObjectWriteBackend extends StorageBackend
{
    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string;

    /** Implementations that also support multipart completion accept its planned paths. */
    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult;
}
