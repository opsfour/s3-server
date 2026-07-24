<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class CachedMetadataStoreDecoratorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $inner;

    private CachedMetadataStoreDecorator $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-cached-metadata-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->inner = new SqliteMetadataStore($this->path);
        $this->inner->initialize();
        $this->store = new CachedMetadataStoreDecorator($this->inner, 60.0);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_transaction_rollback_discards_values_cached_inside_callback(): void
    {
        $policy = '{"Version":"2012-10-17","Statement":[]}';
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');

        try {
            $this->store->transaction(function () use ($policy): void {
                $this->store->putBucketPolicy('bucket-a', $policy);
                self::assertSame($policy, $this->store->getBucketPolicy('bucket-a'));

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('force rollback', $e->getMessage());
        }

        self::assertNull($this->inner->getBucketPolicy('bucket-a'));
        self::assertNull($this->store->getBucketPolicy('bucket-a'));
    }

    public function test_manual_rollback_discards_values_cached_inside_transaction(): void
    {
        $this->store->beginTransaction();
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');
        self::assertSame('owner-a', $this->store->getBucketOwner('bucket-a'));
        $this->store->rollback();

        self::assertNull($this->inner->getBucketOwner('bucket-a'));
        self::assertNull($this->store->getBucketOwner('bucket-a'));
    }

    public function test_cache_has_a_hard_entry_limit(): void
    {
        $store = new CachedMetadataStoreDecorator($this->inner, 60.0, 2);
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->inner->createBucket("owner-{$suffix}", "bucket-{$suffix}", 'us-east-1');
            self::assertSame("owner-{$suffix}", $store->getBucketOwner("bucket-{$suffix}"));
        }

        $this->inner->deleteBucket('owner-a', 'bucket-a');
        $this->inner->deleteBucket('owner-b', 'bucket-b');

        self::assertSame('owner-b', $store->getBucketOwner('bucket-b'));
        self::assertNull($store->getBucketOwner('bucket-a'));
    }
}
