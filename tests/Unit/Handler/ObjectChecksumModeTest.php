<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObjectChecksumModeTest extends TestCase
{
    /** @return iterable<string, array{'GET'|'HEAD'}> */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_stored_checksums_require_enabled_mode_and_disappear_for_ranges(string $method): void
    {
        [$metadata, $storage] = $this->dependencies();
        $handler = $method === 'GET'
            ? new GetObjectHandler($metadata, $storage)
            : new HeadObjectHandler($metadata);

        $withoutMode = $handler->handleRequest($this->request($method));
        self::assertNull($withoutMode->getHeader('x-amz-checksum-crc32'));
        self::assertNull($withoutMode->getHeader('x-amz-checksum-sha256'));
        self::assertNull($withoutMode->getHeader('x-amz-checksum-type'));

        $emptyMode = $handler->handleRequest($this->request($method, ['x-amz-checksum-mode' => '']));
        self::assertNull($emptyMode->getHeader('x-amz-checksum-crc32'));

        $enabled = $handler->handleRequest($this->request($method, ['x-amz-checksum-mode' => 'ENABLED']));
        self::assertSame(base64_encode(hash('crc32b', 'payload', true)), $enabled->getHeader('x-amz-checksum-crc32'));
        self::assertSame(base64_encode(hash('sha256', 'payload', true)), $enabled->getHeader('x-amz-checksum-sha256'));
        self::assertSame('FULL_OBJECT', $enabled->getHeader('x-amz-checksum-type'));

        $range = $handler->handleRequest($this->request($method, [
            'x-amz-checksum-mode' => 'ENABLED',
            'range' => 'bytes=0-2',
        ]));
        self::assertSame($method === 'GET' ? 206 : 200, $range->getStatus());
        self::assertNull($range->getHeader('x-amz-checksum-crc32'));
        self::assertNull($range->getHeader('x-amz-checksum-sha256'));
        self::assertNull($range->getHeader('x-amz-checksum-type'));
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_legacy_object_without_checksum_does_not_claim_checksum_type(string $method): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            7,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer('payload'));
        $handler = $method === 'GET'
            ? new GetObjectHandler($metadata, $storage)
            : new HeadObjectHandler($metadata);

        $response = $handler->handleRequest($this->request($method, ['x-amz-checksum-mode' => 'ENABLED']));

        self::assertNull($response->getHeader('x-amz-checksum-type'));
        self::assertNull($response->getHeader('x-amz-checksum-crc32'));
        self::assertNull($response->getHeader('x-amz-checksum-crc32c'));
        self::assertNull($response->getHeader('x-amz-checksum-sha1'));
        self::assertNull($response->getHeader('x-amz-checksum-sha256'));
    }

    /** @return iterable<string, array{'GET'|'HEAD', string}> */
    public static function invalidModes(): iterable
    {
        foreach (['GET', 'HEAD'] as $method) {
            foreach (['enabled', 'DISABLED', ' ENABLED', 'true'] as $mode) {
                yield $method . ' ' . $mode => [$method, $mode];
            }
        }
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('invalidModes')]
    public function test_invalid_nonempty_mode_fails_before_metadata_or_storage_access(string $method, string $mode): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(InvalidArgumentException::class);
        ($method === 'GET' ? new GetObjectHandler($metadata, $storage) : new HeadObjectHandler($metadata))
            ->handleRequest($this->request($method, ['x-amz-checksum-mode' => $mode]));
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_part_number_is_rejected_before_metadata_or_storage_access(string $method): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $request = $this->request($method);
        $request->setUri(Http::new('http://127.0.0.1/bucket/key?partNumber=1'));

        $this->expectException(NotImplementedException::class);
        ($method === 'GET' ? new GetObjectHandler($metadata, $storage) : new HeadObjectHandler($metadata))
            ->handleRequest($request);
    }

    /** @return array{MetadataStore, StorageBackend} */
    private function dependencies(): array
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            7,
            '"etag"',
            systemMetadata: [
                'storagePath' => 'objects/key',
                'checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
                'checksum-sha256' => base64_encode(hash('sha256', 'payload', true)),
            ],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer('payload'));

        return [$metadata, $storage];
    }

    /**
     * @param 'GET'|'HEAD' $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1/bucket/key'), $headers);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
