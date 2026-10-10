<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessControlListNotSupportedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ObjectOwnershipModeSwitchTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-ownership-switch-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->metadata->putBucketOwnershipControls('bucket', 'ObjectWriter');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_mode_change_during_storage_write_rejects_unsupported_acl_before_commit(): void
    {
        $storage = $this->storageThatSwitchesMode();
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/key', 'bucket');
        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request(['x-amz-acl' => 'public-read']));
            self::fail('A mode change must reject the ACL before object metadata commits.');
        } catch (AccessControlListNotSupportedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            self::assertSame('BucketOwnerEnforced', $this->metadata->getBucketOwnershipControls('bucket'));
        }
    }

    public function test_mode_change_during_storage_write_assigns_new_object_to_bucket_owner(): void
    {
        $storage = $this->storageThatSwitchesMode();
        $response = (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request());
        self::assertSame(200, $response->getStatus());
        self::assertSame('bucket-owner', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
        self::assertSame('bucket-owner', $this->metadata->getAcl('object', ObjectVersionResolver::aclResourceName('bucket', 'key', null))[0]['granteeId'] ?? null);
    }

    public function test_bucket_name_reuse_during_storage_write_cannot_publish_into_new_bucket(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(function (string $bucket): StorageWriteResult {
            $this->metadata->deleteBucket('bucket-owner', $bucket);
            $this->metadata->createBucket('new-owner', $bucket, 'us-east-1');

            return new StorageWriteResult('memory://bucket/old-write', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/old-write', 'bucket');

        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request());
            self::fail('The in-flight write must not become an object in the new bucket generation.');
        } catch (OperationAbortedException) {
            self::assertSame('new-owner', $this->metadata->getBucketOwner('bucket'));
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
    }

    public function test_same_owner_bucket_recreation_during_versioned_write_is_rejected(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $oldGeneration = $this->metadata->getBucket('bucket')?->generation;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(function (string $bucket): StorageWriteResult {
            $this->metadata->deleteBucket('bucket-owner', $bucket);
            $this->metadata->createBucket('bucket-owner', $bucket, 'us-east-1');

            return new StorageWriteResult('memory://bucket/old-write', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/old-write', 'bucket');

        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request());
            self::fail('The recreated bucket must reject an in-flight write from its former generation.');
        } catch (OperationAbortedException) {
            self::assertNotSame($oldGeneration, $this->metadata->getBucket('bucket')?->generation);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
    }

    public function test_bucket_deletion_during_storage_write_cleans_unpublished_bytes(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(function (string $bucket): StorageWriteResult {
            $this->metadata->deleteBucket('bucket-owner', $bucket);

            return new StorageWriteResult('memory://bucket/old-write', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/old-write', 'bucket');

        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request());
            self::fail('The deleted bucket must not leave an unpublished physical write.');
        } catch (NoSuchBucketException) {
            self::assertNull($this->metadata->getBucket('bucket'));
        }
    }

    private function storageThatSwitchesMode(): StorageBackend&MockObject
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(function (string $bucket, string $key, ReadableStream $body): StorageWriteResult {
            self::assertSame('payload', \Amp\ByteStream\buffer($body));
            $this->metadata->putBucketOwnershipControls($bucket, 'BucketOwnerEnforced');

            return new StorageWriteResult("memory://{$bucket}/{$key}", 7, md5('payload'));
        });

        return $storage;
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/key'), $headers, 'payload');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'writer');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        return $request;
    }
}
