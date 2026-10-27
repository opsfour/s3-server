<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

/** Exact plaintext slice and response metadata for one selected logical part. */
final readonly class ObjectPartSelection
{
    public function __construct(
        public int $offset,
        public int $size,
        public int $objectSize,
        public ?int $multipartPartCount,
        public ?string $checksumAlgorithm,
        public ?string $checksumValue,
    ) {}

    public function contentRange(): string
    {
        return sprintf(
            'bytes %d-%d/%d',
            $this->offset,
            $this->offset + $this->size - 1,
            $this->objectSize,
        );
    }
}
