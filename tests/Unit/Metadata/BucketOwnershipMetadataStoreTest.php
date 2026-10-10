<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class BucketOwnershipMetadataStoreTest extends TestCase
{
    public function test_migration_preserves_legacy_bucket_and_deletion_clears_controls(): void
    {
        $path = sys_get_temp_dir() . '/s3-ownership-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path);
            (new SchemaManager($pdo))->migrate(16);
            $pdo->exec("INSERT INTO s3_buckets (name, owner_id, region) VALUES ('legacy', 'owner', 'us-east-1')");
            unset($pdo);

            $store = new SqliteMetadataStore($path);
            $store->initialize();
            self::assertSame('owner', $store->getBucketOwner('legacy'));
            self::assertNull($store->getBucketOwnershipControls('legacy'));
            $store->putBucketOwnershipControls('legacy', 'BucketOwnerEnforced');
            self::assertSame('BucketOwnerEnforced', $store->getBucketOwnershipControls('legacy'));
            $store->deleteBucket('owner', 'legacy');
            self::assertNull($store->getBucketOwnershipControls('legacy'));
            $store->createBucket('owner', 'legacy', 'us-east-1');
            self::assertNull($store->getBucketOwnershipControls('legacy'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
