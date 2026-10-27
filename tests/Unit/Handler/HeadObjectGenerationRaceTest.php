<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadObjectGenerationRaceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function partLayoutOutcomes(): iterable
    {
        yield 'successful selection' => ['success'];
        yield 'stale 416' => ['out-of-range'];
        yield 'stale 501' => ['unknown'];
        yield 'stale InternalError' => ['size-mismatch'];
    }

    #[DataProvider('partLayoutOutcomes')]
    public function test_bucket_reuse_during_part_layout_lookup_overrides_selection_outcome(string $outcome): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;
        $layout = $outcome === 'unknown'
            ? new ObjectPartLayout(ObjectPartLayout::UNKNOWN, 3)
            : ObjectPartLayout::knownSingle(3);
        $objectSize = $outcome === 'size-mismatch' ? 4 : 3;
        $ordinal = $outcome === 'out-of-range' ? 2 : 1;

        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            $objectSize,
            '"old"',
            partLayout: $layout->summary(1),
        ));
        $metadata->method('getBucketVersioning')->willReturn('');
        $metadata->expects(self::once())->method('getObjectPartLayout')
            ->willReturnCallback(static function () use (&$currentBucket, $newBucket, $layout): ObjectPartLayout {
                $currentBucket = $newBucket;

                return $layout;
            });
        $request = new Request(
            $this->createMock(Client::class),
            'HEAD',
            Http::new('http://127.0.0.1/bucket/key?partNumber=' . $ordinal),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $oldBucket->generation);

        $this->expectException(OperationAbortedException::class);
        (new HeadObjectHandler($metadata))->handleRequest($request);
    }

    public function test_bucket_reuse_during_versioning_lookup_does_not_return_old_object_headers(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo('bucket', 'key', 3, '"alice-etag"'));
        $metadata->method('getBucketVersioning')->willReturnCallback(static function () use (&$currentBucket, $newBucket): string {
            $currentBucket = $newBucket;

            return 'Enabled';
        });

        $request = new Request(
            $this->createMock(Client::class),
            'HEAD',
            Http::new('http://127.0.0.1/bucket/key'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $oldBucket->generation);

        $this->expectException(OperationAbortedException::class);
        (new HeadObjectHandler($metadata))->handleRequest($request);
    }

    public function test_bucket_reuse_during_missing_object_visibility_does_not_return_stale_status(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $bucketReads = 0;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$bucketReads, $oldBucket, $newBucket): BucketInfo {
            return ++$bucketReads < 4 ? $oldBucket : $newBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn(null);

        $request = new Request(
            $this->createMock(Client::class),
            'HEAD',
            Http::new('http://127.0.0.1/bucket/missing'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'missing');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $oldBucket->generation);

        $this->expectException(OperationAbortedException::class);
        (new HeadObjectHandler($metadata))->handleRequest($request);
    }
}
