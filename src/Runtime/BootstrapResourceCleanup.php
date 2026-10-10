<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/** Releases command-owned resources that were not transferred to a runtime. */
final class BootstrapResourceCleanup
{
    public static function release(?MetadataStore $metadata, ?StorageTierRegistry $tiers): void
    {
        self::releaseMetadata($metadata);
        self::releaseStorage($tiers);
    }

    private static function releaseMetadata(?MetadataStore $metadata): void
    {
        while ($metadata !== null) {
            if ($metadata instanceof ParallelSqliteMetadataStore) {
                try {
                    $metadata->shutdown();
                } catch (\Throwable) {
                }

                return;
            }
            if (! method_exists($metadata, 'innerStore')) {
                return;
            }
            $inner = $metadata->innerStore();
            if (! $inner instanceof MetadataStore || $inner === $metadata) {
                return;
            }
            $metadata = $inner;
        }
    }

    private static function releaseStorage(?StorageTierRegistry $tiers): void
    {
        $closed = [];
        foreach ($tiers?->all() ?? [] as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof ShutdownAwareStorageBackend) {
                continue;
            }
            $id = spl_object_id($backend);
            if (isset($closed[$id])) {
                continue;
            }
            $closed[$id] = true;
            try {
                $backend->shutdown();
            } catch (\Throwable) {
            }
        }
    }
}
