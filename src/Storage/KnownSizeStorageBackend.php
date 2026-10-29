<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;

/** Optional capability that admits exact temporary bytes before reading input. */
interface KnownSizeStorageBackend
{
    public function putObjectKnownSize(
        string $bucket,
        string $key,
        ReadableStream $body,
        int $knownSize,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult;

    public function putPartKnownSize(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
        int $knownSize,
        ?StorageTempReservation $capacity = null,
    ): StorageWriteResult;
}
