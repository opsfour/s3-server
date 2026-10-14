<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\TestCase;

final class GetObjectGenerationRaceTest extends TestCase
{
    public function test_authorized_snapshot_prevents_aba_lookup_of_foreign_current_object(): void
    {
        $bucket = new BucketInfo('bucket', 'bucket-owner', generation: str_repeat('a', 32));
        $authorized = new ObjectInfo(
            'bucket',
            'key',
            3,
            '"old"',
            ownerId: 'alice',
            systemMetadata: ['storagePath' => 'objects/alice'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getBucketVersioning')->willReturn('');
        // Between authorization and handler execution the current pointer
        // may visit another owner and return (ABA). The handler must not
        // resolve it again, even if a later comparison sees the old value.
        $metadata->expects(self::never())->method('getCurrentObjectVersion');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/alice', null, 3)
            ->willReturn(new ReadableBuffer('old'));
        $request = $this->request($bucket, 'key');
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame('old', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_bucket_reuse_during_versioning_lookup_does_not_return_old_object(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"alice-etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $metadata->method('getBucketVersioning')->willReturnCallback(static function () use (&$currentBucket, $newBucket): string {
            $currentBucket = $newBucket;

            return 'Enabled';
        });

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(OperationAbortedException::class);
        (new GetObjectHandler($metadata, $storage))->handleRequest($this->request($oldBucket, 'key'));
    }

    public function test_bucket_reuse_after_storage_open_closes_the_owned_stream(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"alice-etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $metadata->method('getBucketVersioning')->willReturn('Enabled');

        $stream = new ReadableBuffer('old');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/key', null, 3)
            ->willReturnCallback(static function () use (&$currentBucket, $newBucket, $stream): ReadableBuffer {
                $currentBucket = $newBucket;

                return $stream;
            });

        $this->expectException(OperationAbortedException::class);
        try {
            (new GetObjectHandler($metadata, $storage))->handleRequest($this->request($oldBucket, 'key'));
        } finally {
            self::assertTrue($stream->isClosed());
        }
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

        $storage = $this->createMock(StorageBackend::class);
        $this->expectException(OperationAbortedException::class);
        (new GetObjectHandler($metadata, $storage))->handleRequest($this->request($oldBucket, 'missing'));
    }

    private function request(BucketInfo $bucket, string $key): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/' . $key),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);

        return $request;
    }
}
