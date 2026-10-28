<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmissionCapability;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Reject remote storage without a verified provider write fence and volatile
 * object storage beside persistent metadata. Linux filesystem mounts are
 * admitted only when mountinfo identifies a local filesystem. This does not
 * prove power-loss durability or qualify a multi-host mount. Generic Flysystem
 * is not evidence of a provider write fence.
 */
final class StorageWriteFenceStartupGuard
{
    /**
     * @return list<string> Tiers admitted through the unsafe test-only override.
     */
    public static function assertReady(StorageTierRegistry $tiers, bool $allowUnfencedStorageForTests, string $metadataDriver, ?MetadataStore $metadata = null): array
    {
        $unsafeTiers = [];
        foreach ($tiers->all() as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }

            if ($backend instanceof AwsS3ProviderAdmissionCapability) {
                $admission = $backend->awsS3ProviderAdmission();
                if ($metadataDriver !== 'postgres' || ! $backend instanceof \OpsFour\S3Server\Storage\AwsS3StorageBackend
                    || $backend->operationalState() === null || $metadata === null) {
                    throw new AwsS3ProviderStartupException($admission);
                }
                $binding = $backend->validatedBinding();
                if ($binding === null) {
                    throw new AwsS3ProviderStartupException($admission);
                }
                $backend->operationalState()->assertQualifiedExecutionBinding($binding, $tier->name, $metadata);
                $backend->operationalState()->assertStartupReady();
                continue;
            }

            if ($backend instanceof FilesystemBackend) {
                if (PHP_OS_FAMILY === 'Linux') {
                    $root = realpath($backend->getBasePath());
                    $mountInfo = @file_get_contents('/proc/self/mountinfo');
                    $type = $root === false || $mountInfo === false
                        ? null : LinuxMountInfo::filesystemTypeForPath($root, $mountInfo);
                    $colocatedVolatileSqlite = $metadataDriver === 'sqlite' && $root !== false
                        && in_array($type, ['tmpfs', 'ramfs'], true)
                        && MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata($metadata, $root);
                    if (! $colocatedVolatileSqlite && ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type))) {
                        if (! $allowUnfencedStorageForTests) {
                            throw new \RuntimeException(sprintf(
                                'Refusing to start: filesystem storage tier %s has an unqualified Linux mount (%s). '
                                . 'NFS/EFS, SMB/CIFS, FUSE, unknown mounts, and unreadable mountinfo require an explicit unsafe test-only override.',
                                $tier->name,
                                $type ?? 'unknown',
                            ));
                        }
                        $unsafeTiers[] = $tier->name;
                    }
                }
                continue;
            }

            if ($backend instanceof InMemoryBackend) {
                if ($metadataDriver === 'sqlite' && MetadataDriverStartupGuard::hasVolatileSqliteMetadata($metadata)) {
                    continue;
                }
                if (! $allowUnfencedStorageForTests) {
                    throw new \RuntimeException(sprintf(
                        'Refusing to start: storage tier %s uses ephemeral in-memory object storage with %s metadata. '
                        . 'A restart or another API node would retain metadata without its object bytes. '
                        . 'The unsafe test-only storage override must never be used in production.',
                        $tier->name,
                        $metadataDriver,
                    ));
                }

                $unsafeTiers[] = $tier->name;

                continue;
            }

            if (! $allowUnfencedStorageForTests) {
                throw new \RuntimeException(sprintf(
                    'Refusing to start: storage tier %s uses %s without a verified late-write fence. '
                    . 'The unsafe test-only storage override must never be used in production.',
                    $tier->name,
                    $backend::class,
                ));
            }

            $unsafeTiers[] = $tier->name;
        }

        return $unsafeTiers;
    }
}
