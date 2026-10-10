<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Object\PutObjectAclHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\PutObjectTaggingHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class ObjectMetadataPolicyCommitTest extends TestCase
{
    public function test_new_deny_after_initial_authorization_blocks_tag_change(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'class', 'value' => 'old']]);
            $request = $this->request(
                'PUT',
                'http://127.0.0.1/bucket/key?tagging',
                S3Operation::PutObjectTagging,
                'owner',
                '<Tagging><TagSet><Tag><Key>class</Key><Value>new</Value></Tag></TagSet></Tagging>',
            );
            $policy = json_encode([
                'Statement' => [[
                    'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObjectTagging',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $policy, $request): Response {
                        $metadata->putBucketPolicy('bucket', $policy);

                        return (new PutObjectTaggingHandler($metadata))->handleRequest($request);
                    },
                ));
                self::fail('A newly effective Deny must prevent the metadata commit.');
            } catch (AccessDeniedException) {
                self::assertSame('old', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_revoked_cross_account_allow_blocks_tag_delete(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'class', 'value' => 'old']]);
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => 's3:DeleteObjectTagging',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putBucketPolicy('bucket', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Principal' => ['AWS' => 'visitor'],
                    'Action' => 's3:DeleteObjectTagging',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key?tagging', S3Operation::DeleteObjectTagging, 'visitor');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        $metadata->deleteBucketPolicy('bucket');

                        return (new DeleteObjectTaggingHandler($metadata))->handleRequest($request);
                    },
                ));
                self::fail('A revoked bucket policy Allow must prevent the metadata commit.');
            } catch (AccessDeniedException) {
                self::assertSame('old', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_new_local_identity_deny_blocks_tag_delete(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'class', 'value' => 'old']]);
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key?tagging', S3Operation::DeleteObjectTagging, 'owner');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        $metadata->putAccountPolicy('owner', json_encode([
                            'Statement' => [[
                                'Effect' => 'Deny', 'Action' => 's3:DeleteObjectTagging',
                                'Resource' => 'arn:aws:s3:::bucket/key',
                            ]],
                        ], JSON_THROW_ON_ERROR));

                        return (new DeleteObjectTaggingHandler($metadata))->handleRequest($request);
                    },
                ));
                self::fail('A changed local identity Deny must prevent the metadata commit.');
            } catch (AccessDeniedException) {
                self::assertSame('old', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_new_named_identity_deny_blocks_tag_delete(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'class', 'value' => 'old']]);
            $metadata->putNamedPolicy('mutable-policy', '{"Statement":[]}');
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key?tagging', S3Operation::DeleteObjectTagging, 'owner');
            $request->setAttribute('credential', new Credential(
                accessKeyId: 'named-policy-key',
                secretAccessKey: 'secret',
                ownerId: 'owner',
                policyNames: ['mutable-policy'],
            ));

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        $metadata->putNamedPolicy('mutable-policy', json_encode([
                            'Statement' => [[
                                'Effect' => 'Deny', 'Action' => 's3:DeleteObjectTagging',
                                'Resource' => 'arn:aws:s3:::bucket/key',
                            ]],
                        ], JSON_THROW_ON_ERROR));

                        return (new DeleteObjectTaggingHandler($metadata))->handleRequest($request);
                    },
                ));
                self::fail('A changed named identity Deny must prevent the metadata commit.');
            } catch (AccessDeniedException) {
                self::assertSame('old', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_ownership_enforcement_revokes_object_owner_tag_permission(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectMetadata('bucket', 'key', 'visitor', 3, md5('old'), 'text/plain', 'visitor-path');
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'class', 'value' => 'old']]);
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => 's3:DeleteObjectTagging',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $request = $this->request('DELETE', 'http://127.0.0.1/bucket/key?tagging', S3Operation::DeleteObjectTagging, 'visitor');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        $metadata->putBucketOwnershipControls('bucket', 'BucketOwnerEnforced');

                        return (new DeleteObjectTaggingHandler($metadata))->handleRequest($request);
                    },
                ));
                self::fail('BucketOwnerEnforced must revoke the former object owner permission.');
            } catch (AccessDeniedException) {
                self::assertSame('old', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_ignore_public_acls_revokes_authenticated_group_object_acl(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => 's3:PutObjectAcl',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putAcl('object', 'bucket/key?versionId=null', 'owner', [[
                'granteeType' => 'Group',
                'granteeId' => 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers',
                'permission' => 'WRITE_ACP',
            ]]);
            $request = $this->request('PUT', 'http://127.0.0.1/bucket/key?acl', S3Operation::PutObjectAcl, 'visitor');
            $request->setHeader('x-amz-acl', 'private');
            $acl = new AclEnforcementMiddleware($metadata);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request, $acl): Response {
                        return $acl->handleRequest($request, new class ($metadata, $request) implements RequestHandler {
                            public function __construct(private readonly SqliteMetadataStore $metadata, private readonly Request $request) {}

                            public function handleRequest(Request $request): Response
                            {
                                $this->metadata->putPublicAccessBlock('bucket', false, true, false, false);

                                return (new PutObjectAclHandler($this->metadata))->handleRequest($this->request);
                            }
                        });
                    },
                ));
                self::fail('IgnorePublicAcls must revoke the group ACL before commit.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $metadata->getAcl('object', 'bucket/key?versionId=null'));
                $publicAccessBlock = $metadata->getPublicAccessBlock('bucket');
                self::assertNotNull($publicAccessBlock);
                self::assertTrue($publicAccessBlock['ignorePublicAcls']);
            }
        });
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $url, S3Operation $operation, string $owner, string $body = ''): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new($url), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('ownerId', $owner);

        return $request;
    }

    /** @param \Closure(): Response $callback */
    private function terminal(\Closure $callback): RequestHandler
    {
        return new class ($callback) implements RequestHandler {
            public function __construct(private readonly \Closure $callback) {}

            public function handleRequest(Request $request): Response
            {
                return ($this->callback)();
            }
        };
    }

    /** @param \Closure(SqliteMetadataStore): void $callback */
    private function withMetadata(\Closure $callback): void
    {
        $path = sys_get_temp_dir() . '/s3-metadata-policy-commit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->putObjectMetadata('bucket', 'key', 'owner', 3, md5('old'), 'text/plain', 'old-path');

        try {
            $callback($metadata);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
