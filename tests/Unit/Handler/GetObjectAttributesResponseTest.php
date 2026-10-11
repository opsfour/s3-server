<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use PHPUnit\Framework\TestCase;

final class GetObjectAttributesResponseTest extends TestCase
{
    public function test_suspended_null_version_uses_response_headers_without_undeclared_xml_field(): void
    {
        $lastModified = new \DateTimeImmutable('2026-01-02T03:04:05Z');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            lastModified: $lastModified,
        ));
        $metadata->method('getBucketVersioning')->willReturn('Suspended');

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ETag,ObjectSize'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $response = (new GetObjectAttributesHandler($metadata))->handleRequest($request);
        $body = \Amp\ByteStream\buffer($response->getBody());

        self::assertSame(200, $response->getStatus());
        self::assertSame('null', $response->getHeader('x-amz-version-id'));
        self::assertSame('Fri, 02 Jan 2026 03:04:05 GMT', $response->getHeader('last-modified'));
        self::assertStringContainsString('<ObjectSize>3</ObjectSize>', $body);
        self::assertStringNotContainsString('<LastModified>', $body);
    }

    public function test_repeated_attribute_names_do_not_duplicate_xml_fields(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, '"etag"'));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ETag,ETag,ObjectSize,ETag'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $response = (new GetObjectAttributesHandler($metadata))->handleRequest($request);
        $body = \Amp\ByteStream\buffer($response->getBody());

        self::assertSame(1, substr_count($body, '<ETag>'));
        self::assertSame(1, substr_count($body, '<ObjectSize>'));
    }

    public function test_explicit_delete_marker_returns_method_not_allowed_with_marker_headers(): void
    {
        $lastModified = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadataByVersion')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            0,
            '"marker"',
            lastModified: $lastModified,
            versionId: 'marker-version',
            isDeleteMarker: true,
        ));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes&versionId=marker-version'),
            ['x-amz-object-attributes' => 'ETag'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        try {
            (new GetObjectAttributesHandler($metadata))->handleRequest($request);
            self::fail('An explicit delete marker must not return object attributes.');
        } catch (MethodNotAllowedException $error) {
            self::assertSame('true', $error->getExtraHeaders()['x-amz-delete-marker']);
            self::assertSame('marker-version', $error->getExtraHeaders()['x-amz-version-id']);
            self::assertSame('Tue, 03 Feb 2026 04:05:06 GMT', $error->getExtraHeaders()['Last-Modified']);
        }
    }

    public function test_response_uses_wire_root_and_canonical_field_order(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'bucket',
            'key',
            3,
            '"etag"',
            storageClass: 'GLACIER',
            systemMetadata: ['checksum-sha256' => 'digest'],
        ));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ObjectSize,StorageClass,Checksum,ETag'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $body = \Amp\ByteStream\buffer((new GetObjectAttributesHandler($metadata))->handleRequest($request)->getBody());
        $xml = new \SimpleXMLElement($body);

        self::assertSame('GetObjectAttributesResponse', $xml->getName());
        self::assertSame(['ETag', 'Checksum', 'StorageClass', 'ObjectSize'], array_map(
            static fn(\SimpleXMLElement $child): string => $child->getName(),
            $xml->xpath('./*') ?: [],
        ));
        self::assertSame('digest', (string) $xml->Checksum->ChecksumSHA256);
        self::assertSame('FULL_OBJECT', (string) $xml->Checksum->ChecksumType);
    }

    public function test_legacy_object_omits_empty_checksum_container_and_type(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, '"etag"'));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'Checksum'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $body = \Amp\ByteStream\buffer((new GetObjectAttributesHandler($metadata))->handleRequest($request)->getBody());

        self::assertStringNotContainsString('<Checksum>', $body);
        self::assertStringNotContainsString('<ChecksumType>', $body);
    }

    public function test_standard_storage_class_is_omitted_from_attributes_response(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, '"etag"'));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'StorageClass'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        $body = \Amp\ByteStream\buffer((new GetObjectAttributesHandler($metadata))->handleRequest($request)->getBody());

        self::assertStringNotContainsString('<StorageClass>', $body);
    }

    public function test_only_a_valid_multipart_etag_claims_object_parts(): void
    {
        foreach ([
            '"opaque-value-2"',
            '"d41d8cd98f00b204e9800998ecf8427-0"',
            '"d41d8cd98f00b204e9800998ecf8427-01"',
            '"d41d8cd98f00b204e9800998ecf8427-10001"',
            '"d41d8cd98f00b204e9800998ecf842-2"',
            '"d41d8cd98f00b204e9800998ecf8427ee-2"',
            '"g41d8cd98f00b204e9800998ecf8427-2"',
        ] as $etag) {
            $body = $this->objectPartsResponse($etag);

            self::assertStringNotContainsString('<ObjectParts>', $body, $etag);
            self::assertStringNotContainsString('<PartsCount>', $body, $etag);
        }
    }

    public function test_multipart_part_count_accepts_the_full_one_to_ten_thousand_range(): void
    {
        foreach ([1, 10_000] as $partsCount) {
            $body = $this->objectPartsResponse('"D41D8CD98F00B204E9800998ECF8427E-' . $partsCount . '"');

            self::assertStringContainsString('<ObjectParts>', $body);
            self::assertStringContainsString('<PartsCount>' . $partsCount . '</PartsCount>', $body);
        }
    }

    public function test_bucket_reuse_during_versioning_lookup_does_not_return_old_object_attributes(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, '"alice-etag"'));
        $metadata->method('getBucketVersioning')->willReturnCallback(static function () use (&$currentBucket, $newBucket): string {
            $currentBucket = $newBucket;

            return 'Enabled';
        });

        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ETag'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $oldBucket->generation);

        $this->expectException(OperationAbortedException::class);
        (new GetObjectAttributesHandler($metadata))->handleRequest($request);
    }

    private function objectPartsResponse(string $etag): string
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 3, $etag));
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ObjectParts'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return \Amp\ByteStream\buffer((new GetObjectAttributesHandler($metadata))->handleRequest($request)->getBody());
    }

    public function test_bucket_reuse_during_delete_marker_lookup_aborts_missing_object_response(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadata')->willReturn(null);
        $metadata->method('getCurrentObjectVersion')->willReturnCallback(
            static function () use (&$currentBucket, $newBucket): ObjectInfo {
                $currentBucket = $newBucket;

                return new ObjectInfo('bucket', 'key', 0, '"marker"', isDeleteMarker: true);
            },
        );

        $this->expectException(OperationAbortedException::class);
        (new GetObjectAttributesHandler($metadata))->handleRequest($this->missingObjectRequest($oldBucket));
    }

    public function test_bucket_reuse_during_list_visibility_lookup_aborts_missing_object_response(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $getBucketCalls = 0;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$getBucketCalls, $oldBucket, $newBucket): BucketInfo {
            ++$getBucketCalls;

            return $getBucketCalls < 3 ? $oldBucket : $newBucket;
        });
        $metadata->method('getObjectMetadata')->willReturn(null);
        $metadata->method('getCurrentObjectVersion')->willReturn(null);

        $this->expectException(OperationAbortedException::class);
        (new GetObjectAttributesHandler($metadata))->handleRequest($this->missingObjectRequest($oldBucket));
    }

    public function test_bucket_reuse_during_explicit_version_lookup_aborts_missing_version_response(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;

        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getObjectMetadataByVersion')->willReturnCallback(
            static function () use (&$currentBucket, $newBucket): null {
                $currentBucket = $newBucket;

                return null;
            },
        );

        $request = $this->missingObjectRequest($oldBucket);
        $request->setUri($request->getUri()->withQuery('attributes&versionId=old-version'));

        $this->expectException(OperationAbortedException::class);
        (new GetObjectAttributesHandler($metadata))->handleRequest($request);
    }

    private function missingObjectRequest(BucketInfo $bucket): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key?attributes'),
            ['x-amz-object-attributes' => 'ETag'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);

        return $request;
    }
}
