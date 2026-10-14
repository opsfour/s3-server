<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Socket\InternetAddress;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use League\Uri\Http;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Bucket\PutBucketCorsHandler;
use OpsFour\S3Server\Handler\Bucket\GetBucketPolicyHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class BucketGenerationWriteRaceTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-write-generation-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_copy_cannot_publish_into_recreated_destination_bucket(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            7,
            '"' . md5('payload') . '"',
            'text/plain',
            'memory://source/key',
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('payload'));
        $storage->method('putObject')->willReturnCallback(function (): StorageWriteResult {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');

            return new StorageWriteResult('memory://target/old-copy', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/old-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/copied',
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('The copy must not commit under the new destination owner.');
        } catch (OperationAbortedException) {
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_copy_cannot_publish_after_source_bucket_is_recreated(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'memory://source/key');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('payload'));
        $storage->method('putObject')->willReturnCallback(function (): StorageWriteResult {
            $this->metadata->deleteObjectMetadata('source', 'key');
            $this->metadata->deleteBucket('owner', 'source');
            $this->metadata->createBucket('owner', 'source', 'us-east-1');

            return new StorageWriteResult('memory://target/old-source-copy', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/old-source-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/copied',
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('The copy must not publish after source bucket recreation.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
            self::assertSame('owner', $this->metadata->getBucketOwner('source'));
        }
    }

    public function test_copy_rejects_source_payload_with_same_size_but_wrong_etag(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'memory://source/key');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('corrupt'));
        $storage->method('putObject')->willReturn(new StorageWriteResult('memory://target/corrupt-copy', 7, md5('corrupt')));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/corrupt-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/copied',
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('A damaged source must not be published with a new ETag.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_upload_part_copy_cannot_publish_after_source_bucket_is_recreated(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'memory://source/key');
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'target', 'key', 'owner');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('payload'));
        $storage->method('putPart')->willReturnCallback(function (): StorageWriteResult {
            $this->metadata->deleteObjectMetadata('source', 'key');
            $this->metadata->deleteBucket('owner', 'source');
            $this->metadata->createBucket('owner', 'source', 'us-east-1');

            return new StorageWriteResult('memory://target/old-source-part', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/old-source-part', 'target');

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/key?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('The copied part must not publish after source bucket recreation.');
        } catch (OperationAbortedException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
        }
    }

    public function test_upload_part_copy_rejects_corrupt_full_source_payload(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'memory://source/key');
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'target', 'key', 'owner');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('corrupt'));
        $storage->method('putPart')->willReturn(new StorageWriteResult('memory://target/corrupt-part', 7, md5('corrupt')));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/corrupt-part', 'target');

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/key?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('A damaged copy source must not publish a part.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_upload_part_copy_rejects_corrupt_non_md5_source_with_stored_checksum(): void
    {
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            7,
            '"multipart-2"',
            'text/plain',
            'memory://source/key',
            checksumSha256: base64_encode(hash('sha256', 'payload', true)),
        );
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'target', 'key', 'owner');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('corrupt'));
        $storage->method('putPart')->willReturn(new StorageWriteResult('memory://target/corrupt-part', 7, md5('corrupt')));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/corrupt-part', 'target');

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/target/key?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/source/key'],
            ));
            self::fail('A damaged source must not publish a part when its ETag is not a simple MD5.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_multipart_completion_cannot_publish_into_recreated_bucket(): void
    {
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'target', 'key', 'owner');
        $partEtag = md5('part');
        $this->metadata->putPart($uploadId, 1, $partEtag, 4, 'memory://target/part');
        $storage = $this->createMock(\OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback(
            static fn(string $bucket, string $key, string $generation, string $attemptId): array
                => \OpsFour\S3Server\Storage\StoragePath::forMultipartCompletion('memory://', $bucket, $key, $generation, $attemptId),
        );
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path): StorageWriteResult {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');

            return new StorageWriteResult($path, 4, md5(md5('part', true)) . '-1');
        });
        $deleted = [];
        $storage->expects(self::exactly(2))->method('deleteObjectByPath')
            ->willReturnCallback(static function (string $path, string $bucket) use (&$deleted): void {
                self::assertSame('target', $bucket);
                $deleted[] = $path;
            });
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"'
            . $partEtag . '"</ETag></Part></CompleteMultipartUpload>';

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest($this->request(
                'POST',
                '/target/key?uploadId=' . $uploadId,
                $body,
            ));
            self::fail('The completed upload must not commit under the new bucket owner.');
        } catch (OperationAbortedException) {
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
            self::assertNull($this->metadata->getObjectMetadata('target', 'key'));
            self::assertCount(2, $deleted);
        }
    }

    public function test_bucket_configuration_cannot_change_same_owner_recreated_bucket(): void
    {
        $body = new ReadableIterableStream((function (): \Generator {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('owner', 'target', 'us-east-1');
            yield '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin>'
                . '<AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>';
        })());
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/target?cors'),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('ownerId', 'owner');

        try {
            (new PutBucketCorsHandler($this->metadata))->handleRequest($request);
            self::fail('The old request must not change the newly created bucket.');
        } catch (OperationAbortedException) {
            self::assertSame([], $this->metadata->getBucketCors('target'));
            self::assertSame('owner', $this->metadata->getBucketOwner('target'));
        }
    }

    public function test_bucket_policy_read_cannot_expose_recreated_owners_policy(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucket($bucket));
        $newPolicy = '{"Statement":[{"Sid":"new-owner-secret"}]}';
        $metadata->method('getBucketPolicy')->willReturnCallback(function () use ($newPolicy): string {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
            $this->metadata->putBucketPolicy('target', $newPolicy);

            return $this->metadata->getBucketPolicy('target') ?? '';
        });

        try {
            (new GetBucketPolicyHandler($metadata))->handleRequest($this->request('GET', '/target?policy'));
            self::fail('The old owner must not receive the new owner\'s policy.');
        } catch (OperationAbortedException) {
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
            self::assertSame($newPolicy, $this->metadata->getBucketPolicy('target'));
        }
    }

    public function test_object_read_cannot_expose_recreated_owners_object(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucket($bucket));
        $metadata->method('getCurrentObjectVersion')->willReturnCallback(function (): ?\OpsFour\S3Server\Dto\ObjectInfo {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
            $this->metadata->putObjectMetadata(
                'target',
                'key',
                'new-owner',
                6,
                '"' . md5('secret') . '"',
                'text/plain',
                'memory://target/secret',
            );

            return $this->metadata->getCurrentObjectVersion('target', 'key');
        });
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        try {
            (new GetObjectHandler($metadata, $storage))->handleRequest($this->request('GET', '/target/key'));
            self::fail('The old owner must not receive the new owner\'s object.');
        } catch (OperationAbortedException) {
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
            self::assertNotNull($this->metadata->getObjectMetadata('target', 'key'));
        }
    }

    public function test_read_rejects_bucket_recreated_after_authorization(): void
    {
        $generation = $this->metadata->getBucket('target')?->generation;
        $this->metadata->deleteBucket('owner', 'target');
        $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
        $this->metadata->putBucketPolicy('target', '{"Statement":[{"Sid":"new-owner-secret"}]}');
        $request = $this->request('GET', '/target?policy');
        $request->setAttribute('s3.cachedBucketGeneration', $generation);

        $this->expectException(OperationAbortedException::class);
        (new GetBucketPolicyHandler($this->metadata))->handleRequest($request);
    }

    public function test_write_rejects_bucket_recreated_after_authorization(): void
    {
        $generation = $this->metadata->getBucket('target')?->generation;
        $this->metadata->deleteBucket('owner', 'target');
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
        $request = $this->request('PUT', '/target?cors', '<CORSConfiguration><CORSRule>'
            . '<AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod>'
            . '</CORSRule></CORSConfiguration>');
        $request->setAttribute('s3.cachedBucketGeneration', $generation);

        try {
            (new PutBucketCorsHandler($this->metadata))->handleRequest($request);
            self::fail('The old authorization must not change the recreated bucket.');
        } catch (OperationAbortedException) {
            self::assertSame([], $this->metadata->getBucketCors('target'));
        }
    }

    public function test_website_request_cannot_serve_private_object_after_bucket_reuse(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucket($bucket));
        $metadata->method('getBucketWebsite')->willReturn(['indexDocument' => 'index.html']);
        $metadata->method('getBucketPolicy')->willReturn('{"Statement":[{"Effect":"Allow","Principal":"*",'
            . '"Action":"s3:GetObject","Resource":"arn:aws:s3:::target/*"}]}');
        $metadata->method('getObjectTagging')->willReturn([]);
        $metadata->method('getObjectMetadata')->willReturnCallback(function (): ?\OpsFour\S3Server\Dto\ObjectInfo {
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
            $this->metadata->putObjectMetadata(
                'target',
                'key',
                'new-owner',
                6,
                '"' . md5('secret') . '"',
                'text/plain',
                'memory://target/secret',
            );

            return $this->metadata->getObjectMetadata('target', 'key');
        });
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, 'GET', Http::new('http://target.s3-website.example.com/key'), [
            'host' => 'target.s3-website.example.com',
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $downstream = $this->createMock(RequestHandler::class);
        $downstream->expects(self::never())->method('handleRequest');

        $this->expectException(OperationAbortedException::class);
        (new WebsiteHostingMiddleware(
            $metadata,
            StorageTierRegistry::single($storage),
            '*.s3-website.example.com',
        ))->handleRequest($request, $downstream);
    }

    public function test_website_object_authorization_rechecks_generation_after_tag_lookup(): void
    {
        $this->assertLateWebsiteReuseBlocked(errorDocument: false, duringStorageOpen: false);
    }

    public function test_website_error_document_authorization_rechecks_generation_after_tag_lookup(): void
    {
        $this->assertLateWebsiteReuseBlocked(errorDocument: true, duringStorageOpen: false);
    }

    public function test_website_object_open_rechecks_generation_before_response(): void
    {
        $this->assertLateWebsiteReuseBlocked(errorDocument: false, duringStorageOpen: true);
    }

    public function test_website_error_document_open_rechecks_generation_before_response(): void
    {
        $this->assertLateWebsiteReuseBlocked(errorDocument: true, duringStorageOpen: true);
    }

    public function test_website_object_stream_rejects_reuse_before_first_read(): void
    {
        $source = new ReadableIterableStream(['old-data']);
        $response = $this->websiteStreamingResponse(false, $source, 8);
        self::assertSame(200, $response->getStatus());
        $this->recreateWebsiteBucket('key');

        try {
            $response->getBody()->read();
            self::fail('A former bucket generation must not emit its first body chunk.');
        } catch (OperationAbortedException) {
            self::assertTrue($source->isClosed());
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
        }
    }

    public function test_website_object_stream_rejects_reuse_between_chunks(): void
    {
        $first = str_repeat('a', 65_536);
        $source = new ReadableIterableStream([$first, 'secret']);
        $response = $this->websiteStreamingResponse(false, $source, strlen($first) + 6);

        self::assertSame($first, $response->getBody()->read());
        $this->recreateWebsiteBucket('key');

        try {
            $response->getBody()->read();
            self::fail('A former bucket generation must not emit another body chunk.');
        } catch (OperationAbortedException) {
            self::assertTrue($source->isClosed());
        }
    }

    public function test_website_error_document_stream_rejects_reuse_before_first_read(): void
    {
        $source = new ReadableIterableStream(['old-error']);
        $response = $this->websiteStreamingResponse(true, $source, 9);
        self::assertSame(404, $response->getStatus());
        $this->recreateWebsiteBucket('error.html');

        try {
            $response->getBody()->read();
            self::fail('A former bucket generation must not emit an error-document body.');
        } catch (OperationAbortedException) {
            self::assertTrue($source->isClosed());
        }
    }

    public function test_website_access_denied_redirect_rechecks_generation_after_acl_lookup(): void
    {
        $this->metadata->putBucketWebsite('target', 'index.html', routingRules: [[
            'condition' => ['httpErrorCodeReturnedEquals' => 403],
            'redirect' => ['hostName' => 'example.com'],
        ]]);
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucket($bucket));
        $metadata->method('getBucketWebsite')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucketWebsite($bucket));
        $recreated = false;
        $metadata->method('getAcl')->willReturnCallback(function () use (&$recreated): array {
            if (! $recreated) {
                $recreated = true;
                $this->metadata->deleteBucket('owner', 'target');
                $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
            }

            return [];
        });

        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, 'GET', Http::new('http://target.s3-website.example.com/missing'), [
            'host' => 'target.s3-website.example.com',
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $downstream = $this->createMock(RequestHandler::class);
        $downstream->expects(self::never())->method('handleRequest');

        $this->expectException(OperationAbortedException::class);
        (new WebsiteHostingMiddleware($metadata, StorageTierRegistry::single($storage), '*.s3-website.example.com'))
            ->handleRequest($request, $downstream);
    }

    private function assertLateWebsiteReuseBlocked(bool $errorDocument, bool $duringStorageOpen): void
    {
        $objectKey = $errorDocument ? 'error.html' : 'key';
        $requestKey = $errorDocument ? 'missing' : $objectKey;
        $this->metadata->putBucketWebsite('target', 'index.html', $errorDocument ? $objectKey : null);
        $this->metadata->putObjectMetadata(
            'target',
            $objectKey,
            'owner',
            8,
            '"' . md5('old-data') . '"',
            'text/plain',
            'memory://target/old-data',
        );
        $this->metadata->putBucketPolicy('target', '{"Statement":[{"Effect":"Allow","Principal":"*",'
            . '"Action":"s3:GetObject","Resource":"arn:aws:s3:::target/*"}]}');

        $recreated = false;
        $recreateBucket = function () use (&$recreated, $objectKey): void {
            if ($recreated) {
                return;
            }
            $recreated = true;
            $this->metadata->deleteObjectMetadata('target', $objectKey);
            $this->metadata->deleteBucket('owner', 'target');
            $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
        };
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucket($bucket));
        $metadata->method('getBucketWebsite')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucketWebsite($bucket));
        $metadata->method('getObjectMetadata')->willReturnCallback(fn(string $bucket, string $key) => $this->metadata->getObjectMetadata($bucket, $key));
        $metadata->method('getBucketPolicy')->willReturnCallback(fn(string $bucket) => $this->metadata->getBucketPolicy($bucket));
        $metadata->method('getObjectTagging')->willReturnCallback(function () use ($duringStorageOpen, $recreateBucket): array {
            if (! $duringStorageOpen) {
                $recreateBucket();
            }

            return [];
        });

        $stream = new ReadableIterableStream(['old-data']);
        $storage = $this->createMock(StorageBackend::class);
        if ($duringStorageOpen) {
            $storage->expects(self::once())->method('getObjectByPath')->willReturnCallback(function () use ($recreateBucket, $stream): ReadableIterableStream {
                $recreateBucket();

                return $stream;
            });
        } else {
            $storage->expects(self::never())->method('getObjectByPath');
        }

        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, 'GET', Http::new('http://target.s3-website.example.com/' . $requestKey), [
            'host' => 'target.s3-website.example.com',
        ]);
        $downstream = $this->createMock(RequestHandler::class);
        $downstream->expects(self::never())->method('handleRequest');

        try {
            (new WebsiteHostingMiddleware($metadata, StorageTierRegistry::single($storage), '*.s3-website.example.com'))
                ->handleRequest($request, $downstream);
            self::fail('Website content from a former bucket generation was served.');
        } catch (OperationAbortedException) {
            self::assertTrue($recreated);
            self::assertSame('new-owner', $this->metadata->getBucketOwner('target'));
            if ($duringStorageOpen) {
                self::assertTrue($stream->isClosed());
            }
        }
    }

    private function websiteStreamingResponse(bool $errorDocument, ReadableStream $source, int $size): \Amp\Http\Server\Response
    {
        $objectKey = $errorDocument ? 'error.html' : 'key';
        $requestKey = $errorDocument ? 'missing' : $objectKey;
        $this->metadata->putBucketWebsite('target', 'index.html', $errorDocument ? $objectKey : null);
        $this->metadata->putObjectMetadata(
            'target',
            $objectKey,
            'owner',
            $size,
            '"etag"',
            'text/plain',
            'memory://target/' . $objectKey,
        );
        $this->metadata->putBucketPolicy('target', '{"Statement":[{"Effect":"Allow","Principal":"*",'
            . '"Action":"s3:GetObject","Resource":"arn:aws:s3:::target/*"}]}');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')->willReturn($source);
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, 'GET', Http::new('http://target.s3-website.example.com/' . $requestKey), [
            'host' => 'target.s3-website.example.com',
        ]);
        $downstream = $this->createMock(RequestHandler::class);
        $downstream->expects(self::never())->method('handleRequest');

        return (new WebsiteHostingMiddleware(
            $this->metadata,
            StorageTierRegistry::single($storage),
            '*.s3-website.example.com',
        ))->handleRequest($request, $downstream);
    }

    private function recreateWebsiteBucket(string $objectKey): void
    {
        $this->metadata->deleteObjectMetadata('target', $objectKey);
        $this->metadata->deleteBucket('owner', 'target');
        $this->metadata->createBucket('new-owner', 'target', 'us-east-1');
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $path), $headers, $body);
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', str_starts_with($path, '/target/copied') ? 'copied' : 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
