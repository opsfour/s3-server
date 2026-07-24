<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

final class OwnerWriteLock
{
    public static function acquire(MetadataStore $metadata, string ...$ownerIds): void
    {
        $ownerIds = array_values(array_unique(array_filter(
            $ownerIds,
            static fn(string $ownerId): bool => $ownerId !== '',
        )));
        sort($ownerIds, SORT_STRING);

        foreach ($ownerIds as $ownerId) {
            $metadata->lockOwnerForUpdate($ownerId);
        }
    }
}
