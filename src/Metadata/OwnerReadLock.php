<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

final class OwnerReadLock
{
    public static function prepare(MetadataStore $metadata, string ...$ownerIds): void
    {
        if (! $metadata instanceof SharedAuthorizationLockStore) {
            return;
        }

        foreach (self::normalize(array_values($ownerIds)) as $ownerId) {
            $metadata->prepareOwnerReadLock($ownerId);
        }
    }

    public static function acquire(MetadataStore $metadata, string ...$ownerIds): void
    {
        $shared = $metadata instanceof SharedAuthorizationLockStore;
        foreach (self::normalize(array_values($ownerIds)) as $ownerId) {
            if ($shared) {
                $metadata->lockOwnerForRead($ownerId);
            } else {
                $metadata->lockOwnerForUpdate($ownerId);
            }
        }
    }

    /**
     * @param  list<string>  $ownerIds
     * @return list<string>
     */
    private static function normalize(array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_filter(
            $ownerIds,
            static fn(string $ownerId): bool => $ownerId !== '',
        )));
        sort($ownerIds, SORT_STRING);

        return $ownerIds;
    }
}
