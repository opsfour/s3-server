<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Result of a storage write operation.
 */
final readonly class StorageWriteResult
{
    public function __construct(
        public string $path,
        public int $size,
        public string $md5Hex,
        public ?string $crc32Base64 = null,
        public ?string $crc32cBase64 = null,
        public ?string $sha1Base64 = null,
        public ?string $sha256Base64 = null,
    ) {}
}
