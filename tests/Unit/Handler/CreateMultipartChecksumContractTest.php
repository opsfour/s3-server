<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Multipart\MultipartPartLogicalValidator;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartPartChecksumStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateMultipartChecksumContractTest extends TestCase
{
    /** @return iterable<string, array{float, float}> */
    public static function invalidIntentLeaseIntervals(): iterable
    {
        yield 'NaN lease' => [NAN, 1.0];
        yield 'infinite lease' => [INF, 1.0];
        yield 'NaN renewal' => [100.0, NAN];
        yield 'infinite renewal' => [100.0, INF];
    }

    #[DataProvider('invalidIntentLeaseIntervals')]
    public function test_non_finite_completion_intent_intervals_are_rejected_before_requests(float $lease, float $renewal): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CompleteMultipartUploadHandler(
            $this->createMock(MetadataStore::class),
            $this->createMock(StorageBackend::class),
            intentLeaseSeconds: $lease,
            intentRenewalSeconds: $renewal,
        );
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function unsupportedHeaders(): iterable
    {
        yield 'algorithm' => ['x-amz-checksum-algorithm'];
        yield 'checksum type' => ['x-amz-checksum-type'];
    }

    /** @param non-empty-string $header */
    #[DataProvider('unsupportedHeaders')]
    public function test_additional_checksum_contract_is_rejected_before_upload_creation(string $header): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploads'),
            [$header => 'CRC32'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NotImplementedException::class);
        (new CreateMultipartUploadHandler($metadata))->handleRequest($request);
    }

    /** @return iterable<string, array{non-empty-string, string}> */
    public static function malformedPartChecksums(): iterable
    {
        yield 'CRC32 invalid Base64' => ['x-amz-checksum-crc32', 'not-base64'];
        yield 'CRC32 wrong decoded length' => ['x-amz-checksum-crc32', base64_encode(str_repeat("\0", 3))];
        yield 'CRC32C wrong decoded length' => ['x-amz-checksum-crc32c', base64_encode(str_repeat("\0", 5))];
        yield 'SHA1 wrong decoded length' => ['x-amz-checksum-sha1', base64_encode(str_repeat("\0", 19))];
        yield 'SHA256 wrong decoded length' => ['x-amz-checksum-sha256', base64_encode(str_repeat("\0", 31))];
        yield 'empty digest' => ['x-amz-checksum-sha256', ''];
    }

    /** @param non-empty-string $header */
    #[DataProvider('malformedPartChecksums')]
    public function test_malformed_part_checksum_is_rejected_before_metadata_or_storage_access(
        string $header,
        string $value,
    ): void {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            [$header => $value],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(BadDigestException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{array<non-empty-string, string|list<string>>}> */
    public static function duplicatePartChecksumHeaders(): iterable
    {
        $crc32 = base64_encode(hash('crc32b', 'payload', true));

        yield 'same digest header twice' => [[
            'x-amz-checksum-crc32' => [$crc32, $crc32],
        ]];
        yield 'conflicting digest header twice' => [[
            'x-amz-checksum-crc32' => [$crc32, base64_encode("\0\0\0\0")],
        ]];
        yield 'selector twice' => [[
            'x-amz-sdk-checksum-algorithm' => ['CRC32', 'CRC32'],
            'x-amz-checksum-crc32' => $crc32,
        ]];
        yield 'trailer declaration twice' => [[
            'x-amz-sdk-checksum-algorithm' => 'CRC32',
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-trailer' => ['x-amz-checksum-crc32', 'x-amz-checksum-crc32'],
        ]];
    }

    /** @param array<non-empty-string, string|list<string>> $headers */
    #[DataProvider('duplicatePartChecksumHeaders')]
    public function test_duplicate_part_checksum_headers_are_rejected_before_metadata_or_storage_access(array $headers): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            $headers,
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_unsupported_part_checksum_header_is_rejected_before_metadata_or_storage_access(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            ['x-amz-checksum-crc64nvme' => base64_encode(str_repeat("\0", 8))],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');

        $this->expectException(NotImplementedException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_normal_sdk_part_checksum_header_is_admitted_to_resource_lookup(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            [
                'x-amz-sdk-checksum-algorithm' => 'CRC32',
                'x-amz-checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
            ],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NoSuchBucketException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{array<non-empty-string, string>}> */
    public static function invalidSdkPartChecksumContracts(): iterable
    {
        yield 'no checksum trailer' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
        ]];
        yield 'not a verified streaming trailer' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-trailer' => 'x-amz-checksum-sha256',
        ]];
        yield 'different declared trailer' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-trailer' => 'x-amz-checksum-crc32',
        ]];
        yield 'different normal checksum header' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
        ]];
        yield 'pre-supplied trailer header' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-trailer' => 'x-amz-checksum-sha256',
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', 'payload', true)),
        ]];
    }

    /** @param array<non-empty-string, string> $headers */
    #[DataProvider('invalidSdkPartChecksumContracts')]
    public function test_part_sdk_checksum_contract_is_rejected_before_metadata_or_storage_access(array $headers): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            $headers,
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidRequestException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_part_sdk_checksum_contract_accepts_matching_verified_trailer_declaration(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            [
                'x-amz-sdk-checksum-algorithm' => 'SHA256',
                'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
                'x-amz-trailer' => 'x-amz-checksum-sha256',
            ],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NoSuchBucketException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_part_trailer_value_without_auth_evidence_is_rejected_after_body_read(): void
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            [
                'x-amz-sdk-checksum-algorithm' => 'SHA256',
                'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
                'x-amz-trailer' => 'x-amz-checksum-sha256',
            ],
            'payload',
        );
        $expected = MultipartPartLogicalValidator::expectedClientChecksumAlgorithm($request);
        $request->setHeader('x-amz-checksum-sha256', base64_encode(hash('sha256', 'payload', true)));

        $this->expectException(InvalidRequestException::class);
        MultipartPartLogicalValidator::verifiedClientChecksums($request, $expected);
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function unsupportedCompletionHeaders(): iterable
    {
        foreach ([
            'x-amz-checksum-algorithm',
            'x-amz-checksum-type',
            'x-amz-sdk-checksum-algorithm',
            'x-amz-checksum-crc32',
            'x-amz-checksum-crc32c',
            'x-amz-checksum-sha1',
            'x-amz-checksum-sha256',
            'x-amz-checksum-crc64nvme',
            'x-amz-checksum-sha512',
            'x-amz-checksum-md5',
            'x-amz-checksum-xxhash64',
            'x-amz-checksum-xxhash3',
            'x-amz-checksum-xxhash128',
            'x-amz-checksum-future',
        ] as $header) {
            yield $header => [$header];
        }
    }

    /** @param non-empty-string $header */
    #[DataProvider('unsupportedCompletionHeaders')]
    public function test_completion_checksum_contract_is_rejected_before_metadata_or_storage_access(string $header): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload'),
            [$header => 'SHA256'],
            '<CompleteMultipartUpload/>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NotImplementedException::class);
        (new CompleteMultipartUploadHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{array<non-empty-string, string|list<string>>, class-string<\Throwable>}> */
    public static function invalidPutObjectChecksumContracts(): iterable
    {
        $crc32 = base64_encode(hash('crc32b', 'payload', true));

        yield 'unsupported checksum' => [[
            'x-amz-checksum-sha512' => base64_encode(hash('sha512', 'payload', true)),
        ], NotImplementedException::class];
        yield 'unknown checksum' => [[
            'x-amz-checksum-future' => 'value',
        ], NotImplementedException::class];
        yield 'selector without checksum' => [[
            'x-amz-sdk-checksum-algorithm' => 'CRC32',
        ], InvalidRequestException::class];
        yield 'selector mismatch' => [[
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-checksum-crc32' => $crc32,
        ], InvalidRequestException::class];
        yield 'duplicate digest' => [[
            'x-amz-checksum-crc32' => [$crc32, $crc32],
        ], InvalidArgumentException::class];
        yield 'duplicate selector' => [[
            'x-amz-sdk-checksum-algorithm' => ['CRC32', 'CRC32'],
            'x-amz-checksum-crc32' => $crc32,
        ], InvalidArgumentException::class];
        yield 'duplicate trailer declaration' => [[
            'x-amz-sdk-checksum-algorithm' => 'CRC32',
            'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            'x-amz-trailer' => ['x-amz-checksum-crc32', 'x-amz-checksum-crc32'],
        ], InvalidArgumentException::class];
    }

    /**
     * @param array<non-empty-string, string|list<string>> $headers
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('invalidPutObjectChecksumContracts')]
    public function test_put_object_checksum_contract_is_rejected_before_metadata_or_storage_access(
        array $headers,
        string $exception,
    ): void {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException($exception);
        (new PutObjectHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}> */
    public static function supportedPutObjectChecksums(): iterable
    {
        yield 'CRC32' => ['CRC32', 'x-amz-checksum-crc32', base64_encode(hash('crc32b', 'payload', true))];
        yield 'CRC32C' => ['CRC32C', 'x-amz-checksum-crc32c', base64_encode(hash('crc32c', 'payload', true))];
        yield 'SHA1' => ['SHA1', 'x-amz-checksum-sha1', base64_encode(hash('sha1', 'payload', true))];
        yield 'SHA256' => ['SHA256', 'x-amz-checksum-sha256', base64_encode(hash('sha256', 'payload', true))];
    }

    /**
     * @param non-empty-string $algorithm
     * @param non-empty-string $header
     * @param non-empty-string $value
     */
    #[DataProvider('supportedPutObjectChecksums')]
    public function test_put_object_accepts_each_supported_matching_checksum_before_resource_lookup(
        string $algorithm,
        string $header,
        string $value,
    ): void {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getBucket')->with('bucket')->willReturn(null);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [
                'x-amz-sdk-checksum-algorithm' => $algorithm,
                $header => $value,
            ],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NoSuchBucketException::class);
        (new PutObjectHandler($metadata, $storage))->handleRequest($request);
    }

    public function test_copy_object_checksum_selection_is_rejected_before_metadata_or_storage_access(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('copyObject');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [
                'x-amz-copy-source' => '/source/key',
                'x-amz-checksum-algorithm' => 'SHA256',
            ],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NotImplementedException::class);
        (new CopyObjectHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{'cached'|'observed'}> */
    public static function checksumDecoratorKinds(): iterable
    {
        yield 'cached' => ['cached'];
        yield 'observed' => ['observed'];
    }

    /** @param 'cached'|'observed' $kind */
    #[DataProvider('checksumDecoratorKinds')]
    public function test_checksum_decorator_without_inner_capability_rejects_before_metadata_or_storage_access(string $kind): void
    {
        $inner = $this->createMock(MetadataStore::class);
        $inner->expects(self::never())->method('getBucket');
        $inner->expects(self::never())->method('putPart');
        $metadata = $this->decorate($kind, $inner);
        self::assertFalse($metadata->supportsMultipartPartChecksums());

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload&partNumber=1'),
            [
                'x-amz-sdk-checksum-algorithm' => 'CRC32',
                'x-amz-checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
            ],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(NotImplementedException::class);
        (new UploadPartHandler($metadata, $storage))->handleRequest($request);
    }

    /** @param 'cached'|'observed' $kind */
    #[DataProvider('checksumDecoratorKinds')]
    public function test_checksum_decorator_without_inner_capability_uses_s3_not_implemented_exception(string $kind): void
    {
        $inner = $this->createMock(MetadataStore::class);
        $inner->expects(self::never())->method('putPart');
        $metadata = $this->decorate($kind, $inner);

        $this->expectException(NotImplementedException::class);
        $metadata->putPartWithChecksum('upload', 1, '"etag"', 7, '/part', 'crc32', 'checksum');
    }

    /** @param 'cached'|'observed' $kind */
    private function decorate(string $kind, MetadataStore $inner): MetadataStore&MultipartPartChecksumStore
    {
        return $kind === 'cached'
            ? new CachedMetadataStoreDecorator($inner)
            : new ObservedMetadataStore($inner, new MetricsCollector(), 'custom');
    }
}
