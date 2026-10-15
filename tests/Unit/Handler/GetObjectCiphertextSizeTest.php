<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetObjectCiphertextSizeTest extends TestCase
{
    #[DataProvider('mismatchedBodies')]
    public function test_encrypted_get_rejects_storage_bytes_that_disagree_with_metadata(string $storedBody): void
    {
        $bucket = new BucketInfo('bucket', 'owner');
        $object = new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            userMetadata: ['__sse-algorithm' => 'AES256'],
            systemMetadata: ['storagePath' => 'objects/key'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())
            ->method('getObjectByPath')
            ->with('objects/key')
            ->willReturn(new ReadableBuffer($storedBody));
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->expects(self::never())->method('decryptSseS3');

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored encrypted object size does not match metadata.');

        (new GetObjectHandler($metadata, $storage, $encryption, maxEncryptedObjectSize: 8))->handleRequest($request);
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedBodies(): iterable
    {
        yield 'larger than metadata' => ['abcd'];
        yield 'shorter than metadata' => ['ab'];
    }

    #[DataProvider('mismatchedPlaintexts')]
    public function test_encrypted_get_rejects_decrypted_bytes_that_disagree_with_metadata(
        string $plaintext,
        ?string $range,
    ): void {
        $bucket = new BucketInfo('bucket', 'owner');
        $object = new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            userMetadata: [
                '__sse-algorithm' => 'AES256',
                '__sse-key' => 'wrapped-key',
                '__sse-iv' => 'iv',
                '__sse-tag' => 'tag',
            ],
            systemMetadata: ['storagePath' => 'objects/key'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->with('objects/key')->willReturn(new ReadableBuffer('abc'));
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->expects(self::once())->method('decryptSseS3')->willReturn($plaintext);

        $headers = $range === null ? [] : ['Range' => $range];
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Decrypted object size does not match metadata.');

        (new GetObjectHandler($metadata, $storage, $encryption, maxEncryptedObjectSize: 8))->handleRequest($request);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function mismatchedPlaintexts(): iterable
    {
        yield 'short full object' => ['ab', null];
        yield 'long full object' => ['abcd', null];
        yield 'short ranged object' => ['ab', 'bytes=0-0'];
        yield 'long ranged object' => ['abcd', 'bytes=0-0'];
    }
}
