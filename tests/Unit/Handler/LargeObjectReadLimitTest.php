<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\TestCase;

final class LargeObjectReadLimitTest extends TestCase
{
    private const int FIVE_TIB = 5_497_558_138_880;

    public function test_unbounded_get_above_five_tib_is_rejected_before_storage_open(): void
    {
        $metadata = $this->metadata(self::FIVE_TIB + 1);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(MethodNotAllowedException::class);
        (new GetObjectHandler($metadata, $storage))->handleRequest($this->request('GET'));
    }

    public function test_get_at_five_tib_boundary_remains_allowed(): void
    {
        $metadata = $this->metadata(self::FIVE_TIB);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/key', null, self::FIVE_TIB)
            ->willReturn(new ReadableBuffer(''));

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($this->request('GET'));

        self::assertSame(200, $response->getStatus());
        self::assertSame((string) self::FIVE_TIB, $response->getHeader('Content-Length'));
    }

    public function test_ranged_get_above_five_tib_remains_allowed(): void
    {
        $size = self::FIVE_TIB + 1;
        $metadata = $this->metadata($size);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/key', 0, 1)
            ->willReturn(new ReadableBuffer('x'));

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest(
            $this->request('GET', ['range' => 'bytes=0-0']),
        );

        self::assertSame(206, $response->getStatus());
        self::assertSame('bytes 0-0/' . $size, $response->getHeader('Content-Range'));
        self::assertSame('1', $response->getHeader('Content-Length'));
        self::assertSame('x', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_part_get_above_five_tib_bypasses_complete_object_guard(): void
    {
        $size = self::FIVE_TIB + 1;
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, $size, [
            new ObjectPart(1, 91, 0, 1),
            new ObjectPart(2, 37, 1, $size - 1),
        ]);
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            $size,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
            partLayout: $layout->summary(1),
        ));
        $metadata->method('getObjectPartLayout')->willReturn($layout);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/key', 0, 1)
            ->willReturn(new ReadableBuffer('x'));
        $request = $this->request('GET');
        $request->setUri(Http::new('http://127.0.0.1/bucket/key?partNumber=1'));

        $response = (new GetObjectHandler($metadata, $storage))->handleRequest($request);

        self::assertSame(206, $response->getStatus());
        self::assertSame('bytes 0-0/' . $size, $response->getHeader('Content-Range'));
        self::assertSame('x', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_head_above_five_tib_remains_available_with_and_without_range(): void
    {
        $size = self::FIVE_TIB + 1;
        $handler = new HeadObjectHandler($this->metadata($size));

        $full = $handler->handleRequest($this->request('HEAD'));
        $ranged = $handler->handleRequest($this->request('HEAD', ['range' => 'bytes=0-0']));

        self::assertSame(200, $full->getStatus());
        self::assertSame((string) $size, $full->getHeader('Content-Length'));
        self::assertSame(200, $ranged->getStatus());
        self::assertSame('1', $ranged->getHeader('Content-Length'));
        self::assertNull($ranged->getHeader('Content-Range'));
    }

    private function metadata(int $size): MetadataStore
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

        return $metadata;
    }

    /**
     * @param 'GET'|'HEAD' $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
