<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class SuspendedExistingObjectTagCommitTest extends TestCase
{
    public function test_copy_rechecks_secondary_tagging_deny_after_storage_stage(): void
    {
        $path = sys_get_temp_dir() . '/s3-copy-tag-commit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', 'Enabled');
        $metadata->setBucketVersioning('bucket', 'Suspended');
        $metadata->putObjectMetadata('bucket', 'target', 'owner', 3, '"' . md5('old') . '"', 'text/plain', 'old-path');
        $metadata->putObjectTagging('bucket', 'target', [['key' => 'classification', 'value' => 'ordinary']]);
        $metadata->putObjectMetadata('bucket', 'source', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'source-path');
        $metadata->putObjectTagging('bucket', 'source', [['key' => 'classification', 'value' => 'ordinary']]);
        $metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObjectTagging',
                'Resource' => 'arn:aws:s3:::bucket/target',
                'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected']],
            ]],
        ], JSON_THROW_ON_ERROR));

        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('payload'));
        $storage->method('putObject')->willReturnCallback(static function () use ($metadata): StorageWriteResult {
            $metadata->putObjectTagging('bucket', 'target', [['key' => 'classification', 'value' => 'protected']]);

            return new StorageWriteResult('staged-copy-path', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('staged-copy-path', 'bucket');
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/target'), [
            'x-amz-copy-source' => '/bucket/source',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'target');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::CopyObject);

        try {
            $handler = new CopyObjectHandler($metadata, $storage);
            $terminal = new class ($handler) implements RequestHandler {
                public function __construct(private readonly RequestHandler $handler) {}

                public function handleRequest(Request $request): Response
                {
                    return $this->handler->handleRequest($request);
                }
            };
            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $terminal);
                self::fail('The changed destination tag must deny the secondary copy permission.');
            } catch (AccessDeniedException) {
                self::assertSame('old-path', $metadata->getObjectMetadataByVersion('bucket', 'target', 'null')?->systemMetadata['storagePath']);
                self::assertSame('protected', $metadata->getObjectTagging('bucket', 'target', 'null')[0]['value']);
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_tag_change_during_storage_stage_rejects_null_version_replacement(): void
    {
        $path = sys_get_temp_dir() . '/s3-tag-commit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->setBucketVersioning('bucket', 'Enabled');
        $metadata->setBucketVersioning('bucket', 'Suspended');
        $metadata->putObjectMetadata('bucket', 'key', 'owner', 3, md5('old'), 'text/plain', 'old-path');
        $metadata->putObjectTagging('bucket', 'key', [['key' => 'classification', 'value' => 'ordinary']]);
        $metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/key',
                'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected']],
            ]],
        ], JSON_THROW_ON_ERROR));

        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(static function () use ($metadata): StorageWriteResult {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'classification', 'value' => 'protected']]);

            return new StorageWriteResult('staged-path', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with('staged-path', 'bucket');
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/key'), [], 'payload');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        try {
            $handler = new PutObjectHandler($metadata, $storage);
            $terminal = new class ($handler) implements RequestHandler {
                public function __construct(private readonly RequestHandler $handler) {}

                public function handleRequest(Request $request): Response
                {
                    return $this->handler->handleRequest($request);
                }
            };
            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $terminal);
                self::fail('A Deny added during storage staging must stop the metadata commit.');
            } catch (AccessDeniedException) {
                self::assertSame('old-path', $metadata->getObjectMetadataByVersion('bucket', 'key', 'null')?->systemMetadata['storagePath']);
                self::assertSame('protected', $metadata->getObjectTagging('bucket', 'key', 'null')[0]['value']);
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
