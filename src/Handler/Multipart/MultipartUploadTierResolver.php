<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/** Resolve the immutable physical tier captured when a multipart upload was created. */
final class MultipartUploadTierResolver
{
    /** @param array<string, mixed> $upload */
    public static function resolve(array $upload, StorageTierRegistry $tiers): StorageTier
    {
        $name = $upload['storage_tier'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new InternalErrorException('Multipart upload has no verified physical storage tier.');
        }
        if (! $tiers->has($name)) {
            throw new InternalErrorException('Multipart upload storage tier is not configured.');
        }

        return $tiers->tier($name);
    }
}
