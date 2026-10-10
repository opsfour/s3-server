<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Persists a verified multipart part checksum atomically with the part row. */
interface MultipartPartChecksumStore
{
    public function supportsMultipartPartChecksums(): bool;

    public function putPartWithChecksum(
        string $uploadId,
        int $partNumber,
        string $etag,
        int $size,
        string $storagePath,
        ?string $checksumAlgorithm,
        ?string $checksumValue,
    ): void;
}
