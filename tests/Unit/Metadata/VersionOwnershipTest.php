<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class VersionOwnershipTest extends TestCase
{
    public function test_versions_and_markers_round_trip_stored_owners_and_accounting(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', 'Enabled');

        $first = $metadata->putObjectVersioned('bucket', 'key', 'owner-a', 3, 'first', 'text/plain', 'first-path');
        $second = $metadata->putObjectVersioned('bucket', 'key', 'owner-b', 5, 'second', 'text/plain', 'second-path');
        $marker = $metadata->deleteObjectVersioned('bucket', 'key', 'marker-owner');

        $owners = [];
        foreach ($metadata->listObjectVersions('bucket')['entries'] ?? [] as $entry) {
            $owners[$entry->versionId ?? 'null'] = $entry->ownerId;
        }
        self::assertSame('owner-a', $owners[$first]);
        self::assertSame('owner-b', $owners[$second]);
        self::assertSame('marker-owner', $owners[$marker]);
        self::assertSame(['objectCount' => 1, 'bytesUsed' => 3], $metadata->getOwnerStorageStats('owner-a'));
        self::assertSame(['objectCount' => 1, 'bytesUsed' => 5], $metadata->getOwnerStorageStats('owner-b'));
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $metadata->getOwnerStorageStats('marker-owner'));

        self::assertSame('owner-a', $metadata->deleteObjectVersion('bucket', 'key', $first)?->ownerId);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $metadata->getOwnerStorageStats('owner-a'));
        self::assertSame('owner-b', $metadata->getObjectMetadataByVersion('bucket', 'key', $second)?->ownerId);

        $metadata->setBucketVersioning('bucket', 'Suspended');
        $metadata->putObjectMetadata('bucket', 'null-key', 'null-owner', 4, 'null', 'text/plain', 'null-path');
        $nullResource = 'bucket/null-key?versionId=null';
        $metadata->putAcl('object', $nullResource, 'null-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'null-owner',
            'permission' => 'FULL_CONTROL',
        ]]);
        self::assertSame('null', $metadata->deleteObjectVersioned('bucket', 'null-key', 'bucket-owner', suspended: true));
        $nullMarker = $metadata->getObjectMetadataByVersion('bucket', 'null-key', 'null');
        self::assertNotNull($nullMarker);
        self::assertTrue($nullMarker->isDeleteMarker);
        self::assertSame('bucket-owner', $nullMarker->ownerId);
        self::assertSame([], $metadata->getAcl('object', $nullResource));
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $metadata->getOwnerStorageStats('null-owner'));
    }
}
