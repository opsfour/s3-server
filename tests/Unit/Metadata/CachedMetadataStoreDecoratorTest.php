<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('invalidTtlValues')]
    public function test_rejects_non_finite_ttl(float $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        new CachedMetadataStoreDecorator($this->inner, $ttl);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidTtlValues(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    public function test_zero_ttl_remains_supported(): void
    {
        $store = new CachedMetadataStoreDecorator($this->inner, 0.0);
        $this->inner->createBucket('owner-a', 'bucket-a', 'us-east-1');
        self::assertSame([], $store->getBucketCors('bucket-a'));

        $rules = [[
            'allowedOrigins' => ['https://example.test'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ]];
        $this->inner->putBucketCors('bucket-a', $rules);

        self::assertSame($rules, $store->getBucketCors('bucket-a'));
    }

    public function test_object_part_layout_publication_read_and_immutability_are_forwarded(): void
    {
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 9, [
            new ObjectPart(1, 8, 0, 4, 'sha256', 'first'),
            new ObjectPart(2, 3, 4, 5),
        ]);

        $this->store->transaction(function () use ($layout): void {
            $this->store->putObjectMetadata('bucket-a', 'key', 'owner-a', 9, 'etag-2', 'application/octet-stream', 'objects/key');
            $this->store->putObjectPartLayout('bucket-a', 'key', 'null', $layout);
        });

        $object = $this->store->getObjectMetadata('bucket-a', 'key');
        self::assertNotNull($object);
        $persisted = $this->store->getObjectPartLayout($object);
        self::assertSame([8, 3], array_map(static fn(ObjectPart $part): ?int => $part->uploadPartNumber, $persisted->parts));
        self::assertSame('first', $persisted->parts[0]->checksumValue);

        try {
            $this->store->transaction(fn() => $this->store->putObjectPartLayout('bucket-a', 'key', 'null', ObjectPartLayout::knownSingle(9)));
            self::fail('The cache decorator must preserve committed layout immutability.');
        } catch (\LogicException $error) {
            self::assertSame('Published object part layouts are immutable.', $error->getMessage());
        }
    }

    public function test_transaction_rollback_discards_values_cached_inside_callback(): void
    {
        $rules = [[
            'allowedOrigins' => ['https://example.test'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ]];
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');

        try {
            $this->store->transaction(function () use ($rules): void {
                $this->store->putBucketCors('bucket-a', $rules);
                self::assertSame($rules, $this->store->getBucketCors('bucket-a'));

                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('force rollback', $e->getMessage());
        }

        self::assertSame([], $this->inner->getBucketCors('bucket-a'));
        self::assertSame([], $this->store->getBucketCors('bucket-a'));
    }

    public function test_manual_rollback_discards_values_cached_inside_transaction(): void
    {
        $rules = [[
            'allowedOrigins' => ['https://example.test'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ]];
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');
        $this->store->beginTransaction();
        $this->store->putBucketCors('bucket-a', $rules);
        self::assertSame($rules, $this->store->getBucketCors('bucket-a'));
        $this->store->rollback();

        self::assertSame([], $this->inner->getBucketCors('bucket-a'));
        self::assertSame([], $this->store->getBucketCors('bucket-a'));
    }

    public function test_failed_manual_commit_discards_values_cached_inside_transaction(): void
    {
        $rules = [[
            'allowedOrigins' => ['https://example.test'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ]];
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');
        $this->store->beginTransaction();
        $this->store->putBucketCors('bucket-a', $rules);
        self::assertSame($rules, $this->store->getBucketCors('bucket-a'));

        // Simulate a driver that has already rolled back when commit reports
        // an error, leaving the decorator unable to know the final outcome.
        $this->inner->rollback();
        $commitFailed = false;
        try {
            $this->store->commit();
        } catch (\Throwable) {
            $commitFailed = true;
        }

        self::assertTrue($commitFailed, 'Commit without an active inner transaction must fail.');
        self::assertSame([], $this->inner->getBucketCors('bucket-a'));
        self::assertSame([], $this->store->getBucketCors('bucket-a'));
    }

    public function test_cache_has_a_hard_entry_limit(): void
    {
        $store = new CachedMetadataStoreDecorator($this->inner, 60.0, 2);
        $rule = [[
            'allowedOrigins' => ['https://example.test'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => null,
        ]];
        foreach (['a', 'b', 'c'] as $suffix) {
            $this->inner->createBucket("owner-{$suffix}", "bucket-{$suffix}", 'us-east-1');
            $this->inner->putBucketCors("bucket-{$suffix}", $rule);
            self::assertSame($rule, $store->getBucketCors("bucket-{$suffix}"));
        }

        $this->inner->deleteBucketCors('bucket-a');
        $this->inner->deleteBucketCors('bucket-b');

        self::assertSame($rule, $store->getBucketCors('bucket-b'));
        self::assertSame([], $store->getBucketCors('bucket-a'));
    }

    public function test_security_metadata_changes_on_another_store_are_visible_immediately(): void
    {
        $peer = new SqliteMetadataStore($this->path);
        $peer->initialize();
        $this->store->createBucket('owner-a', 'bucket-a', 'us-east-1');

        self::assertSame('owner-a', $this->store->getBucketOwner('bucket-a'));
        self::assertNull($this->store->getBucketPolicy('bucket-a'));
        self::assertNull($this->store->getPublicAccessBlock('bucket-a'));
        self::assertNull($this->store->getBucketEncryption('bucket-a'));
        self::assertSame('', $this->store->getBucketVersioning('bucket-a'));
        self::assertNull($this->store->getAccountPolicy('owner-a'));
        self::assertNull($this->store->getNamedPolicy('readers'));

        $policy = '{"Version":"2012-10-17","Statement":[]}';
        $peer->putBucketPolicy('bucket-a', $policy);
        $peer->putPublicAccessBlock('bucket-a', true, true, true, true);
        $peer->putBucketEncryption('bucket-a', 'AES256');
        $peer->setBucketVersioning('bucket-a', 'Enabled');
        $peer->putAccountPolicy('owner-a', $policy);
        $peer->putNamedPolicy('readers', $policy);

        self::assertSame($policy, $peer->getBucketPolicy('bucket-a'));
        self::assertSame($policy, $this->inner->getBucketPolicy('bucket-a'));
        self::assertSame($policy, $this->store->getBucketPolicy('bucket-a'));
        self::assertSame($policy, $this->store->getAccountPolicy('owner-a'));
        self::assertSame($policy, $this->store->getNamedPolicy('readers'));
        $pab = $this->store->getPublicAccessBlock('bucket-a');
        $encryption = $this->store->getBucketEncryption('bucket-a');
        self::assertNotNull($pab);
        self::assertNotNull($encryption);
        self::assertTrue($pab['blockPublicPolicy']);
        self::assertSame('AES256', $encryption['sseAlgorithm']);
        self::assertSame('Enabled', $this->store->getBucketVersioning('bucket-a'));

        $peer->deleteBucket('owner-a', 'bucket-a');
        $peer->createBucket('owner-b', 'bucket-a', 'us-east-1');
        self::assertSame('owner-b', $this->store->getBucketOwner('bucket-a'));
        self::assertNull($this->store->getBucketPolicy('bucket-a'));
        self::assertNull($this->store->getPublicAccessBlock('bucket-a'));
        self::assertNull($this->store->getBucketEncryption('bucket-a'));
        self::assertSame('', $this->store->getBucketVersioning('bucket-a'));
    }
}
