<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use League\Flysystem\Filesystem;
use League\Uri\Http;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\PreconditionFailedException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
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

    public function test_repeated_list_valued_metadata_headers_are_combined_before_persistence(): void
    {
        $storage = new InMemoryBackend();
        $response = (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request('body', [
            'content-encoding' => ['gzip', 'br'],
            'content-language' => ['de', 'en'],
            'cache-control' => ['no-cache', 'no-store'],
        ]));

        $object = $this->metadata->getObjectMetadata('bucket', 'key');

        self::assertSame(200, $response->getStatus());
        self::assertSame('gzip, br', $object?->systemMetadata['content-encoding'] ?? null);
        self::assertSame('de, en', $object?->userMetadata['__content-language'] ?? null);
        self::assertSame('no-cache, no-store', $object?->systemMetadata['cache-control'] ?? null);
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

    public function test_custom_backend_full_read_uses_package_crc64_capture(): void
    {
        $payload = 'custom backend logical payload';
        $digest = new Crc64NvmeDigest();
        $digest->update($payload);
        $checksum = $digest->finalizeBase64();
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body) use ($payload): StorageWriteResult {
                self::assertSame($payload, \Amp\ByteStream\buffer($body));

                return new StorageWriteResult('/custom/full', strlen($payload), md5($payload));
            },
        );

        $response = (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request($payload, [
            'x-amz-checksum-crc64nvme' => $checksum,
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame($checksum, $response->getHeader('x-amz-checksum-crc64nvme'));
        self::assertSame(
            $checksum,
            $this->metadata->getObjectMetadata('bucket', 'key')?->systemMetadata['checksum-crc64nvme'] ?? null,
        );
    }

    public function test_custom_backend_early_eof_crc64_failure_deletes_returned_path(): void
    {
        $payload = 'first-second';
        $digest = new Crc64NvmeDigest();
        $digest->update($payload);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body): StorageWriteResult {
                $first = $body->read();
                self::assertSame('first-', $first);

                return new StorageWriteResult('/custom/partial', strlen((string) $first), md5((string) $first));
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('/custom/partial', 'bucket');
        $body = new ReadableIterableStream(['first-', 'second']);

        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request($body, [
                'x-amz-checksum-crc64nvme' => $digest->finalizeBase64(),
            ]));
            self::fail('A partial custom-backend read finalized CRC64NVME.');
        } catch (\LogicException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
    }

    public function test_repeated_if_match_lines_are_evaluated_as_one_entity_tag_list(): void
    {
        $storage = new InMemoryBackend();
        $handler = new PutObjectHandler($this->metadata, $storage);
        $handler->handleRequest($this->request('original'));
        $etag = '"' . md5('original') . '"';

        $response = $handler->handleRequest($this->request('replacement', [
            'If-Match' => ['"different"', $etag],
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame('"' . md5('replacement') . '"', $this->metadata->getObjectMetadata('bucket', 'key')?->etag);
    }

    public function test_repeated_if_none_match_lines_cannot_overwrite_a_matching_object(): void
    {
        $storage = new InMemoryBackend();
        $handler = new PutObjectHandler($this->metadata, $storage);
        $handler->handleRequest($this->request('original'));
        $etag = '"' . md5('original') . '"';

        $this->expectException(PreconditionFailedException::class);
        try {
            $handler->handleRequest($this->request('replacement', [
                'If-None-Match' => ['"different"', $etag],
            ]));
        } finally {
            self::assertSame($etag, $this->metadata->getObjectMetadata('bucket', 'key')?->etag);
        }
    }

    public function test_sse_temp_capacity_failure_does_not_read_request_body(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-put-capacity-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $budget = new StorageTempBudgetCoordinator($tempDir, 4, 0);
        $storage = new ObservedStorageBackend(
            new FlysystemBackend(new Filesystem(new InMemoryFlysystemAdapter()), $tempDir, $budget),
            new MetricsCollector(),
            'flysystem',
        );
        $reads = 0;
        $body = new ReadableIterableStream((static function () use (&$reads): iterable {
            $reads++;
            yield 'payload!!';
        })());
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://localhost/bucket/key'),
            ['content-length' => '9', 'x-amz-server-side-encryption' => 'AES256'],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'owner');

        try {
            $this->expectException(SlowDownException::class);
            try {
                (new PutObjectHandler(
                    $this->metadata,
                    $storage,
                    $this->createMock(EncryptionServiceInterface::class),
                    tempBudget: $budget,
                ))->handleRequest($request);
            } finally {
                self::assertSame(0, $reads);
                self::assertSame(0, $budget->snapshot()->reservedBytes);
            }
        } finally {
            $budget->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    /** @param array<non-empty-string, string|list<string>> $headers */
    private function request(string|ReadableStream $body, array $headers = []): Request
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
