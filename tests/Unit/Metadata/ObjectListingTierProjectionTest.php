<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectListingTierProjectionTest extends TestCase
{
    public function test_sqlite_listings_preserve_physical_tier_and_restore_state(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $this->assertObjectListingTier($store);
    }

    private function assertObjectListingTier(MetadataStore $store): void
    {
        self::assertInstanceOf(RestoredCopyTierStore::class, $store);
        $bucket = 'tier-list-' . bin2hex(random_bytes(6));
        $store->createBucket('owner', $bucket, 'us-east-1');
        $versionId = $store->putObjectVersioned($bucket, 'dir/item', 'owner', 4, 'etag', 'text/plain', 'path/original');
        $store->updateObjectPlacement($bucket, 'dir/item', $versionId, 'STANDARD', 'COLD', 'path/cold', 'failed', 'WARM', 'transition unavailable');
        $store->updateObjectRestoreStateWithTier($bucket, 'dir/item', $versionId, 'restored', 'path/restore', new \DateTimeImmutable('+1 day'), 'WARM');

        self::assertSame('COLD', $store->getObjectMetadataByVersion($bucket, 'dir/item', $versionId)?->storageTier);
        foreach ([null, '/'] as $delimiter) {
            $objects = $store->listObjects($bucket, prefix: 'dir/', delimiter: $delimiter)->objects;
            self::assertCount(1, $objects);
            self::assertSame('COLD', $objects[0]->storageTier);
            self::assertSame('failed', $objects[0]->transitionStatus);
            self::assertSame('WARM', $objects[0]->transitionTargetTier);
            self::assertSame('transition unavailable', $objects[0]->transitionError);
            self::assertSame('restored', $objects[0]->restoreStatus);
            self::assertSame('path/restore', $objects[0]->restoredStoragePath);
            self::assertSame('WARM', $objects[0]->restoredStorageTier);

            $versions = $store->listObjectVersions($bucket, prefix: 'dir/', delimiter: $delimiter)['versions'];
            self::assertCount(1, $versions);
            self::assertSame('COLD', $versions[0]->storageTier);
            self::assertSame('failed', $versions[0]->transitionStatus);
            self::assertSame('WARM', $versions[0]->transitionTargetTier);
            self::assertSame('transition unavailable', $versions[0]->transitionError);
            self::assertSame('restored', $versions[0]->restoreStatus);
            self::assertSame('path/restore', $versions[0]->restoredStoragePath);
            self::assertSame('WARM', $versions[0]->restoredStorageTier);
        }
    }
}
