<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConditionalSseCReadTest extends TestCase
{
    #[DataProvider('conditionalHeaders')]
    public function test_sse_c_key_is_required_before_a_conditional_response(bool $isHead, bool $etagMatches): void
    {
        $method = $isHead ? 'HEAD' : 'GET';
        $headers = $etagMatches
            ? ['If-None-Match' => '"etag"']
            : ['If-Match' => '"different"'];
        $bucket = new BucketInfo('bucket', 'owner');
        $object = new ObjectInfo(
            'bucket',
            'key',
            5,
            '"etag"',
            userMetadata: ['__sse-algorithm' => 'SSE-C'],
            systemMetadata: ['storagePath' => 'objects/key'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SSE-C headers are required');

        $handler = $method === 'GET'
            ? new GetObjectHandler($metadata, $storage)
            : new HeadObjectHandler($metadata);
        $handler->handleRequest($request);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function conditionalHeaders(): iterable
    {
        yield 'GET 304' => [false, true];
        yield 'GET 412' => [false, false];
        yield 'HEAD 304' => [true, true];
        yield 'HEAD 412' => [true, false];
    }
}
