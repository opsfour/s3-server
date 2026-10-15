<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartRestorePolicyCommitTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-multipart-policy-commit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_upload_part_revocation_during_body_read_keeps_part_uncommitted(): void
    {
        $this->metadata->createMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bucket', 'key', 'owner');
        $body = new ReadableIterableStream((function (): \Generator {
            $this->deny('s3:PutObject');
            yield 'part-data';
        })());
        $request = $this->request('PUT', '/bucket/key?uploadId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&partNumber=1', S3Operation::UploadPart, $body);

        $this->assertDenied(function () use ($request): void {
            $this->handle($request, new UploadPartHandler($this->metadata, $this->storage));
        });
        self::assertSame([], $this->metadata->getParts('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_upload_part_copy_revocation_before_commit_keeps_part_uncommitted(): void
    {
        $this->metadata->createMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bucket', 'key', 'owner');
        $source = $this->storage->putObject('bucket', 'source', new ReadableBuffer('source-data'));
        $this->metadata->putObjectMetadata('bucket', 'source', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
        $request = $this->request('PUT', '/bucket/key?uploadId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&partNumber=1', S3Operation::UploadPartCopy);
        $request->setHeader('x-amz-copy-source', '/bucket/source');

        $this->assertDenied(function () use ($request): void {
            $this->handle(
                $request,
                new UploadPartCopyHandler($this->metadata, $this->storage),
                fn() => $this->deny('s3:PutObject'),
            );
        });
        self::assertSame([], $this->metadata->getParts('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_upload_part_copy_source_version_deny_during_read_keeps_part_uncommitted(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->createMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bucket', 'key', 'owner');
        $source = $this->storage->putObject('bucket', 'source', new ReadableBuffer('source-data'));
        $versionId = $this->metadata->putObjectVersioned('bucket', 'source', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
        $storage = new CallbackStorageBackend($this->storage, beforeRead: fn() => $this->deny('s3:GetObjectVersion', 'source'));
        $request = $this->request('PUT', '/bucket/key?uploadId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&partNumber=1', S3Operation::UploadPartCopy);
        $request->setHeader('x-amz-copy-source', '/bucket/source?versionId=' . $versionId);

        $this->assertDenied(function () use ($request, $storage): void {
            $this->handle($request, new UploadPartCopyHandler($this->metadata, $storage));
        });
        self::assertSame([], $this->metadata->getParts('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'source', $versionId));
    }

    /** @return iterable<string, array{bool}> */
    public static function copyVersioningModes(): iterable
    {
        yield 'unversioned with named source policy' => [false];
        yield 'versioned with explicit source version' => [true];
    }

    #[DataProvider('copyVersioningModes')]
    public function test_copy_object_source_permission_revoked_during_read_does_not_publish_destination(bool $versioned): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('bucket', 'Enabled');
        } else {
            $this->metadata->putNamedPolicy('source-policy', '{"Statement":[]}');
        }
        $cold = new InMemoryBackend();
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'source', new ReadableBuffer('source-data'));
        $versionId = $versioned
            ? $this->metadata->putObjectVersioned('bucket', 'source', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path)
            : null;
        if (!$versioned) {
            $this->metadata->putObjectMetadata('bucket', 'source', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
        }
        $this->metadata->updateObjectPlacement('bucket', 'source', $versionId, 'COLD', 'COLD', $source->path);
        $coldWithRevocation = new CallbackStorageBackend($cold, beforeRead: function () use ($versioned): void {
            if ($versioned) {
                $this->deny('s3:GetObjectVersion', 'source');
            } else {
                $this->metadata->putNamedPolicy('source-policy', $this->denyPolicy('s3:GetObject', 'source'));
            }
        });
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('COLD', $coldWithRevocation),
        ]);
        $request = $this->request('PUT', '/bucket/destination', S3Operation::CopyObject);
        $request->setAttribute('s3.key', 'destination');
        $request->setHeader('x-amz-copy-source', '/bucket/source' . ($versionId !== null ? '?versionId=' . $versionId : ''));
        if (!$versioned) {
            $request->setAttribute('credential', new Credential('source-key', 'secret', 'owner', policyNames: ['source-policy']));
        }

        $this->assertDenied(function () use ($request, $tiers): void {
            $this->handle($request, new CopyObjectHandler($this->metadata, $this->storage, storageTiers: $tiers));
        });
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'destination'));
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'source'));
    }

    public function test_cross_owner_source_bucket_policy_revocation_during_copy_blocks_commit(): void
    {
        $this->metadata->createBucket('source-owner', 'source-bucket', 'us-east-1');
        $this->metadata->putAccountPolicy('owner', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::source-bucket/source',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->metadata->putBucketPolicy('source-bucket', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'owner'],
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::source-bucket/source',
            ]],
        ], JSON_THROW_ON_ERROR));
        $cold = new InMemoryBackend();
        $cold->createBucket('source-bucket');
        $source = $cold->putObject('source-bucket', 'source', new ReadableBuffer('source-data'));
        $this->metadata->putObjectMetadata('source-bucket', 'source', 'source-owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
        $this->metadata->updateObjectPlacement('source-bucket', 'source', null, 'COLD', 'COLD', $source->path);
        $coldWithRevocation = new CallbackStorageBackend(
            $cold,
            beforeRead: fn() => $this->metadata->deleteBucketPolicy('source-bucket'),
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('COLD', $coldWithRevocation),
        ]);
        $request = $this->request('PUT', '/bucket/destination', S3Operation::CopyObject);
        $request->setAttribute('s3.key', 'destination');
        $request->setHeader('x-amz-copy-source', '/source-bucket/source');

        $this->assertDenied(function () use ($request, $tiers): void {
            $this->handle($request, new CopyObjectHandler($this->metadata, $this->storage, storageTiers: $tiers));
        });
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'destination'));
        self::assertNull($this->metadata->getBucketPolicy('source-bucket'));
    }

    public function test_create_multipart_revocation_before_commit_creates_no_upload(): void
    {
        $request = $this->request('POST', '/bucket/key?uploads', S3Operation::CreateMultipartUpload);

        $this->assertDenied(function () use ($request): void {
            $this->handle(
                $request,
                new CreateMultipartUploadHandler($this->metadata),
                fn() => $this->deny('s3:PutObject'),
            );
        });
        self::assertSame([], $this->metadata->listMultipartUploads('bucket', null, null, 1000, null, null)['uploads']);
    }

    public function test_abort_multipart_revocation_before_commit_preserves_upload_and_parts(): void
    {
        $this->metadata->createMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bucket', 'key', 'owner');
        $part = $this->storage->putPart('bucket', 'key', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1, '"' . $part->md5Hex . '"', $part->size, $part->path);
        $request = $this->request('DELETE', '/bucket/key?uploadId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', S3Operation::AbortMultipartUpload);

        $this->assertDenied(function () use ($request): void {
            $this->handle(
                $request,
                new AbortMultipartUploadHandler($this->metadata, $this->storage),
                fn() => $this->deny('s3:AbortMultipartUpload'),
            );
        });
        self::assertNotNull($this->metadata->getMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
        self::assertCount(1, $this->metadata->getParts('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function test_delegated_abort_requires_resource_allow_at_commit_without_blocking_upload_or_bucket_owner(): void
    {
        $delegatedUploadId = str_repeat('a', 32);
        $bucketOwnerUploadId = str_repeat('b', 32);
        $initiatorUploadId = str_repeat('c', 32);
        foreach ([$delegatedUploadId, $bucketOwnerUploadId, $initiatorUploadId] as $uploadId) {
            $this->metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'tenant:uploader');
        }
        $part = $this->storage->putPart('bucket', 'key', $delegatedUploadId, 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart($delegatedUploadId, 1, '"' . $part->md5Hex . '"', $part->size, $part->path);

        foreach (['tenant:delegate', 'tenant:uploader'] as $principal) {
            $this->metadata->putAccountPolicy($principal, $this->allowAbortPolicy());
        }
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'tenant:delegate'],
                'Action' => 's3:AbortMultipartUpload',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ]],
        ], JSON_THROW_ON_ERROR));

        $delegated = $this->request(
            'DELETE',
            '/bucket/key?uploadId=' . $delegatedUploadId,
            S3Operation::AbortMultipartUpload,
        );
        $delegated->setAttribute('ownerId', 'tenant:delegate');
        try {
            $this->handle(
                $delegated,
                new AbortMultipartUploadHandler($this->metadata, $this->storage),
                fn() => $this->metadata->deleteBucketPolicy('bucket'),
            );
            self::fail('A delegated abort must retain its resource-policy Allow through commit.');
        } catch (NoSuchUploadException) {
        }
        self::assertNotNull($this->metadata->getMultipartUpload($delegatedUploadId));
        self::assertCount(1, $this->metadata->getParts($delegatedUploadId));
        self::assertSame('part-data', $this->storage->getObjectByPath($part->path)->read());

        $bucketOwner = $this->request(
            'DELETE',
            '/bucket/key?uploadId=' . $bucketOwnerUploadId,
            S3Operation::AbortMultipartUpload,
        );
        self::assertSame(204, $this->handle(
            $bucketOwner,
            new AbortMultipartUploadHandler($this->metadata, $this->storage),
        )->getStatus());

        $initiator = $this->request(
            'DELETE',
            '/bucket/key?uploadId=' . $initiatorUploadId,
            S3Operation::AbortMultipartUpload,
        );
        $initiator->setAttribute('ownerId', 'tenant:uploader');
        self::assertSame(204, $this->handle(
            $initiator,
            new AbortMultipartUploadHandler($this->metadata, $this->storage),
        )->getStatus());

        self::assertNull($this->metadata->getMultipartUpload($bucketOwnerUploadId));
        self::assertNull($this->metadata->getMultipartUpload($initiatorUploadId));
    }

    public function test_restore_revocation_during_body_read_leaves_object_unrestored(): void
    {
        $cold = new InMemoryBackend();
        $cold->createBucket('bucket');
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]);
        $source = $cold->putObject('bucket', 'key', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'GLACIER', 'GLACIER', $source->path);
        $body = new ReadableIterableStream((function (): \Generator {
            $this->deny('s3:RestoreObject');
            yield '<RestoreRequest><Days>1</Days></RestoreRequest>';
        })());
        $request = $this->request('POST', '/bucket/key?restore', S3Operation::RestoreObject, $body);

        $this->assertDenied(function () use ($request, $tiers): void {
            $this->handle($request, new RestoreObjectHandler($this->metadata, $tiers));
        });
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'key')?->restoreStatus);
        self::assertSame([], $this->metadata->dequeueRestoreJobs(1));
    }

    private function deny(string $action, string $key = 'key'): void
    {
        $this->metadata->putAccountPolicy('owner', $this->denyPolicy($action, $key));
    }

    private function denyPolicy(string $action, string $key): string
    {
        return json_encode([
            'Statement' => [[
                'Effect' => 'Deny',
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::bucket/' . $key,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function allowAbortPolicy(): string
    {
        return json_encode([
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => 's3:AbortMultipartUpload',
                'Resource' => 'arn:aws:s3:::bucket/key',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $path, S3Operation $operation, string|\Amp\ByteStream\ReadableStream $body = ''): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $path), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    /** @param null|\Closure(): void $afterAuthorization */
    private function handle(Request $request, RequestHandler $handler, ?\Closure $afterAuthorization = null): Response
    {
        return (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
            $request,
            new class ($handler, $afterAuthorization) implements RequestHandler {
                public function __construct(private readonly RequestHandler $handler, private readonly ?\Closure $afterAuthorization) {}

                public function handleRequest(Request $request): Response
                {
                    ($this->afterAuthorization ?? static function (): void {})();

                    return $this->handler->handleRequest($request);
                }
            },
        );
    }

    /** @param \Closure(): void $operation */
    private function assertDenied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Revoked authorization must prevent the metadata commit.');
        } catch (AccessDeniedException) {
        }
    }
}
