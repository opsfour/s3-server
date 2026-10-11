<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Auth\PostObjectForm;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Object\PostObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

final class PostObjectAuthorizationRaceTest extends TestCase
{
    public function test_anonymous_post_rechecks_revoked_acl_as_anonymous_before_commit(): void
    {
        $path = sys_get_temp_dir() . '/s3-post-auth-race-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'Group',
            'granteeId' => AclGrantResolver::ALL_USERS_URI,
            'permission' => 'WRITE',
        ]]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(
            function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $body) use ($metadata): StorageWriteResult {
                self::assertSame('payload', ByteStream\buffer($body));
                $metadata->putAcl('bucket', 'bucket', 'bucket-owner', AclGrantResolver::privateAcl('bucket-owner'));

                return new StorageWriteResult('memory://bucket/key', 7, md5('payload'));
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://bucket/key', 'bucket');
        $post = new PostObjectHandler($metadata, new PutObjectHandler($metadata, $storage));
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket'),
            [],
            'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::PostObject);
        $request->setAttribute('ownerId', '');
        $request->setAttribute('credential', null);
        $request->setAttribute(PostObjectForm::class, new PostObjectForm(
            fields: ['key' => 'key'],
            key: 'key',
            filename: 'upload.txt',
            filePath: '/unused',
            fileSize: 7,
            spoolKey: str_repeat('k', 32),
            credential: null,
            policy: null,
        ));
        $handler = new PolicyEnforcementMiddleware($metadata);

        try {
            $handler->handleRequest($request, new class ($metadata, $post) implements RequestHandler {
                public function __construct(
                    private readonly SqliteMetadataStore $metadata,
                    private readonly PostObjectHandler $post,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    return (new AclEnforcementMiddleware($this->metadata))->handleRequest($request, $this->post);
                }
            });
            self::fail('A revoked anonymous WRITE ACL remained authorized at commit.');
        } catch (AccessDeniedException) {
            self::assertSame('', $request->getAttribute('ownerId'));
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
