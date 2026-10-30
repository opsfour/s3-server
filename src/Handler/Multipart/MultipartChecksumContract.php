<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NotImplementedException;

/** Immutable checksum contract stored with a multipart upload. */
final readonly class MultipartChecksumContract
{
    private function __construct(
        public string $algorithm,
        public string $type,
    ) {}

    public static function fromInitiation(?string $algorithm, ?string $type): ?self
    {
        if ($algorithm === null) {
            if ($type !== null) {
                throw new InvalidRequestException('A multipart checksum type requires a checksum algorithm.');
            }

            return null;
        }
        if (strtoupper($algorithm) !== 'CRC64NVME') {
            throw new NotImplementedException('This multipart checksum algorithm is not supported.');
        }
        if ($type !== null && strtoupper($type) !== 'FULL_OBJECT') {
            throw new InvalidRequestException('CRC64NVME multipart uploads require FULL_OBJECT checksum type.');
        }

        return new self('crc64nvme', 'FULL_OBJECT');
    }

    /** @param array<string, string> $metadata */
    public static function fromStored(array $metadata): ?self
    {
        $algorithm = $metadata['__mpu-checksum-algorithm'] ?? null;
        $type = $metadata['__mpu-checksum-type'] ?? null;
        if ($algorithm === null && $type === null) {
            return null;
        }
        if ($algorithm !== 'crc64nvme' || $type !== 'FULL_OBJECT') {
            throw new InternalErrorException('Stored multipart checksum contract is invalid.');
        }

        return new self($algorithm, $type);
    }

    public function sameAs(?self $other): bool
    {
        return $other !== null
            && $this->algorithm === $other->algorithm
            && $this->type === $other->type;
    }
}
