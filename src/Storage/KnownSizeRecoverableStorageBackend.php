<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/** Exact-size admission for writes to already persisted physical paths. */
interface KnownSizeRecoverableStorageBackend
{
    public function putObjectAtPathKnownSize(
        string $bucket,
        string $key,
        ReadableStream $body,
        string $path,
        int $knownSize,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult;

    public function putPartAtPathKnownSize(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
        string $path,
        int $knownSize,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult;
}
