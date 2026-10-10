<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use OpsFour\S3Server\Metadata\MetadataStore;

/** Check a rolled-back callback before deleting its unique physical part path. */
final class FailedPartCommitCleanup
{
    public static function shouldDelete(
        bool $callbackCompleted,
        MetadataStore $metadata,
        string $uploadId,
        int $partNumber,
        string $path,
    ): bool {
        return ! $callbackCompleted && self::isUnreferenced($metadata, $uploadId, $partNumber, $path);
    }

    public static function isUnreferenced(
        MetadataStore $metadata,
        string $uploadId,
        int $partNumber,
        string $path,
    ): bool {
        try {
            foreach ($metadata->getParts($uploadId) as $part) {
                if ($part['part_number'] === $partNumber && $part['storage_path'] === $path) {
                    return false;
                }
            }
        } catch (\Throwable) {
            // The commit may have succeeded. Preserve a possible orphan rather
            // than deleting bytes that committed metadata still references.
            return false;
        }

        return true;
    }
}
