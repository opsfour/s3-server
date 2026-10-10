<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PutObjectCommitAcknowledgementTest extends TestCase
{
    #[DataProvider('versioningModes')]
    public function test_lost_commit_ack_does_not_delete_a_published_object(string $versioning): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $bucket = new BucketInfo('bucket', 'owner', generation: str_repeat('a', 32));
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getBucketVersioning')->willReturn($versioning);
        $published = null;
        $metadata->method('getObjectMetadata')->willReturnCallback(
            static function (string $bucket, string $key) use (&$published): ?ObjectInfo {
                return $published;
            },
        );
        $metadata->method('getObjectMetadataByVersion')->willReturnCallback(
            static function (string $bucket, string $key, string $versionId) use (&$published): ?ObjectInfo {
                return $versionId === 'version-1' ? $published : null;
            },
        );
        $metadata->method('putObjectMetadata')->willReturnCallback(
            static function (string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath) use (&$published): void {
                $published = new ObjectInfo($bucket, $key, $size, $etag, ownerId: $ownerId, systemMetadata: ['storagePath' => $storagePath]);
            },
        );
        $metadata->method('putObjectVersioned')->willReturnCallback(
            static function (string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath) use (&$published): string {
                $published = new ObjectInfo($bucket, $key, $size, $etag, ownerId: $ownerId, versionId: 'version-1', systemMetadata: ['storagePath' => $storagePath]);

                return 'version-1';
            },
        );
        $metadata->method('transaction')->willReturnCallback(static function (callable $callback): never {
            $callback();
            throw new \RuntimeException('commit acknowledgement lost');
        });

        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturn(new StorageWriteResult('bucket/physical-attempt', 7, md5('payload')));
        $storage->expects(self::never())->method('deleteObjectByPath');
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => '7'],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        try {
            (new PutObjectHandler($metadata, $storage))->handleRequest($request);
            self::fail('Expected the lost commit acknowledgement.');
        } catch (\RuntimeException $error) {
            self::assertSame('commit acknowledgement lost', $error->getMessage());
        }
        self::assertInstanceOf(ObjectInfo::class, $published);
        self::assertSame('bucket/physical-attempt', $published->systemMetadata['storagePath']);
    }

    public function test_failure_before_publication_still_cleans_unreferenced_path(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner', generation: str_repeat('a', 32)));
        $metadata->method('getBucketVersioning')->willReturn('Disabled');
        $metadata->method('transaction')->willThrowException(new \RuntimeException('pre-publication failure'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturn(new StorageWriteResult('bucket/uncommitted', 7, md5('payload')));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('bucket/uncommitted', 'bucket');
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/key'), ['content-length' => '7'], 'payload');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        try {
            (new PutObjectHandler($metadata, $storage))->handleRequest($request);
            self::fail('Expected the pre-publication failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('pre-publication failure', $error->getMessage());
        }
    }

    public function test_lost_replacement_commit_ack_has_already_staged_old_path_for_recovery(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $bucket = new BucketInfo('bucket', 'owner', generation: str_repeat('a', 32));
        $old = new ObjectInfo('bucket', 'key', 3, '"' . md5('old') . '"', ownerId: 'owner', systemMetadata: ['storagePath' => 'bucket/old-path']);
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->method('getBucketVersioning')->willReturn('Disabled');
        $metadata->method('getObjectMetadataByVersion')->willReturn($old);
        $metadata->expects(self::once())->method('enqueueStorageGarbage')->with('bucket', 'STANDARD', 'bucket/old-path');
        $metadata->method('transaction')->willReturnCallback(static function (callable $callback): never {
            $callback();
            throw new \RuntimeException('replacement commit acknowledgement lost');
        });
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturn(new StorageWriteResult('bucket/new-path', 3, md5('new')));
        $storage->expects(self::never())->method('deleteObjectByPath');
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/key'), ['content-length' => '3'], 'new');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        $this->expectExceptionMessage('replacement commit acknowledgement lost');
        (new PutObjectHandler($metadata, $storage))->handleRequest($request);
    }

    /** @return iterable<string, array{string}> */
    public static function versioningModes(): iterable
    {
        yield 'unversioned' => ['Disabled'];
        yield 'versioned' => ['Enabled'];
    }
}
