<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\ByteStream\ReadableBuffer;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketPolicyHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketPolicyHandler;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\ListPartsHandler;
use OpsFour\S3Server\Handler\Multipart\ListMultipartUploadsHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;
use OpsFour\S3Server\Policy\ObjectDeletePolicyGuard;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\ObjectReadAuthorizer;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class PolicyEnforcementMiddlewareContextTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    public function test_secure_transport_bucket_deny_uses_client_to_trusted_proxy_tls(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Deny',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/*',
            'Condition' => ['Bool' => ['aws:SecureTransport' => 'false']],
        ]]));

        foreach ([
            ['127.0.0.1/32', 'https', true],
            ['127.0.0.1/32', null, false],
            ['192.0.2.0/24', 'https', false],
        ] as [$trustedCidr, $forwardedProto, $allowed]) {
            $request = $this->request('GET', '/bucket/public/readme.txt');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'public/readme.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObject);
            $request->setAttribute('ownerId', 'owner');
            $request->setHeader('X-Forwarded-For', '203.0.113.10');
            if ($forwardedProto !== null) {
                $request->setHeader('X-Forwarded-Proto', $forwardedProto);
            }
            (new TrustedProxyMiddleware([$trustedCidr]))->handleRequest($request, new PolicyTerminalHandler());

            try {
                $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
                self::assertTrue($allowed);
                self::assertSame(200, $response->getStatus());
            } catch (AccessDeniedException) {
                self::assertFalse($allowed);
            }
        }
    }

    public function test_policy_context_captures_bucket_generation_for_request_logging(): void
    {
        $request = $this->request('HEAD', '/bucket');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', '');
        $request->setAttribute('s3.operation', S3Operation::HeadBucket);
        $request->setAttribute('ownerId', 'owner');

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        self::assertSame($this->metadata->getBucket('bucket')?->generation, $request->getAttribute('s3.cachedBucketGeneration'));
    }

    public function test_create_bucket_ignores_expected_owner_header_even_for_existing_bucket(): void
    {
        $request = $this->request('PUT', '/bucket', ['x-amz-expected-bucket-owner' => 'wrong-owner']);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', '');
        $request->setAttribute('s3.operation', S3Operation::CreateBucket);
        $request->setAttribute('ownerId', 'owner');

        self::assertSame(200, (new PolicyEnforcementMiddleware($this->metadata))
            ->handleRequest($request, new PolicyTerminalHandler())->getStatus());

        $head = $this->request('HEAD', '/bucket', ['x-amz-expected-bucket-owner' => 'wrong-owner']);
        $head->setAttribute('s3.bucket', 'bucket');
        $head->setAttribute('s3.key', '');
        $head->setAttribute('s3.operation', S3Operation::HeadBucket);
        $head->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($head, new PolicyTerminalHandler());
    }

    public function test_foreign_attributes_read_requires_both_actions_on_identity_and_object_side(): void
    {
        $resource = 'arn:aws:s3:::bucket/public/readme.txt';
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $read = function () use ($handler): Response {
            $request = $this->request('GET', '/bucket/public/readme.txt?attributes');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'public/readme.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObjectAttributes);
            $request->setAttribute('ownerId', 'tenant:reader');

            return $handler->handleRequest($request);
        };
        $assertDenied = static function () use ($read): void {
            try {
                $read();
                self::fail('An attributes action was missing on one authorization side.');
            } catch (AccessDeniedException) {
            }
        };

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObject', 'Resource' => $resource,
        ]]));
        $assertDenied();

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => ['s3:GetObject', 's3:GetObjectAttributes'], 'Resource' => $resource,
        ]]));
        self::assertSame(200, $read()->getStatus());

        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:GetObject', 'Resource' => $resource,
        ]]));
        $assertDenied();

        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => ['s3:GetObject', 's3:GetObjectAttributes'], 'Resource' => $resource,
        ]]));
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObject', 'Resource' => $resource,
        ]]));
        $this->metadata->putAcl('object', 'bucket/public/readme.txt', 'owner', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'tenant:reader', 'permission' => 'READ'],
        ]);
        $assertDenied();

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObjectAttributes', 'Resource' => $resource,
        ]]));
        self::assertSame(200, $read()->getStatus());
    }

    public function test_public_read_acl_does_not_grant_the_attributes_action(): void
    {
        $resource = 'arn:aws:s3:::bucket/public/readme.txt';
        $this->metadata->putAcl('object', 'bucket/public/readme.txt', 'owner', [
            ['granteeType' => 'Group', 'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers', 'permission' => 'READ'],
        ]);
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $read = function () use ($handler): Response {
            $request = $this->request('GET', '/bucket/public/readme.txt?attributes');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'public/readme.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObjectAttributes);
            $request->setAttribute('ownerId', '');

            return $handler->handleRequest($request);
        };

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObject', 'Resource' => $resource,
        ]]));
        try {
            $read();
            self::fail('Anonymous READ access granted GetObjectAttributes without its action.');
        } catch (AccessDeniedException) {
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => '*', 'Action' => 's3:GetObjectAttributes', 'Resource' => $resource,
        ]]));
        self::assertSame(200, $read()->getStatus());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-policy-context-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        foreach (['owner', 'tenant:other', 'tenant:reader', 'tenant:uploader', 'tenant:writer', 'foreign-account'] as $principal) {
            $this->metadata->putAccountPolicy($principal, $this->policy([[
                'Effect' => 'Allow', 'Action' => 's3:*', 'Resource' => '*',
            ]]));
        }
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'public/readme.txt',
            ownerId: 'owner',
            size: 4,
            etag: '"etag"',
            contentType: 'text/plain',
            storagePath: 'bucket/public/readme.txt',
        );
        $this->metadata->putObjectTagging('bucket', 'public/readme.txt', [
            ['key' => 'classification', 'value' => 'public'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_bucket_write_grant_cannot_delete_another_accounts_object(): void
    {
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'owner', 'permission' => 'FULL_CONTROL'],
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'tenant:writer', 'permission' => 'WRITE'],
        ]);
        $request = $this->request('DELETE', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'tenant:writer');
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        try {
            (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                $request,
                new DeleteObjectHandler($this->metadata, StorageTierRegistry::single(new InMemoryBackend())),
            );
            $this->fail('Bucket WRITE must not delete an object owned by another account.');
        } catch (AccessDeniedException) {
            $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'public/readme.txt'));
        }
    }

    public function test_public_bucket_write_cannot_delete_an_existing_owner_object(): void
    {
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [
            ['granteeType' => 'Group', 'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers', 'permission' => 'WRITE'],
        ]);
        $request = $this->request('DELETE', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', '');
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        try {
            (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                $request,
                new DeleteObjectHandler($this->metadata, StorageTierRegistry::single(new InMemoryBackend())),
            );
            $this->fail('Public bucket WRITE must not delete an existing object.');
        } catch (AccessDeniedException) {
            $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'public/readme.txt'));
        }
    }

    public function test_batch_delete_makes_a_policy_decision_for_each_key(): void
    {
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'tenant:writer', 'permission' => 'WRITE'],
        ]);
        $this->metadata->putObjectMetadata('bucket', 'public/allowed.txt', 'owner', 1, '"etag"', 'text/plain', '');
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:writer'],
            'Action' => 's3:DeleteObject',
            'Resource' => 'arn:aws:s3:::bucket/public/allowed.txt',
        ]]));

        $body = '<Delete><Object><Key>public/readme.txt</Key></Object><Object><Key>public/allowed.txt</Key></Object></Delete>';
        $request = $this->request('POST', '/bucket?delete', [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::DeleteObjects);
        $request->setAttribute('ownerId', 'tenant:writer');
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
        $response = (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $request,
            new DeleteObjectsHandler($this->metadata, StorageTierRegistry::single(new InMemoryBackend())),
        );

        $xml = \Amp\ByteStream\buffer($response->getBody());
        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('<Key>public/readme.txt</Key><Code>AccessDenied</Code>', $xml);
        $this->assertStringContainsString('<Deleted><Key>public/allowed.txt</Key>', $xml);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'public/readme.txt'));
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'public/allowed.txt'));
    }

    public function test_object_owner_with_bucket_write_can_delete_own_object_but_prefix_scope_still_applies(): void
    {
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'tenant:writer', 'permission' => 'WRITE'],
        ]);
        $this->metadata->putObjectMetadata('bucket', 'own.txt', 'tenant:writer', 1, '"etag"', 'text/plain', '');
        $guard = new ObjectDeletePolicyGuard($this->metadata);
        $request = $this->request('POST', '/bucket?delete');
        $this->assertFalse($guard->check($request, 'bucket', 'own.txt', null, 'tenant:writer', 'owner'));

        $request->setAttribute('credential', new Credential('writer-key', 'secret', 'tenant:writer', allowedPrefixes: ['safe/']));
        $this->expectException(AccessDeniedException::class);
        $guard->check($request, 'bucket', 'own.txt', null, 'tenant:writer', 'owner');
    }

    public function test_batch_delete_scoped_credential_is_checked_per_key(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:writer'],
            'Action' => 's3:DeleteObject',
            'Resource' => 'arn:aws:s3:::bucket/public/*',
        ]]));
        $request = $this->request(
            'POST',
            '/bucket?delete',
            [],
            '<Delete><Object><Key>public/readme.txt</Key></Object></Delete>',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::DeleteObjects);
        $request->setAttribute('ownerId', 'tenant:writer');
        $request->setAttribute('credential', new Credential('writer-key', 'secret', 'tenant:writer', allowedPrefixes: ['safe/']));
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
        $response = (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $request,
            new DeleteObjectsHandler($this->metadata, StorageTierRegistry::single(new InMemoryBackend())),
        );

        $this->assertStringContainsString('<Code>AccessDenied</Code>', \Amp\ByteStream\buffer($response->getBody()));
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'public/readme.txt'));
    }

    public function test_identity_allow_alone_cannot_delete_a_foreign_object(): void
    {
        $this->metadata->putAccountPolicy('tenant:writer', $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:DeleteObject',
            'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
        ]]));
        $request = $this->request('DELETE', '/bucket/public/readme.txt');

        $this->expectException(AccessDeniedException::class);
        (new ObjectDeletePolicyGuard($this->metadata))->check(
            $request,
            'bucket',
            'public/readme.txt',
            null,
            'tenant:writer',
            'owner',
        );
    }

    public function test_identity_allow_alone_cannot_read_a_foreign_object(): void
    {
        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
        ]]));
        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );

        try {
            $handler->handleRequest($request);
            self::fail('Identity Allow alone must not authorize a foreign bucket read.');
        } catch (AccessDeniedException) {
            self::assertSame('Neutral', $request->getAttribute('s3.policyResult'));
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
        ]]));
        self::assertSame(200, $handler->handleRequest($request)->getStatus());
    }

    public function test_foreign_identity_allow_cannot_self_grant_governance_bypass(): void
    {
        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow',
            'Action' => ['s3:DeleteObject', 's3:BypassGovernanceRetention'],
            'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
        ]]));
        $request = $this->request('DELETE', '/bucket/public/readme.txt', [
            'x-amz-bypass-governance-retention' => 'true',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
        self::assertFalse($request->getAttribute('s3.canBypassGovernanceRetention'));
    }

    public function test_foreign_governance_bypass_needs_both_identity_and_bucket_policy_allow(): void
    {
        $resource = 'arn:aws:s3:::bucket/public/readme.txt';
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => ['s3:DeleteObject', 's3:BypassGovernanceRetention'],
            'Resource' => $resource,
        ]]));
        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:DeleteObject', 'Resource' => $resource,
        ]]));
        $request = $this->request('DELETE', '/bucket/public/readme.txt', [
            'x-amz-bypass-governance-retention' => 'true',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
        self::assertFalse($request->getAttribute('s3.canBypassGovernanceRetention'));

        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow',
            'Action' => ['s3:DeleteObject', 's3:BypassGovernanceRetention'],
            'Resource' => $resource,
        ]]));
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
        self::assertTrue($request->getAttribute('s3.canBypassGovernanceRetention'));
    }

    public function test_bucket_owner_can_abort_foreign_upload_and_identity_allow_alone_cannot(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $this->metadata->createMultipartUpload('upload-owner-check', 'bucket', 'upload.txt', 'tenant:uploader');
        $part = $storage->putPart('bucket', 'upload.txt', 'upload-owner-check', 1, new ReadableBuffer('part data'));
        $this->metadata->putPart('upload-owner-check', 1, '"' . $part->md5Hex . '"', $part->size, $part->path);
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new AbortMultipartUploadHandler($this->metadata, $storage),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );

        $this->metadata->putAccountPolicy('tenant:other', $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:AbortMultipartUpload',
            'Resource' => 'arn:aws:s3:::bucket/upload.txt',
        ]]));
        $foreign = $this->request('DELETE', '/bucket/upload.txt?uploadId=upload-owner-check');
        $foreign->setAttribute('s3.bucket', 'bucket');
        $foreign->setAttribute('s3.key', 'upload.txt');
        $foreign->setAttribute('s3.operation', S3Operation::AbortMultipartUpload);
        $foreign->setAttribute('ownerId', 'tenant:other');
        try {
            $handler->handleRequest($foreign);
            self::fail('A foreign identity policy must not authorize the abort.');
        } catch (NoSuchUploadException) {
            self::assertNotNull($this->metadata->getMultipartUpload('upload-owner-check'));
            self::assertCount(1, $this->metadata->getParts('upload-owner-check'));
        }

        $owner = $this->request('DELETE', '/bucket/upload.txt?uploadId=upload-owner-check');
        $owner->setAttribute('s3.bucket', 'bucket');
        $owner->setAttribute('s3.key', 'upload.txt');
        $owner->setAttribute('s3.operation', S3Operation::AbortMultipartUpload);
        $owner->setAttribute('ownerId', 'owner');
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Deny',
            'Principal' => ['AWS' => 'owner'],
            'Action' => 's3:AbortMultipartUpload',
            'Resource' => 'arn:aws:s3:::bucket/upload.txt',
        ]]));
        try {
            $handler->handleRequest($owner);
            self::fail('Explicit bucket-policy Deny must block the bucket owner.');
        } catch (AccessDeniedException) {
            self::assertNotNull($this->metadata->getMultipartUpload('upload-owner-check'));
        }
        $this->metadata->deleteBucketPolicy('bucket');
        self::assertSame(204, $handler->handleRequest($owner)->getStatus());
        self::assertNull($this->metadata->getMultipartUpload('upload-owner-check'));
    }

    public function test_abort_allows_initiator_and_explicit_bucket_policy_grantee_but_not_acl_write_alone(): void
    {
        $storage = new InMemoryBackend();
        $this->metadata->createMultipartUpload('upload-initiator', 'bucket', 'first.txt', 'tenant:uploader');
        $this->metadata->createMultipartUpload('upload-policy', 'bucket', 'second.txt', 'tenant:uploader');
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new AbortMultipartUploadHandler($this->metadata, $storage),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );

        $initiator = $this->request('DELETE', '/bucket/first.txt?uploadId=upload-initiator');
        $initiator->setAttribute('s3.bucket', 'bucket');
        $initiator->setAttribute('s3.key', 'first.txt');
        $initiator->setAttribute('s3.operation', S3Operation::AbortMultipartUpload);
        $initiator->setAttribute('ownerId', 'tenant:uploader');
        self::assertSame(204, $handler->handleRequest($initiator)->getStatus());

        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:other',
            'permission' => 'WRITE',
        ]]);
        $foreign = $this->request('DELETE', '/bucket/second.txt?uploadId=upload-policy');
        $foreign->setAttribute('s3.bucket', 'bucket');
        $foreign->setAttribute('s3.key', 'second.txt');
        $foreign->setAttribute('s3.operation', S3Operation::AbortMultipartUpload);
        $foreign->setAttribute('ownerId', 'tenant:other');
        try {
            $handler->handleRequest($foreign);
            self::fail('Bucket ACL WRITE must not authorize a foreign upload abort.');
        } catch (NoSuchUploadException) {
            self::assertNotNull($this->metadata->getMultipartUpload('upload-policy'));
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:other'],
            'Action' => 's3:AbortMultipartUpload',
            'Resource' => 'arn:aws:s3:::bucket/second.txt',
        ]]));
        self::assertSame(204, $handler->handleRequest($foreign)->getStatus());
        self::assertNull($this->metadata->getMultipartUpload('upload-policy'));
    }

    public function test_list_parts_allows_initiator_bucket_owner_and_policy_grantee_only(): void
    {
        $uploadId = 'upload-list-ownership';
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'shared.txt', 'tenant:uploader');
        $this->metadata->putPart($uploadId, 1, '"etag"', 4, 'part-path');
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new ListPartsHandler($this->metadata),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $requestFor = function (string $account) use ($uploadId): Request {
            $request = $this->request('GET', '/bucket/shared.txt?uploadId=' . $uploadId);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'shared.txt');
            $request->setAttribute('s3.operation', S3Operation::ListParts);
            $request->setAttribute('ownerId', $account);

            return $request;
        };

        foreach (['tenant:uploader', 'owner'] as $account) {
            $response = $handler->handleRequest($requestFor($account));
            self::assertSame(200, $response->getStatus(), $account);
            $xml = new \SimpleXMLElement(\Amp\ByteStream\buffer($response->getBody()));
            self::assertSame('tenant:uploader', (string) $xml->Initiator->ID);
            self::assertSame('tenant:uploader', (string) $xml->Owner->ID);
            self::assertSame(1, (int) $xml->Part->PartNumber);
        }

        $this->metadata->putAccountPolicy('tenant:other', $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:ListMultipartUploadParts',
            'Resource' => 'arn:aws:s3:::bucket/shared.txt',
        ]]));
        try {
            $handler->handleRequest($requestFor('tenant:other'));
            self::fail('Identity policy alone must not list a foreign upload.');
        } catch (NoSuchUploadException|AccessDeniedException) {
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:other'],
            'Action' => 's3:ListMultipartUploadParts',
            'Resource' => 'arn:aws:s3:::bucket/shared.txt',
        ]]));
        self::assertSame(200, $handler->handleRequest($requestFor('tenant:other'))->getStatus());

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Deny',
            'Principal' => ['AWS' => 'owner'],
            'Action' => 's3:ListMultipartUploadParts',
            'Resource' => 'arn:aws:s3:::bucket/shared.txt',
        ]]));
        $this->expectException(AccessDeniedException::class);
        $handler->handleRequest($requestFor('owner'));
    }

    public function test_bucket_read_acl_grants_list_multipart_uploads(): void
    {
        $this->metadata->createMultipartUpload('upload-visible', 'bucket', 'visible.txt', 'tenant:uploader');
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:reader',
            'permission' => 'READ',
        ]]);
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new ListMultipartUploadsHandler($this->metadata),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $request = $this->request('GET', '/bucket?uploads');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::ListMultipartUploads);
        $request->setAttribute('ownerId', 'tenant:reader');

        $response = $handler->handleRequest($request);
        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('<Key>visible.txt</Key>', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_bucket_write_acl_allows_foreign_object_owner_to_copy_into_bucket(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $source = $storage->putObject('bucket', 'source.txt', new ReadableBuffer('copy me'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.txt',
            'tenant:writer',
            $source->size,
            '"' . $source->md5Hex . '"',
            'text/plain',
            $source->path,
        );
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new CopyObjectHandler($this->metadata, $storage),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $requestForWriter = function (): Request {
            $request = $this->request('PUT', '/bucket/copied.txt', [
                'x-amz-copy-source' => '/bucket/source.txt',
            ]);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'copied.txt');
            $request->setAttribute('s3.operation', S3Operation::CopyObject);
            $request->setAttribute('ownerId', 'tenant:writer');

            return $request;
        };

        try {
            $handler->handleRequest($requestForWriter());
            self::fail('CopyObject must require destination write permission.');
        } catch (AccessDeniedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'copied.txt'));
        }

        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:writer',
            'permission' => 'WRITE',
        ]]);
        self::assertSame(200, $handler->handleRequest($requestForWriter())->getStatus());
        $copied = $this->metadata->getObjectMetadata('bucket', 'copied.txt');
        self::assertNotNull($copied);
        self::assertSame('tenant:writer', $copied->ownerId);
        self::assertSame('copy me', \Amp\ByteStream\buffer($storage->getObjectByPath($copied->systemMetadata['storagePath'])));

        $bucketOwnerRequest = $this->request('PUT', '/bucket/owner-copy.txt', [
            'x-amz-copy-source' => '/bucket/source.txt',
        ]);
        $bucketOwnerRequest->setAttribute('s3.bucket', 'bucket');
        $bucketOwnerRequest->setAttribute('s3.key', 'owner-copy.txt');
        $bucketOwnerRequest->setAttribute('s3.operation', S3Operation::CopyObject);
        $bucketOwnerRequest->setAttribute('ownerId', 'owner');
        try {
            $handler->handleRequest($bucketOwnerRequest);
            self::fail('Bucket ownership alone must not read a foreign-owned source object.');
        } catch (AccessDeniedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'owner-copy.txt'));
        }
    }

    public function test_copy_source_identity_allow_alone_cannot_read_foreign_object(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $source = $storage->putObject('bucket', 'private-source.txt', new ReadableBuffer('secret'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'private-source.txt',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'text/plain',
            $source->path,
        );
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:writer',
            'permission' => 'WRITE',
        ]]);
        $this->metadata->putAccountPolicy('tenant:writer', $this->policy([[
            'Effect' => 'Allow',
            'Action' => ['s3:GetObject', 's3:PutObject'],
            'Resource' => 'arn:aws:s3:::bucket/*',
        ]]));
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new CopyObjectHandler($this->metadata, $storage),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $request = $this->request('PUT', '/bucket/stolen.txt', [
            'x-amz-copy-source' => '/bucket/private-source.txt',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'stolen.txt');
        $request->setAttribute('s3.operation', S3Operation::CopyObject);
        $request->setAttribute('ownerId', 'tenant:writer');

        try {
            $handler->handleRequest($request);
            self::fail('Identity policy alone must not grant access to the copy source.');
        } catch (AccessDeniedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'stolen.txt'));
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:writer'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/private-source.txt',
        ]]));
        self::assertSame(200, $handler->handleRequest($request)->getStatus());
    }

    public function test_foreign_object_owner_controls_direct_reads_despite_bucket_ownership(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'foreign/private.txt',
            'tenant:writer',
            4,
            '"etag"',
            'text/plain',
            'foreign-path',
        );
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $requestFor = function (S3Operation $operation, string $account): Request {
            $request = $this->request('GET', '/bucket/foreign/private.txt');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'foreign/private.txt');
            $request->setAttribute('s3.operation', $operation);
            $request->setAttribute('ownerId', $account);

            return $request;
        };

        foreach ([
            S3Operation::GetObject,
            S3Operation::HeadObject,
            S3Operation::GetObjectAttributes,
            S3Operation::SelectObjectContent,
            S3Operation::GetObjectAcl,
            S3Operation::GetObjectTagging,
            S3Operation::GetObjectRetention,
            S3Operation::GetObjectLegalHold,
        ] as $operation) {
            self::assertSame(200, $handler->handleRequest($requestFor($operation, 'tenant:writer'))->getStatus(), $operation->value);
            try {
                $handler->handleRequest($requestFor($operation, 'owner'));
                self::fail('Bucket owner read a foreign-owned object through ' . $operation->value);
            } catch (AccessDeniedException $exception) {
                self::assertSame('AccessDenied', $exception->getErrorCode());
            }
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'owner'],
            'Action' => 's3:*',
            'Resource' => 'arn:aws:s3:::bucket/foreign/private.txt',
        ]]));
        try {
            $handler->handleRequest($requestFor(S3Operation::GetObject, 'owner'));
            self::fail('Bucket policy cannot grant a foreign-owned object.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('AccessDenied', $exception->getErrorCode());
        }

        $this->metadata->putAcl('object', 'bucket/foreign/private.txt', 'tenant:writer', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'owner', 'permission' => 'READ'],
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'owner', 'permission' => 'READ_ACP'],
        ]);
        foreach ([
            S3Operation::GetObject,
            S3Operation::HeadObject,
            S3Operation::SelectObjectContent,
            S3Operation::GetObjectAcl,
        ] as $operation) {
            self::assertSame(200, $handler->handleRequest($requestFor($operation, 'owner'))->getStatus(), $operation->value);
        }
        try {
            $handler->handleRequest($requestFor(S3Operation::GetObjectAttributes, 'owner'));
            self::fail('Object READ ACL does not grant the GetObjectAttributes action.');
        } catch (AccessDeniedException) {
        }
        try {
            $handler->handleRequest($requestFor(S3Operation::GetObjectTagging, 'owner'));
            self::fail('Object READ ACL must not grant tagging access.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('AccessDenied', $exception->getErrorCode());
        }
    }

    public function test_versioned_read_uses_requested_versions_owner_and_acl(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $foreignVersion = $this->metadata->putObjectVersioned(
            'bucket',
            'foreign/history.txt',
            'tenant:writer',
            4,
            '"old"',
            'text/plain',
            'old-path',
        );
        $ownerVersion = $this->metadata->putObjectVersioned(
            'bucket',
            'foreign/history.txt',
            'owner',
            4,
            '"new"',
            'text/plain',
            'new-path',
        );
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $requestFor = function (string $account, string $versionId): Request {
            $request = $this->request('GET', '/bucket/foreign/history.txt?versionId=' . $versionId);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'foreign/history.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObject);
            $request->setAttribute('ownerId', $account);

            return $request;
        };

        self::assertSame(200, $handler->handleRequest($requestFor('owner', $ownerVersion))->getStatus());
        self::assertSame(200, $handler->handleRequest($requestFor('tenant:writer', $foreignVersion))->getStatus());
        try {
            $handler->handleRequest($requestFor('owner', $foreignVersion));
            self::fail('The current owner must not authorize a foreign-owned older version.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('AccessDenied', $exception->getErrorCode());
        }

        $this->metadata->putAcl(
            'object',
            \OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName('bucket', 'foreign/history.txt', $foreignVersion),
            'tenant:writer',
            [['granteeType' => 'CanonicalUser', 'granteeId' => 'owner', 'permission' => 'READ']],
        );
        self::assertSame(200, $handler->handleRequest($requestFor('owner', $foreignVersion))->getStatus());
    }

    public function test_select_cannot_use_an_older_versions_owner_to_read_the_current_object(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $storage = new InMemoryBackend();
        $oldBody = "old-writer\n";
        $currentBody = "private-current\n";
        $oldPath = $storage->putObject('bucket', 'versioned.csv', new ReadableBuffer($oldBody))->path;
        $currentPath = $storage->putObject('bucket', 'versioned.csv', new ReadableBuffer($currentBody))->path;
        $oldVersion = $this->metadata->putObjectVersioned(
            'bucket',
            'versioned.csv',
            'tenant:writer',
            strlen($oldBody),
            '"old"',
            'text/csv',
            $oldPath,
        );
        $this->metadata->putObjectVersioned(
            'bucket',
            'versioned.csv',
            'owner',
            strlen($currentBody),
            '"current"',
            'text/csv',
            $currentPath,
        );

        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new SelectObjectContentHandler($this->metadata, $storage),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        $requestFor = function (string $account, ?string $versionId = null): Request {
            $query = '?select&select-type=2';
            if ($versionId !== null) {
                $query .= '&versionId=' . rawurlencode($versionId);
            }
            $request = $this->request(
                'POST',
                '/bucket/versioned.csv' . $query,
                [],
                '<SelectObjectContentRequest><Expression>SELECT * FROM s3object</Expression>'
                . '<ExpressionType>SQL</ExpressionType><InputSerialization><CSV/></InputSerialization>'
                . '<OutputSerialization><CSV/></OutputSerialization></SelectObjectContentRequest>',
            );
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'versioned.csv');
            $request->setAttribute('s3.operation', S3Operation::SelectObjectContent);
            $request->setAttribute('ownerId', $account);

            return $request;
        };

        try {
            $handler->handleRequest($requestFor('tenant:writer'));
            self::fail('The old version owner read the current private object without a version selector.');
        } catch (AccessDeniedException) {
        }
        $currentResponse = $handler->handleRequest($requestFor('owner'));
        self::assertSame(200, $currentResponse->getStatus());
        self::assertStringContainsString('private-current', $currentResponse->getBody()->read() ?? '');

        try {
            (new SelectObjectContentHandler($this->metadata, $storage))
                ->handleRequest($requestFor('owner', $oldVersion));
            self::fail('Direct Select handler access accepted an unsupported version selector.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('InvalidArgument', $exception->getErrorCode());
        }

        $this->expectException(InvalidArgumentException::class);
        $handler->handleRequest($requestFor('tenant:writer', $oldVersion));
    }

    public function test_foreign_object_owner_controls_existing_object_subresource_writes(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'foreign/settings.txt',
            'tenant:writer',
            4,
            '"etag"',
            'text/plain',
            'foreign-path',
        );
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );

        foreach ([
            S3Operation::PutObjectAcl,
            S3Operation::PutObjectTagging,
            S3Operation::DeleteObjectTagging,
            S3Operation::PutObjectRetention,
            S3Operation::PutObjectLegalHold,
            S3Operation::RestoreObject,
        ] as $operation) {
            foreach (['tenant:writer' => 200, 'owner' => 403] as $account => $expectedStatus) {
                $body = $operation === S3Operation::PutObjectTagging
                    ? '<Tagging><TagSet/></Tagging>'
                    : '';
                $request = $this->request('PUT', '/bucket/foreign/settings.txt', [], $body);
                $request->setAttribute('s3.bucket', 'bucket');
                $request->setAttribute('s3.key', 'foreign/settings.txt');
                $request->setAttribute('s3.operation', $operation);
                $request->setAttribute('ownerId', $account);

                if ($expectedStatus === 200) {
                    self::assertSame(200, $handler->handleRequest($request)->getStatus(), $operation->value);
                    continue;
                }
                try {
                    $handler->handleRequest($request);
                    self::fail('Bucket owner changed a foreign-owned object through ' . $operation->value);
                } catch (AccessDeniedException $exception) {
                    self::assertSame('AccessDenied', $exception->getErrorCode());
                }
            }
        }

        $this->metadata->putAcl('object', 'bucket/foreign/settings.txt', 'tenant:writer', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'owner',
            'permission' => 'WRITE_ACP',
        ]]);
        $aclRequest = $this->request('PUT', '/bucket/foreign/settings.txt');
        $aclRequest->setAttribute('s3.bucket', 'bucket');
        $aclRequest->setAttribute('s3.key', 'foreign/settings.txt');
        $aclRequest->setAttribute('s3.operation', S3Operation::PutObjectAcl);
        $aclRequest->setAttribute('ownerId', 'owner');
        self::assertSame(200, $handler->handleRequest($aclRequest)->getStatus());
    }

    public function test_foreign_version_delete_requires_version_specific_policy_allow(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:writer'],
            'Action' => 's3:DeleteObject',
            'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
        ]]));
        $request = $this->request('DELETE', '/bucket/public/readme.txt?versionId=version-1');
        $guard = new ObjectDeletePolicyGuard($this->metadata);

        try {
            $guard->check($request, 'bucket', 'public/readme.txt', 'version-1', 'tenant:writer', 'owner');
            $this->fail('DeleteObject permission must not authorize a version-specific delete.');
        } catch (AccessDeniedException) {
            $this->metadata->putBucketPolicy('bucket', $this->policy([[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'tenant:writer'],
                'Action' => 's3:DeleteObjectVersion',
                'Resource' => 'arn:aws:s3:::bucket/public/readme.txt',
            ]]));
            $this->assertFalse($guard->check($request, 'bucket', 'public/readme.txt', 'version-1', 'tenant:writer', 'owner'));
        }
    }

    public function test_create_bucket_object_lock_header_respects_secondary_action_denies(): void
    {
        foreach (['s3:PutBucketVersioning', 's3:PutBucketObjectLockConfiguration'] as $action) {
            $this->metadata->putAccountPolicy('owner', $this->policy([[
                'Effect' => 'Deny',
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::new-bucket',
            ]]));
            $request = $this->request('PUT', '/new-bucket', [
                'x-amz-bucket-object-lock-enabled' => 'true',
            ]);
            $request->setAttribute('s3.bucket', 'new-bucket');
            $request->setAttribute('s3.operation', S3Operation::CreateBucket);
            $request->setAttribute('ownerId', 'owner');

            try {
                (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
                $this->fail("Expected {$action} Deny to reject the request.");
            } catch (AccessDeniedException) {
                $this->assertNull($this->metadata->getBucketOwner('new-bucket'));
            }
        }
    }

    public function test_create_bucket_ownership_and_acl_headers_respect_secondary_action_denies(): void
    {
        foreach ([
            ['s3:PutBucketOwnershipControls', 'x-amz-object-ownership', 'ObjectWriter'],
            ['s3:PutBucketAcl', 'x-amz-acl', 'private'],
            ['s3:PutBucketAcl', 'x-amz-grant-read', 'id="another-owner"'],
        ] as [$action, $header, $value]) {
            $this->metadata->putAccountPolicy('owner', $this->policy([[
                'Effect' => 'Deny',
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::new-bucket',
            ]]));
            $request = $this->request('PUT', '/new-bucket', [$header => $value]);
            $request->setAttribute('s3.bucket', 'new-bucket');
            $request->setAttribute('s3.operation', S3Operation::CreateBucket);
            $request->setAttribute('ownerId', 'owner');

            try {
                (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
                $this->fail("Expected {$action} Deny for {$header}.");
            } catch (AccessDeniedException) {
                $this->assertNull($this->metadata->getBucketOwner('new-bucket'));
            }

            // An implicit ObjectWriter default does not require the extra
            // ownership action. An omitted ACL does not require PutBucketAcl.
            $withoutHeader = $this->request('PUT', '/new-bucket');
            $withoutHeader->setAttribute('s3.bucket', 'new-bucket');
            $withoutHeader->setAttribute('s3.operation', S3Operation::CreateBucket);
            $withoutHeader->setAttribute('ownerId', 'owner');
            $this->assertSame(200, (new PolicyEnforcementMiddleware($this->metadata))
                ->handleRequest($withoutHeader, new PolicyTerminalHandler())->getStatus());
        }
    }

    public function test_put_public_access_block_deny_also_blocks_delete(): void
    {
        $this->metadata->putPublicAccessBlock('bucket', true, true, true, true);
        $this->metadata->putAccountPolicy('owner', $this->policy([[
            'Effect' => 'Deny',
            'Action' => 's3:PutBucketPublicAccessBlock',
            'Resource' => 'arn:aws:s3:::bucket',
        ]]));
        $request = $this->request('DELETE', '/bucket?publicAccessBlock');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::DeletePublicAccessBlock);
        $request->setAttribute('ownerId', 'owner');

        try {
            (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
            $this->fail('The PutBucketPublicAccessBlock deny must also protect deletion.');
        } catch (AccessDeniedException) {
            $this->assertSame([
                'blockPublicAcls' => true,
                'ignorePublicAcls' => true,
                'blockPublicPolicy' => true,
                'restrictPublicBuckets' => true,
            ], $this->metadata->getPublicAccessBlock('bucket'));
        }
    }

    public function test_middleware_populates_user_agent_prefix_and_source_ip_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:ListBucket',
                'Resource' => 'arn:aws:s3:::bucket',
                'Condition' => [
                    'IpAddress' => ['aws:SourceIp' => '127.0.0.0/8'],
                    'StringLike' => ['aws:UserAgent' => 'aws-sdk-*', 's3:prefix' => 'public/*'],
                ],
            ],
        ]));

        $request = $this->request('GET', '/bucket?list-type=2&prefix=public/images/', ['user-agent' => 'aws-sdk-php/3']);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::ListObjectsV2);
        $request->setAttribute('ownerId', 'tenant:reader');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_middleware_populates_existing_object_tag_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'StringEquals' => ['s3:ExistingObjectTag/classification' => 'public'],
                ],
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_middleware_enforces_unsupported_condition_fail_closed(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => ['StringEquals' => ['aws:PrincipalOrgID' => 'o-123']],
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_owner_can_remove_legacy_policy_with_unsupported_condition(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => '*',
            'Condition' => ['StringEquals' => ['aws:PrincipalOrgID' => 'o-legacy']],
        ]]));

        $foreign = $this->request('DELETE', '/bucket?policy');
        $foreign->setAttribute('s3.bucket', 'bucket');
        $foreign->setAttribute('s3.operation', S3Operation::DeleteBucketPolicy);
        $foreign->setAttribute('ownerId', 'tenant:other');
        try {
            (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($foreign, new DeleteBucketPolicyHandler($this->metadata));
            $this->fail('A foreign account removed a bucket policy.');
        } catch (AccessDeniedException) {
            $this->assertNotNull($this->metadata->getBucketPolicy('bucket'));
        }

        $owner = $this->request('DELETE', '/bucket?policy');
        $owner->setAttribute('s3.bucket', 'bucket');
        $owner->setAttribute('s3.operation', S3Operation::DeleteBucketPolicy);
        $owner->setAttribute('ownerId', 'owner');
        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($owner, new DeleteBucketPolicyHandler($this->metadata));

        $this->assertSame(204, $response->getStatus());
        $this->assertNull($this->metadata->getBucketPolicy('bucket'));
    }

    public function test_middleware_populates_request_tag_header_and_numeric_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'StringEquals' => [
                        's3:RequestObjectTag/project' => 'alpha',
                        's3:x-amz-acl' => 'private',
                        's3:x-amz-server-side-encryption' => 'AES256',
                        's3:x-amz-storage-class' => 'STANDARD',
                    ],
                    'NumericLessThanEquals' => ['s3:max-keys' => 100],
                    'DateLessThan' => ['aws:CurrentTime' => '2999-01-01T00:00:00Z'],
                ],
            ],
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => ['s3:PutObjectTagging', 's3:PutObjectAcl'],
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('PUT', '/bucket/public/new.txt?max-keys=25', [
            'x-amz-tagging' => 'project=alpha',
            'x-amz-acl' => 'private',
            'x-amz-server-side-encryption' => 'AES256',
            'x-amz-storage-class' => 'STANDARD',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/new.txt');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'tenant:writer');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_anonymous_tagged_put_uses_explicit_bucket_policy_actions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => ['s3:PutObject', 's3:PutObjectTagging'],
            'Resource' => 'arn:aws:s3:::bucket/public/*',
        ]]));

        foreach ([
            'untagged' => [],
            'tagged' => ['x-amz-tagging' => 'project=alpha'],
        ] as $label => $headers) {
            $request = $this->request('PUT', '/bucket/public/' . $label . '.txt', $headers);
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'public/' . $label . '.txt');
            $request->setAttribute('s3.operation', S3Operation::PutObject);
            $request->setAttribute('ownerId', '');

            $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
                $request,
                new PolicyTerminalHandler(),
            );

            self::assertSame(200, $response->getStatus(), $label);
            self::assertSame('Allow', $request->getAttribute('s3.policyResult'), $label);
        }
    }

    public function test_scoped_credential_prefix_is_enforced_before_owner_bypass(): void
    {
        $request = $this->request('GET', '/bucket/private/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'private/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIASCOPED12345678',
            secretAccessKey: 'secret',
            ownerId: 'owner',
            displayName: 'Scoped',
            allowedPrefixes: ['public/'],
        ));

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_object_lock_write_headers_require_their_secondary_actions(): void
    {
        foreach ([S3Operation::PutObject, S3Operation::CreateMultipartUpload] as $operation) {
            foreach ([
                [['x-amz-object-lock-mode' => 'GOVERNANCE'], 's3:PutObjectRetention'],
                [['x-amz-object-lock-legal-hold' => 'ON'], 's3:PutObjectLegalHold'],
            ] as [$header, $action]) {
                $this->metadata->putBucketPolicy('bucket', $this->policy([[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => $action,
                    'Resource' => 'arn:aws:s3:::bucket/public/locked.txt',
                ]]));
                $request = $this->request(
                    $operation === S3Operation::PutObject ? 'PUT' : 'POST',
                    '/bucket/public/locked.txt' . ($operation === S3Operation::CreateMultipartUpload ? '?uploads' : ''),
                    $header,
                );
                $request->setAttribute('s3.bucket', 'bucket');
                $request->setAttribute('s3.key', 'public/locked.txt');
                $request->setAttribute('s3.operation', $operation);
                $request->setAttribute('ownerId', 'owner');

                try {
                    (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
                    $this->fail('Expected secondary Object Lock action to be denied: ' . $action);
                } catch (AccessDeniedException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_list_buckets_respects_account_policy_explicit_deny(): void
    {
        $this->metadata->putAccountPolicy('owner', $this->policy([[
            'Effect' => 'Deny',
            'Action' => 's3:ListAllMyBuckets',
            'Resource' => '*',
        ]]));
        $request = $this->request('GET', '/');
        $request->setAttribute('s3.operation', S3Operation::ListBuckets);
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_list_buckets_requires_an_authenticated_owner(): void
    {
        $request = $this->request('GET', '/');
        $request->setAttribute('s3.operation', S3Operation::ListBuckets);
        $request->setAttribute('ownerId', '');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_bucket_policy_mutation_is_reserved_for_bucket_owner(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => ['s3:PutBucketPolicy', 's3:DeleteBucketPolicy'],
            'Resource' => 'arn:aws:s3:::bucket',
        ]]));

        foreach ([S3Operation::PutBucketPolicy, S3Operation::DeleteBucketPolicy] as $operation) {
            $request = $this->request(
                $operation === S3Operation::PutBucketPolicy ? 'PUT' : 'DELETE',
                '/bucket?policy',
                [],
                $this->policy([[
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => 'arn:aws:s3:::bucket/*',
                ]]),
            );
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.operation', $operation);
            $request->setAttribute('ownerId', 'foreign-account');
            $handler = $operation === S3Operation::PutBucketPolicy
                ? new PutBucketPolicyHandler($this->metadata)
                : new DeleteBucketPolicyHandler($this->metadata);

            try {
                (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, $handler);
                $this->fail('Expected an otherwise authorized foreign policy mutation to return MethodNotAllowed.');
            } catch (MethodNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_put_bucket_logging_requires_source_bucket_owner_even_with_resource_policy_allow(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'foreign-account'],
            'Action' => 's3:PutBucketLogging',
            'Resource' => 'arn:aws:s3:::bucket',
        ]]));

        $foreign = $this->request('PUT', '/bucket?logging');
        $foreign->setAttribute('s3.bucket', 'bucket');
        $foreign->setAttribute('s3.operation', S3Operation::PutBucketLogging);
        $foreign->setAttribute('ownerId', 'foreign-account');
        try {
            (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($foreign, new PolicyTerminalHandler());
            $this->fail('A resource policy must not let another account change bucket logging.');
        } catch (AccessDeniedException) {
            $this->assertNull($this->metadata->getBucketLogging('bucket'));
        }

        $owner = $this->request('PUT', '/bucket?logging');
        $owner->setAttribute('s3.bucket', 'bucket');
        $owner->setAttribute('s3.operation', S3Operation::PutBucketLogging);
        $owner->setAttribute('ownerId', 'owner');
        $this->assertSame(200, (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($owner, new PolicyTerminalHandler())->getStatus());
    }

    public function test_account_policy_explicit_deny_applies_before_owner_bypass(): void
    {
        $this->metadata->putAccountPolicy('owner', $this->policy([
            [
                'Effect' => 'Deny',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('DELETE', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_bucket_policy_explicit_deny_applies_to_bucket_owner(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Deny',
                'Principal' => '*',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('DELETE', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_restrict_public_buckets_blocks_public_policy_for_foreign_account(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));
        $this->metadata->putPublicAccessBlock('bucket', false, false, false, true);

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'foreign-account');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_restrict_public_buckets_cannot_be_bypassed_by_object_acl(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:PutObject',
            'Resource' => 'arn:aws:s3:::bucket/uploads/*',
        ]]));
        $this->metadata->putPublicAccessBlock('bucket', false, false, false, true);
        $this->metadata->putAcl('object', 'bucket/public/readme.txt?versionId=null', 'owner', [
            [
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'foreign-account',
                'permission' => 'READ',
            ],
            [
                'granteeType' => 'Group',
                'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers',
                'permission' => 'READ',
            ],
        ]);
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );

        $denied = 0;
        foreach (['foreign-account', ''] as $principal) {
            $request = $this->request('GET', '/bucket/public/readme.txt');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', 'public/readme.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObject);
            $request->setAttribute('ownerId', $principal);

            try {
                $handler->handleRequest($request);
                self::fail('RestrictPublicBuckets was bypassed through an object ACL.');
            } catch (AccessDeniedException) {
                ++$denied;
            }
        }
        self::assertSame(2, $denied);
    }

    public function test_restrict_public_buckets_applies_to_internal_object_read_authorization(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:PutObject',
            'Resource' => 'arn:aws:s3:::bucket/uploads/*',
        ]]));
        $this->metadata->putPublicAccessBlock('bucket', false, false, false, true);
        $this->metadata->putAcl('object', 'bucket/public/readme.txt?versionId=null', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'foreign-account',
            'permission' => 'READ',
        ]]);
        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('ownerId', 'foreign-account');
        $object = $this->metadata->getObjectMetadata('bucket', 'public/readme.txt');
        self::assertNotNull($object);

        $this->expectException(AccessDeniedException::class);
        (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $object);
    }

    public function test_restrict_public_buckets_applies_to_acl_authorized_object_delete(): void
    {
        $this->metadata->putObjectMetadata('bucket', 'foreign.txt', 'tenant:writer', 1, '"etag"', 'text/plain', '');
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:writer',
            'permission' => 'WRITE',
        ]]);
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/public/*',
        ]]));
        $this->metadata->putPublicAccessBlock('bucket', false, false, false, true);

        $this->expectException(AccessDeniedException::class);
        (new ObjectDeletePolicyGuard($this->metadata))->check(
            $this->request('DELETE', '/bucket/foreign.txt'),
            'bucket',
            'foreign.txt',
            null,
            'tenant:writer',
            'owner',
        );
    }

    public function test_named_policy_attached_to_credential_can_allow_request(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow',
            'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/public/*',
        ]]));
        $this->metadata->putNamedPolicy('readonly-public', $this->policy([
            [
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIANAMED12345678',
            secretAccessKey: 'secret',
            ownerId: 'tenant:reader',
            displayName: 'Named Policy Reader',
            policyNames: ['readonly-public'],
        ));

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_missing_named_policy_attached_to_credential_fails_closed(): void
    {
        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIAMISSING123456',
            secretAccessKey: 'secret',
            ownerId: 'tenant:reader',
            displayName: 'Missing Policy Reader',
            policyNames: ['does-not-exist'],
        ));

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_object_lock_configuration_uses_the_aws_identity_policy_action_names(): void
    {
        foreach ([
            [S3Operation::GetObjectLockConfig, 'GET', 's3:GetBucketObjectLockConfiguration', 's3:GetObjectLockConfiguration'],
            [S3Operation::PutObjectLockConfig, 'PUT', 's3:PutBucketObjectLockConfiguration', 's3:PutObjectLockConfiguration'],
        ] as [$operation, $method, $awsAction, $legacyAction]) {
            $this->metadata->putBucketPolicy('bucket', $this->policy([[
                'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
                'Action' => $awsAction, 'Resource' => 'arn:aws:s3:::bucket',
            ]]));
            $request = $this->request($method, '/bucket?object-lock');
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.key', '');
            $request->setAttribute('s3.operation', $operation);
            $request->setAttribute('ownerId', 'tenant:reader');

            $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
                'Effect' => 'Allow', 'Action' => $awsAction, 'Resource' => 'arn:aws:s3:::bucket',
            ]]));
            self::assertSame(200, (new PolicyEnforcementMiddleware($this->metadata))
                ->handleRequest($request, new PolicyTerminalHandler())->getStatus());

            $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
                'Effect' => 'Allow', 'Action' => $legacyAction, 'Resource' => 'arn:aws:s3:::bucket',
            ]]));
            try {
                (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
                self::fail("Legacy action {$legacyAction} authorized {$operation->value}.");
            } catch (AccessDeniedException) {
            }
        }
    }

    public function test_object_lock_configuration_uses_the_aws_bucket_policy_action_name(): void
    {
        $resource = 'arn:aws:s3:::bucket';
        $request = $this->request('GET', '/bucket?object-lock');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', '');
        $request->setAttribute('s3.operation', S3Operation::GetObjectLockConfig);
        $request->setAttribute('ownerId', 'tenant:reader');
        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:GetBucketObjectLockConfiguration', 'Resource' => $resource,
        ]]));

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetObjectLockConfiguration', 'Resource' => $resource,
        ]]));
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new PolicyTerminalHandler(),
            new PolicyEnforcementMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        try {
            $handler->handleRequest($request);
            self::fail('The legacy Object Lock action authorized a cross-account read.');
        } catch (AccessDeniedException) {
        }

        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:GetBucketObjectLockConfiguration', 'Resource' => $resource,
        ]]));
        self::assertSame(200, $handler->handleRequest($request)->getStatus());
    }

    public function test_object_lock_configuration_commit_recheck_uses_the_aws_action_name(): void
    {
        $resource = 'arn:aws:s3:::bucket';
        $request = $this->request('PUT', '/bucket?object-lock');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', '');
        $request->setAttribute('s3.operation', S3Operation::PutObjectLockConfig);
        $request->setAttribute('ownerId', 'tenant:reader');
        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:PutBucketObjectLockConfiguration', 'Resource' => $resource,
        ]]));
        $this->metadata->putBucketPolicy('bucket', $this->policy([[
            'Effect' => 'Allow', 'Principal' => ['AWS' => 'tenant:reader'],
            'Action' => 's3:PutBucketObjectLockConfiguration', 'Resource' => $resource,
        ]]));

        $middleware = new PolicyEnforcementMiddleware($this->metadata);
        self::assertSame(200, $middleware->handleRequest($request, new PolicyTerminalHandler())->getStatus());

        $this->metadata->putAccountPolicy('tenant:reader', $this->policy([[
            'Effect' => 'Allow', 'Action' => 's3:PutObjectLockConfiguration', 'Resource' => $resource,
        ]]));
        $this->expectException(AccessDeniedException::class);
        $middleware->assertAllowedAtCommit($request);
    }

    public function test_bucket_acl_write_commit_recheck_rejects_a_key_created_by_another_owner(): void
    {
        $this->metadata->putAcl('bucket', 'bucket', 'owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'tenant:writer',
            'permission' => 'WRITE',
        ]]);
        $request = $this->request('PUT', '/bucket/commit-new.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'commit-new.txt');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'tenant:writer');

        $middleware = new PolicyEnforcementMiddleware($this->metadata);
        self::assertSame(200, $middleware->handleRequest($request, new PolicyTerminalHandler())->getStatus());
        self::assertSame(200, (new AclEnforcementMiddleware($this->metadata))
            ->handleRequest($request, new PolicyTerminalHandler())->getStatus());

        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'commit-new.txt',
            ownerId: 'owner',
            size: 4,
            etag: '"etag"',
            contentType: 'text/plain',
            storagePath: 'bucket/commit-new.txt',
        );

        $this->expectException(AccessDeniedException::class);
        $middleware->assertAllowedAtCommit($request);
    }

    public function test_account_policy_deny_precedes_legacy_multipart_context_error(): void
    {
        $this->metadata->createMultipartUpload('legacy-account-deny', 'bucket', 'legacy.txt', 'owner');
        $this->metadata->putAccountPolicy('owner', $this->policy([[
            'Effect' => 'Deny',
            'Action' => 's3:PutObject',
            'Resource' => 'arn:aws:s3:::bucket/legacy.txt',
        ]]));

        $this->expectException(AccessDeniedException::class);
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
            $this->multipartCompletionRequest('legacy-account-deny', 'legacy.txt'),
            new PolicyTerminalHandler(),
        );
    }

    public function test_named_policy_deny_precedes_corrupt_multipart_context_error(): void
    {
        $this->metadata->createMultipartUpload(
            'corrupt-named-deny',
            'bucket',
            'corrupt.txt',
            'owner',
            userMetadata: [
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '{',
            ],
        );
        $this->metadata->putNamedPolicy('deny-completion', $this->policy([[
            'Effect' => 'Deny',
            'Action' => 's3:PutObject',
            'Resource' => 'arn:aws:s3:::bucket/corrupt.txt',
        ]]));
        $request = $this->multipartCompletionRequest('corrupt-named-deny', 'corrupt.txt');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIAMPUDENY123456',
            secretAccessKey: 'secret',
            ownerId: 'owner',
            policyNames: ['deny-completion'],
        ));

        $this->expectException(AccessDeniedException::class);
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_missing_base_permission_precedes_legacy_multipart_context_error(): void
    {
        $this->metadata->createMultipartUpload('legacy-no-access', 'bucket', 'foreign.txt', 'tenant:uploader');
        $this->metadata->putAccountPolicy('tenant:uploader', $this->policy([[
            'Effect' => 'Allow',
            'Action' => 's3:GetObject',
            'Resource' => 'arn:aws:s3:::bucket/foreign.txt',
        ]]));

        $this->expectException(AccessDeniedException::class);
        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
            $this->multipartCompletionRequest('legacy-no-access', 'foreign.txt', 'tenant:uploader'),
            new PolicyTerminalHandler(),
        );
    }

    public function test_authorized_owner_receives_specific_multipart_context_errors(): void
    {
        $middleware = new PolicyEnforcementMiddleware($this->metadata);

        $this->metadata->createMultipartUpload('legacy-allowed', 'bucket', 'legacy.txt', 'owner');
        try {
            $middleware->handleRequest(
                $this->multipartCompletionRequest('legacy-allowed', 'legacy.txt'),
                new PolicyTerminalHandler(),
            );
            self::fail('An authorized legacy multipart upload must require re-initiation.');
        } catch (OperationAbortedException $error) {
            self::assertStringContainsString('initiate a new upload', $error->getMessage());
        }

        $this->metadata->createMultipartUpload(
            'corrupt-allowed',
            'bucket',
            'corrupt.txt',
            'owner',
            userMetadata: [
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '{',
            ],
        );
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('policy header snapshot is invalid');
        $middleware->handleRequest(
            $this->multipartCompletionRequest('corrupt-allowed', 'corrupt.txt'),
            new PolicyTerminalHandler(),
        );
    }

    public function test_valid_multipart_context_reaches_authorized_handler(): void
    {
        $this->metadata->createMultipartUpload(
            'valid-context',
            'bucket',
            'valid.txt',
            'owner',
            userMetadata: [
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '[]',
                '__mpu-acl-grants' => json_encode(AclGrantResolver::privateAcl('owner'), JSON_THROW_ON_ERROR),
            ],
        );
        $request = $this->multipartCompletionRequest('valid-context', 'valid.txt');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
            $request,
            new PolicyTerminalHandler(),
        );

        self::assertSame(200, $response->getStatus());
        self::assertIsArray($request->getAttribute('s3.multipartCompletionPolicyContext'));
    }

    public function test_commit_recheck_denial_precedes_newly_corrupt_multipart_context(): void
    {
        $request = $this->multipartCompletionRequest('commit-snapshot', 'commit.txt');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');
        $this->metadata->putAccountPolicy('owner', $this->policy([[
            'Effect' => 'Deny',
            'Action' => 's3:PutObject',
            'Resource' => 'arn:aws:s3:::bucket/commit.txt',
        ]]));

        $this->expectException(AccessDeniedException::class);
        (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
            $request,
            [
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '{',
            ],
            [],
            AclGrantResolver::privateAcl('owner'),
        );
    }

    public function test_every_operation_maps_to_the_expected_iam_action(): void
    {
        $expectedGroups = [
            's3:ListAllMyBuckets' => [S3Operation::ListBuckets],
            's3:CreateBucket' => [S3Operation::CreateBucket],
            's3:DeleteBucket' => [S3Operation::DeleteBucket],
            's3:ListBucket' => [
                S3Operation::HeadBucket,
                S3Operation::ListObjects,
                S3Operation::ListObjectsV2,
            ],
            's3:ListBucketVersions' => [S3Operation::ListObjectVersions],
            's3:GetBucketLocation' => [S3Operation::GetBucketLocation],
            's3:GetBucketVersioning' => [S3Operation::GetBucketVersioning],
            's3:PutBucketVersioning' => [S3Operation::PutBucketVersioning],
            's3:GetBucketAcl' => [S3Operation::GetBucketAcl],
            's3:PutBucketAcl' => [S3Operation::PutBucketAcl],
            's3:GetBucketPolicy' => [S3Operation::GetBucketPolicy],
            's3:PutBucketPolicy' => [S3Operation::PutBucketPolicy],
            's3:DeleteBucketPolicy' => [S3Operation::DeleteBucketPolicy],
            's3:GetBucketCORS' => [S3Operation::GetBucketCors],
            's3:PutBucketCORS' => [S3Operation::PutBucketCors, S3Operation::DeleteBucketCors],
            's3:GetBucketTagging' => [S3Operation::GetBucketTagging],
            's3:PutBucketTagging' => [S3Operation::PutBucketTagging, S3Operation::DeleteBucketTagging],
            's3:GetLifecycleConfiguration' => [S3Operation::GetBucketLifecycle],
            's3:PutLifecycleConfiguration' => [
                S3Operation::PutBucketLifecycle,
                S3Operation::DeleteBucketLifecycle,
            ],
            's3:GetBucketNotification' => [S3Operation::GetBucketNotification],
            's3:PutBucketNotification' => [S3Operation::PutBucketNotification],
            's3:GetEncryptionConfiguration' => [S3Operation::GetBucketEncryption],
            's3:PutEncryptionConfiguration' => [
                S3Operation::PutBucketEncryption,
                S3Operation::DeleteBucketEncryption,
            ],
            's3:GetBucketObjectLockConfiguration' => [S3Operation::GetObjectLockConfig],
            's3:PutBucketObjectLockConfiguration' => [S3Operation::PutObjectLockConfig],
            's3:ListBucketMultipartUploads' => [S3Operation::ListMultipartUploads],
            's3:DeleteObject' => [S3Operation::DeleteObject, S3Operation::DeleteObjects],
            's3:PutObject' => [
                S3Operation::PostObject,
                S3Operation::PutObject,
                S3Operation::CopyObject,
                S3Operation::CreateMultipartUpload,
                S3Operation::UploadPart,
                S3Operation::UploadPartCopy,
                S3Operation::CompleteMultipartUpload,
            ],
            's3:GetBucketWebsite' => [S3Operation::GetBucketWebsite],
            's3:PutBucketWebsite' => [S3Operation::PutBucketWebsite],
            's3:DeleteBucketWebsite' => [S3Operation::DeleteBucketWebsite],
            's3:GetBucketPublicAccessBlock' => [S3Operation::GetPublicAccessBlock],
            's3:PutBucketPublicAccessBlock' => [
                S3Operation::PutPublicAccessBlock,
                S3Operation::DeletePublicAccessBlock,
            ],
            's3:GetBucketPolicyStatus' => [S3Operation::GetBucketPolicyStatus],
            's3:GetBucketLogging' => [S3Operation::GetBucketLogging],
            's3:PutBucketLogging' => [S3Operation::PutBucketLogging],
            's3:GetBucketOwnershipControls' => [S3Operation::GetBucketOwnershipControls],
            's3:PutBucketOwnershipControls' => [S3Operation::PutBucketOwnershipControls, S3Operation::DeleteBucketOwnershipControls],
            's3:GetObject' => [
                S3Operation::GetObject,
                S3Operation::HeadObject,
                S3Operation::SelectObjectContent,
                S3Operation::GetObjectAttributes,
            ],
            's3:GetObjectAcl' => [S3Operation::GetObjectAcl],
            's3:PutObjectAcl' => [S3Operation::PutObjectAcl],
            's3:GetObjectTagging' => [S3Operation::GetObjectTagging],
            's3:PutObjectTagging' => [S3Operation::PutObjectTagging],
            's3:DeleteObjectTagging' => [S3Operation::DeleteObjectTagging],
            's3:GetObjectRetention' => [S3Operation::GetObjectRetention],
            's3:PutObjectRetention' => [S3Operation::PutObjectRetention],
            's3:GetObjectLegalHold' => [S3Operation::GetObjectLegalHold],
            's3:PutObjectLegalHold' => [S3Operation::PutObjectLegalHold],
            's3:RestoreObject' => [S3Operation::RestoreObject],
            's3:AbortMultipartUpload' => [S3Operation::AbortMultipartUpload],
            's3:ListMultipartUploadParts' => [S3Operation::ListParts],
        ];

        $seen = [];
        foreach ($expectedGroups as $action => $operations) {
            foreach ($operations as $operation) {
                $this->assertSame($action, PolicyEnforcementMiddleware::operationToAction($operation));
                $seen[] = $operation->value;
            }
        }

        sort($seen);
        $allOperations = array_map(
            static fn(S3Operation $operation): string => $operation->value,
            S3Operation::cases(),
        );
        sort($allOperations);

        $this->assertCount(69, $seen);
        $this->assertSame($allOperations, $seen);
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string|list<string>> $headers
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): Request
    {
        return new Request(
            new PolicyTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
    }

    private function multipartCompletionRequest(string $uploadId, string $key, string $ownerId = 'owner'): Request
    {
        $request = $this->request('POST', '/bucket/' . $key . '?uploadId=' . $uploadId);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('s3.operation', S3Operation::CompleteMultipartUpload);
        $request->setAttribute('ownerId', $ownerId);

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $statements
     */
    private function policy(array $statements): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ], JSON_THROW_ON_ERROR);
    }
}

final class PolicyTerminalHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(status: 200);
    }
}

final class PolicyTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
