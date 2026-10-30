<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

use OpsFour\S3Server\Exception\InternalErrorException;

final class StoredCrc64Nvme
{
    public static function requireCanonical(string $value): string
    {
        try {
            Crc64NvmeDigest::decodeBase64($value);
        } catch (\InvalidArgumentException) {
            throw new InternalErrorException('Stored CRC64NVME checksum is invalid.');
        }

        return $value;
    }
}
