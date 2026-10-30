<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** One immutable logical plaintext part of a completed object. */
final readonly class ObjectPart
{
    public function __construct(
        public int $ordinal,
        public ?int $uploadPartNumber,
        public int $offset,
        public int $size,
        public ?string $checksumAlgorithm = null,
        public ?string $checksumValue = null,
    ) {
        if ($ordinal < 1 || $ordinal > 10_000) {
            throw new \InvalidArgumentException('Object part ordinal must be between 1 and 10000.');
        }
        if ($uploadPartNumber !== null && ($uploadPartNumber < 1 || $uploadPartNumber > 10_000)) {
            throw new \InvalidArgumentException('Upload part number must be between 1 and 10000.');
        }
        if ($offset < 0 || $size < 0) {
            throw new \InvalidArgumentException('Object part offsets and sizes must be non-negative.');
        }
        if (($checksumAlgorithm === null) !== ($checksumValue === null)) {
            throw new \InvalidArgumentException('Part checksum algorithm and value must be present together.');
        }
        if ($checksumAlgorithm !== null && ! in_array(strtolower($checksumAlgorithm), ['crc32', 'crc32c', 'crc64nvme', 'sha1', 'sha256'], true)) {
            throw new \InvalidArgumentException('Unsupported object part checksum algorithm.');
        }
        if ($checksumValue === '') {
            throw new \InvalidArgumentException('Object part checksum value must not be empty.');
        }
    }

    /** @return array{ordinal: int, uploadPartNumber: ?int, offset: int, size: int, checksumAlgorithm: ?string, checksumValue: ?string} */
    public function canonical(): array
    {
        return [
            'ordinal' => $this->ordinal,
            'uploadPartNumber' => $this->uploadPartNumber,
            'offset' => $this->offset,
            'size' => $this->size,
            'checksumAlgorithm' => $this->checksumAlgorithm,
            'checksumValue' => $this->checksumValue,
        ];
    }
}
