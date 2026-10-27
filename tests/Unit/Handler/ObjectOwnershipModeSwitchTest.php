<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
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

    public function test_mode_change_during_storage_write_rejects_public_acl_decision_before_commit(): void
    {
        $storage = $this->storageThatSwitchesMode();
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/key', 'bucket');
        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request(['x-amz-acl' => 'public-read']));
            self::fail('A mode change must reject the ACL before object metadata commits.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            self::assertSame('BucketOwnerEnforced', $this->metadata->getBucketOwnershipControls('bucket'));
        }
    }

    public function test_mode_change_during_storage_write_does_not_reinterpret_owner_or_acl(): void
    {
        $storage = $this->storageThatSwitchesMode();
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/key', 'bucket');
        try {
            (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request());
            self::fail('A mode change must not reinterpret the preflight ownership decision.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            self::assertSame([], $this->metadata->getAcl(
                'object',
                ObjectVersionResolver::aclResourceName('bucket', 'key', null),
            ));
        }
    }

    public function test_copy_mode_change_cleans_bytes_without_reinterpreting_preferred_decision(): void
    {
        $this->metadata->putBucketOwnershipControls('bucket', 'BucketOwnerPreferred');
        $this->metadata->putObjectMetadata(
            'bucket',
            'source',
            'writer',
            7,
            '"' . md5('payload') . '"',
            'text/plain',
            'memory://bucket/source',
        );
        $this->metadata->putAccountPolicy('writer', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/source',
            ]],
        ], JSON_THROW_ON_ERROR));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->with('memory://bucket/source')->willReturn(
            new \Amp\ByteStream\ReadableBuffer('payload'),
        );
        $storage->method('putObject')->willReturnCallback(function (): StorageWriteResult {
            $this->metadata->putBucketOwnershipControls('bucket', 'ObjectWriter');

            return new StorageWriteResult('memory://bucket/copy', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/copy', 'bucket');
        $request = $this->request([
            'x-amz-copy-source' => '/bucket/source',
            'x-amz-acl' => 'bucket-owner-full-control',
        ]);
        $request->setAttribute('s3.key', 'copy');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($request);
            self::fail('A copy must not reinterpret its Preferred ownership decision after storage I/O.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'copy'));
        }
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
