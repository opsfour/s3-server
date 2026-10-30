<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

final class Crc64NvmeObjectMetadataCapability
{
    public static function require(MetadataStore $metadata): Crc64NvmeObjectMetadataStore
    {
        if (! $metadata instanceof Crc64NvmeObjectMetadataStore
            || ! $metadata->supportsCrc64NvmeObjectMetadata()) {
            throw new \LogicException('Metadata store does not support durable CRC64NVME object metadata.');
        }

        return $metadata;
    }
}
