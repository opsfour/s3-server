<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/** @internal Read-only metadata admission for the inert production EFS candidate. */
final class EfsCandidateMetadataStartupAdmission
{
    public static function assertReady(
        S3ServerConfig $config,
        MetadataStore $metadata,
        StorageTierRegistry $tiers,
    ): void {
        MetadataDriverStartupGuard::assertMatches($metadata, $config->metadataDriver);
        PostgresApiDriverGuard::assertAvailable($config);
        MetadataDriverStartupGuard::assertSchemaCurrent($metadata);

        foreach ($metadata->listReferencedStorageTiers() as $name) {
            if (! $tiers->has($name)) {
                throw new \RuntimeException('EFS candidate metadata references an unconfigured storage tier.');
            }
        }
    }
}
