<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Bucket\ListObjectVersionsHandler;
use OpsFour\S3Server\Handler\Bucket\ListObjectsHandler;
use OpsFour\S3Server\Handler\Bucket\ListObjectsV2Handler;
use OpsFour\S3Server\Metadata\MetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectListingContractTest extends TestCase
{
    public function test_list_v2_rejects_invalid_fetch_owner_before_listing(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->expects(self::never())->method('listObjects');

        $this->expectException(InvalidArgumentException::class);
        (new ListObjectsV2Handler($metadata))->handleRequest($this->request('?list-type=2&fetch-owner=banana'));
    }

    public function test_list_v1_omits_next_marker_without_delimiter(): void
    {
        $metadata = $this->metadataWithResult(new ListObjectsResult(
            name: 'bucket',
            maxKeys: 1,
            isTruncated: true,
            keyCount: 1,
            objects: [new ObjectInfo('bucket', 'a', 1, '"etag"', ownerId: 'owner')],
        ));

        $response = (new ListObjectsHandler($metadata))->handleRequest($this->request('?max-keys=1'));
        self::assertStringNotContainsString('<NextMarker>', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_list_v1_emits_next_marker_with_delimiter(): void
    {
        $metadata = $this->metadataWithResult(new ListObjectsResult(
            name: 'bucket',
            delimiter: '/',
            maxKeys: 1,
            isTruncated: true,
            commonPrefixes: ['a/'],
        ));

        $response = (new ListObjectsHandler($metadata))->handleRequest($this->request('?delimiter=%2F&max-keys=1'));
        self::assertStringContainsString('<NextMarker>a/</NextMarker>', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_list_versions_rejects_version_marker_without_key_marker_before_listing(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->expects(self::never())->method('listObjectVersions');

        $this->expectException(InvalidArgumentException::class);
        (new ListObjectVersionsHandler($metadata))->handleRequest(
            $this->request('?versions&version-id-marker=version'),
        );
    }

    public function test_list_versions_accepts_paired_key_and_version_markers(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->expects(self::once())->method('listObjectVersions')
            ->with('bucket', null, null, 1000, 'key', 'version')
            ->willReturn([
                'versions' => [],
                'deleteMarkers' => [],
                'isTruncated' => false,
                'nextKeyMarker' => null,
                'nextVersionIdMarker' => null,
                'commonPrefixes' => [],
            ]);

        $response = (new ListObjectVersionsHandler($metadata))->handleRequest(
            $this->request('?versions&key-marker=key&version-id-marker=version'),
        );

        self::assertSame(200, $response->getStatus());
    }

    private function metadataWithResult(ListObjectsResult $result): MetadataStore
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('listObjects')->willReturn($result);

        return $metadata;
    }

    private function request(string $query): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket' . $query),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('credential', null);

        return $request;
    }
}
