<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Creates an upload with its physical storage tier atomically pinned. */
interface MultipartUploadTierStore
{
    /** @param array<string, string> $userMetadata */
    public function createMultipartUploadWithTier(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        string $storageTier,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void;
}
