<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;

/** Resolve the physical tier recorded with a restored object copy. */
final class RestoredCopyTierResolver
{
    public static function resolve(ObjectInfo $object, StorageTierRegistry $tiers): StorageTier
    {
        if ($object->restoredStoragePath === null || $object->restoredStoragePath === '') {
            throw new InternalErrorException('Restored object copy has no storage path.');
        }

        $name = $object->restoredStorageTier;
        if ($name === null || $name === '' || ! $tiers->has($name)) {
            throw new InternalErrorException('Restored object copy has no configured physical storage tier.');
        }

        return $tiers->tier($name);
    }
}
