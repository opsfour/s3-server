<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\ListPartsHandler;
use OpsFour\S3Server\Handler\Multipart\ListMultipartUploadsHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class MultipartGenerationReadBoundaryTest extends TestCase
{
    public function test_custom_metadata_without_generation_bound_listing_fails_closed(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->expects(self::never())->method('listMultipartUploads');

        $this->expectException(NotImplementedException::class);
        (new ListMultipartUploadsHandler($metadata))->handleRequest($this->request('GET', 'bob'));
    }

    public function test_new_bucket_owner_cannot_list_parts_of_stale_upload(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturn(self::oldUpload());
        $metadata->method('getParts')->willReturn([
            ['part_number' => 1, 'etag' => '"old"', 'size' => 3, 'storage_path' => 'old-path', 'created_at' => '2026-01-01T00:00:00Z'],
        ]);

        $this->expectException(NoSuchUploadException::class);
        (new ListPartsHandler($metadata))->handleRequest($this->request('GET', 'bob'));
    }

    public function test_bucket_owner_enforced_list_parts_distinguishes_initiator_and_object_owner(): void
    {
        $upload = self::oldUpload();
        $upload['bucket_generation'] = str_repeat('b', 32);
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturn($upload);
        $metadata->method('getParts')->willReturn([]);
        $metadata->method('getBucketOwnershipControls')->willReturn('BucketOwnerEnforced');

        $response = (new ListPartsHandler($metadata))->handleRequest($this->request('GET', 'bob'));
        $xml = \Amp\ByteStream\buffer($response->getBody());

        self::assertStringContainsString('<Initiator><ID>alice</ID>', $xml);
        self::assertStringContainsString('<Owner><ID>bob</ID>', $xml);
    }

    public function test_bucket_owner_enforced_upload_listing_distinguishes_initiator_and_object_owner(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            GenerationBoundMultipartListingStore::class,
        ]);
        if (! $metadata instanceof MetadataStore) {
            throw new \LogicException('Intersection mock does not implement MetadataStore.');
        }
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('getBucketOwnershipControls')->willReturn('BucketOwnerEnforced');
        $metadata->method('listMultipartUploadsForBucket')->willReturn([
            'uploads' => [[
                'upload_id' => 'upload',
                'bucket' => 'bucket',
                'key_name' => 'key',
                'owner_id' => 'alice',
                'content_type' => 'application/octet-stream',
                'user_metadata' => '{}',
                'created_at' => '2026-01-01T00:00:00Z',
            ]],
            'isTruncated' => false,
            'nextKeyMarker' => null,
            'nextUploadIdMarker' => null,
            'commonPrefixes' => [],
        ]);

        $response = (new ListMultipartUploadsHandler($metadata))->handleRequest($this->request('GET', 'bob'));
        $xml = \Amp\ByteStream\buffer($response->getBody());

        self::assertStringContainsString('<Initiator><ID>alice</ID>', $xml);
        self::assertStringContainsString('<Owner><ID>bob</ID>', $xml);
    }

    public function test_upload_id_marker_without_key_marker_is_ignored(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            GenerationBoundMultipartListingStore::class,
        ]);
        if (! $metadata instanceof MetadataStore) {
            throw new \LogicException('Intersection mock does not implement MetadataStore.');
        }
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner', generation: str_repeat('b', 32)));
        $metadata->expects(self::once())->method('listMultipartUploadsForBucket')
            ->with(self::isInstanceOf(BucketInfo::class), null, null, 1000, null, 'orphaned')
            ->willReturn([
                'uploads' => [],
                'isTruncated' => false,
                'nextKeyMarker' => null,
                'nextUploadIdMarker' => null,
                'commonPrefixes' => [],
            ]);

        $response = (new ListMultipartUploadsHandler($metadata))->handleRequest(
            $this->request('GET', 'owner', query: '?uploads&upload-id-marker=orphaned'),
        );

        self::assertSame(200, $response->getStatus());
    }

    public function test_list_parts_rechecks_generation_after_ownership_lookup(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(
            static function () use (&$currentBucket): BucketInfo {
                return $currentBucket;
            },
        );
        $metadata->method('getMultipartUpload')->willReturn(self::oldUpload());
        $metadata->method('getParts')->willReturn([
            ['part_number' => 1, 'etag' => '"old"', 'size' => 3, 'storage_path' => 'old-path', 'created_at' => '2026-01-01T00:00:00Z'],
        ]);
        $metadata->method('getBucketOwnershipControls')->willReturnCallback(
            static function () use (&$currentBucket, $newBucket): ?string {
                $currentBucket = $newBucket;

                return null;
            },
        );

        $this->expectException(OperationAbortedException::class);
        (new ListPartsHandler($metadata))->handleRequest($this->request('GET', 'alice', $oldBucket->generation));
    }

    public function test_multipart_listing_rechecks_generation_after_ownership_lookup(): void
    {
        $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
        $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
        $currentBucket = $oldBucket;
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            GenerationBoundMultipartListingStore::class,
        ]);
        if (! $metadata instanceof MetadataStore) {
            throw new \LogicException('Intersection mock does not implement MetadataStore.');
        }
        $metadata->method('getBucket')->willReturnCallback(
            static function () use (&$currentBucket): BucketInfo {
                return $currentBucket;
            },
        );
        $metadata->method('listMultipartUploadsForBucket')->willReturn([
            'uploads' => [[
                'upload_id' => 'old-upload',
                'bucket' => 'bucket',
                'key_name' => 'key',
                'owner_id' => 'alice',
                'content_type' => 'application/octet-stream',
                'user_metadata' => '{}',
                'created_at' => '2026-01-01T00:00:00Z',
            ]],
            'isTruncated' => false,
            'nextKeyMarker' => null,
            'nextUploadIdMarker' => null,
            'commonPrefixes' => [],
        ]);
        $metadata->method('getBucketOwnershipControls')->willReturnCallback(
            static function () use (&$currentBucket, $newBucket): ?string {
                $currentBucket = $newBucket;

                return null;
            },
        );

        $this->expectException(OperationAbortedException::class);
        (new ListMultipartUploadsHandler($metadata))->handleRequest(
            $this->request('GET', 'alice', $oldBucket->generation),
        );
    }

    public function test_new_bucket_owner_cannot_abort_stale_upload(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturn(self::oldUpload());
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::never())->method('deleteMultipartUpload');

        $this->expectException(NoSuchUploadException::class);
        (new AbortMultipartUploadHandler($metadata, new InMemoryBackend()))
            ->handleRequest($this->request('DELETE', 'bob'));
    }

    public function test_abort_rechecks_upload_generation_when_staging_cleanup(): void
    {
        $currentUpload = self::oldUpload();
        $currentUpload['bucket_generation'] = str_repeat('b', 32);
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'alice', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturnOnConsecutiveCalls($currentUpload, self::oldUpload());
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::never())->method('deleteMultipartUpload');

        $this->expectException(OperationAbortedException::class);
        (new AbortMultipartUploadHandler($metadata, new InMemoryBackend()))
            ->handleRequest($this->request('DELETE', 'alice'));
    }

    public function test_pre_generation_legacy_upload_remains_listable_and_abortable(): void
    {
        $legacyUpload = self::oldUpload();
        $legacyUpload['bucket_generation'] = '';
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'alice', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturn($legacyUpload);
        $metadata->method('getParts')->willReturn([]);
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::once())->method('deleteMultipartUpload')->with('old-upload');

        self::assertSame(200, (new ListPartsHandler($metadata))->handleRequest($this->request('GET', 'alice'))->getStatus());
        self::assertSame(204, (new AbortMultipartUploadHandler($metadata, new InMemoryBackend()))
            ->handleRequest($this->request('DELETE', 'alice'))->getStatus());
    }

    public function test_new_bucket_owner_cannot_use_unbound_legacy_upload(): void
    {
        $legacyUpload = self::oldUpload();
        $legacyUpload['bucket_generation'] = '';
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32)));
        $metadata->method('getMultipartUpload')->willReturn($legacyUpload);
        $metadata->expects(self::never())->method('getParts');
        $metadata->expects(self::never())->method('deleteMultipartUpload');

        $rejections = 0;
        foreach (['GET', 'DELETE'] as $method) {
            try {
                $handler = $method === 'GET'
                    ? new ListPartsHandler($metadata)
                    : new AbortMultipartUploadHandler($metadata, new InMemoryBackend());
                $handler->handleRequest($this->request($method, 'bob'));
                self::fail("{$method} accepted an unbound upload from another owner.");
            } catch (NoSuchUploadException) {
                ++$rejections;
            }
        }
        self::assertSame(2, $rejections);
    }

    public function test_disappearing_upload_after_bucket_reuse_aborts_before_no_such_upload(): void
    {
        foreach (['GET', 'DELETE', 'POST'] as $method) {
            $oldBucket = new BucketInfo('bucket', 'alice', generation: str_repeat('a', 32));
            $newBucket = new BucketInfo('bucket', 'bob', generation: str_repeat('b', 32));
            $currentBucket = $oldBucket;
            $metadata = $this->createMock(MetadataStore::class);
            $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
                return $currentBucket;
            });
            $metadata->method('getMultipartUpload')->willReturnCallback(
                static function () use (&$currentBucket, $newBucket): ?array {
                    $currentBucket = $newBucket;

                    return null;
                },
            );

            $storage = new InMemoryBackend();
            $handler = match ($method) {
                'GET' => new ListPartsHandler($metadata),
                'DELETE' => new AbortMultipartUploadHandler($metadata, $storage),
                'POST' => new CompleteMultipartUploadHandler($metadata, $storage),
            };
            try {
                $handler->handleRequest($this->request($method, 'alice', $oldBucket->generation));
                self::fail("{$method} accepted an upload after bucket reuse.");
            } catch (OperationAbortedException) {
                self::assertSame($newBucket, $currentBucket);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function oldUpload(): array
    {
        return [
            'upload_id' => 'old-upload',
            'bucket' => 'bucket',
            'bucket_generation' => str_repeat('a', 32),
            'key_name' => 'key',
            'owner_id' => 'alice',
            'storage_tier' => 'STANDARD',
            'content_type' => 'application/octet-stream',
            'user_metadata' => [],
            'created_at' => '2026-01-01T00:00:00Z',
        ];
    }

    /** @param non-empty-string $method */
    private function request(
        string $method,
        string $ownerId,
        ?string $generation = null,
        string $query = '?uploadId=old-upload',
    ): Request {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/bucket/key' . $query),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', $ownerId);
        if ($generation !== null) {
            $request->setAttribute('s3.cachedBucketGeneration', $generation);
        }

        return $request;
    }
}
