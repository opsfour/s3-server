<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional capability for removing a stored object retention configuration. */
interface ObjectRetentionDeletionStore
{
    public function deleteObjectRetention(string $bucket, string $key, ?string $versionId = null): void;
}
