<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetObjectUnknownTierTest extends TestCase
{
    #[DataProvider('registryModes')]
    public function test_unknown_persisted_tier_never_reads_a_path_from_the_default_backend(bool $explicitRegistry): void
    {
        $bucket = new BucketInfo('bucket', 'owner');
        $object = new ObjectInfo(
            'bucket',
            'key',
            5,
            '"etag"',
            storageTier: 'WARM',
            systemMetadata: ['storagePath' => 'same-path-as-an-unrelated-standard-object'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $handler = new GetObjectHandler(
            $metadata,
            $storage,
            storageTiers: $explicitRegistry ? StorageTierRegistry::single($storage) : null,
        );

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Object storage tier is not configured.');

        $handler->handleRequest($request);
    }

    public function test_range_response_caps_a_backend_that_ignores_the_requested_length(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            10,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())
            ->method('getObjectByPath')
            ->with('objects/key', 2, 3)
            ->willReturn(new ReadableBuffer('23456789'));

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
            ['range' => 'bytes=2-4'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($request);

        self::assertSame(206, $response->getStatus());
        self::assertSame('3', $response->getHeader('content-length'));
        self::assertSame('234', \Amp\ByteStream\buffer($response->getBody()));
    }

    #[DataProvider('shortReads')]
    public function test_get_rejects_a_short_backend_read(int $size, ?string $range, string $storedBody, ?int $offset, int $expectedLength): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            $size,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())
            ->method('getObjectByPath')
            ->with('objects/key', $offset, $expectedLength)
            ->willReturn(new ReadableBuffer($storedBody));

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
            $range === null ? [] : ['range' => $range],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($request);
        self::assertSame($range === null ? 200 : 206, $response->getStatus());

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored object size does not match metadata.');
        \Amp\ByteStream\buffer($response->getBody());
    }

    public function test_zero_byte_get_passes_zero_length_to_backend_without_reading_data(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            0,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())
            ->method('getObjectByPath')
            ->with('objects/key', null, 0)
            ->willReturn(new ReadableBuffer('physical surplus'));

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($request);
        self::assertSame('0', $response->getHeader('content-length'));
        self::assertSame('', \Amp\ByteStream\buffer($response->getBody()));
    }

    /** @return iterable<string, array{int, ?string, string, ?int, int}> */
    public static function shortReads(): iterable
    {
        yield 'full object' => [3, null, 'ab', null, 3];
        yield 'range' => [10, 'bytes=2-4', '23', 2, 3];
    }

    /** @return iterable<string, array{bool}> */
    public static function registryModes(): iterable
    {
        yield 'explicit registry' => [true];
        yield 'default registry' => [false];
    }
}
