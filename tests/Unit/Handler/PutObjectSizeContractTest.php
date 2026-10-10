<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PutObjectSizeContractTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidLengths')]
    public function test_invalid_or_oversized_declared_length_fails_before_storage(array $headers, string $exception): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $this->expectException($exception);
        (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request('', $headers));
    }

    /** @return iterable<string, array{array<non-empty-string, string>, class-string<\Throwable>}> */
    public static function invalidLengths(): iterable
    {
        $chunked = ['x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER'];

        yield 'missing decoded length' => [$chunked, InvalidArgumentException::class];
        yield 'nonnumeric decoded length' => [$chunked + ['x-amz-decoded-content-length' => 'abc'], InvalidArgumentException::class];
        yield 'oversized decoded length' => [$chunked + ['x-amz-decoded-content-length' => (string) (MultipartPartSize::MAX_BYTES + 1)], EntityTooLargeException::class];
        yield 'oversized normal length' => [['content-length' => (string) (MultipartPartSize::MAX_BYTES + 1)], EntityTooLargeException::class];
    }

    public function test_zero_decoded_length_is_valid_after_authentication_has_removed_framing(): void
    {
        $storage = new InMemoryBackend();
        $response = (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request('', [
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-decoded-content-length' => '0',
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame(0, $this->metadata->getObjectMetadata('bucket', 'key')?->size);
    }

    public function test_backend_result_above_single_put_limit_is_deleted_before_metadata_publish(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturn(new StorageWriteResult(
            '/oversized',
            MultipartPartSize::MAX_BYTES + 1,
            md5(''),
        ));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('/oversized', 'bucket');

        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request(''));
            self::fail('Oversized backend result must not be published.');
        } catch (EntityTooLargeException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(string $body, array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://localhost/bucket/key'),
            $headers,
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
