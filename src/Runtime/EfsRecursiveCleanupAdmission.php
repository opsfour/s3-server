<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/** @internal Read-only SQL admission for one exact EFS candidate metadata graph. */
final class EfsRecursiveCleanupAdmission
{
    public static function assertReady(MetadataStore $metadata, StorageTierRegistry $tiers): void
    {
        if (! $metadata instanceof RecursiveCleanupAdmissionStore) {
            throw new \RuntimeException('EFS candidate metadata lacks recursive cleanup admission.');
        }

        foreach ($tiers->all() as $tier) {
            $metadata->assertNoUnsafeRecursiveCleanup($tier->name);
        }
    }
}
