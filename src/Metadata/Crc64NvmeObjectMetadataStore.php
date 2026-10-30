<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Attests that object and version writes durably preserve CRC64NVME metadata. */
interface Crc64NvmeObjectMetadataStore
{
    public function supportsCrc64NvmeObjectMetadata(): bool;
}
