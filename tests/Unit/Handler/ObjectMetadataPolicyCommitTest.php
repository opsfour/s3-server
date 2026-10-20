<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Handler\Object\PutObjectAclHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAclHandler;
use OpsFour\S3Server\Handler\Object\GetObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Handler\Object\SelectObjectContentHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectTaggingHandler;
use OpsFour\S3Server\Handler\Object\PutObjectTaggingHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\ObjectLock\ObjectLockResponseHeaders;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\TestCase;

final class ObjectMetadataPolicyCommitTest extends TestCase
{
    public function test_unlocked_object_does_not_trigger_additional_object_lock_authorization(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getObjectRetention')
            ->with('bucket', 'key', 'version')->willReturn(null);
        $metadata->expects(self::once())->method('getObjectLegalHold')
            ->with('bucket', 'key', 'version')->willReturn(null);
        $metadata->expects(self::never())->method('getBucketPolicy');
        $metadata->expects(self::never())->method('getAccountPolicy');
        $metadata->expects(self::never())->method('getAcl');
        $request = $this->request('GET', 'http://127.0.0.1/bucket/key', S3Operation::GetObject, 'owner');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');

        $headers = (new ObjectLockResponseHeaders($metadata))->forObject(
            $request,
            new ObjectInfo('bucket', 'key', 3, '"etag"', ownerId: 'owner', versionId: 'version'),
        );

        self::assertSame([], $headers);
    }

    public function test_get_and_head_return_authorized_object_lock_headers(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $versionId = $this->putLockedVersion($metadata, 'GOVERNANCE', 'ON');

            foreach (['GET', 'HEAD'] as $method) {
                $request = $this->request($method, 'http://127.0.0.1/bucket/key', $method === 'GET' ? S3Operation::GetObject : S3Operation::HeadObject, 'owner');
                $response = $this->objectRead($metadata, $request);

                self::assertSame($versionId, $response->getHeader('x-amz-version-id'));
                self::assertSame('GOVERNANCE', $response->getHeader('x-amz-object-lock-mode'));
                self::assertSame('2099-01-01T00:00:00Z', $response->getHeader('x-amz-object-lock-retain-until-date'));
                self::assertSame('ON', $response->getHeader('x-amz-object-lock-legal-hold'));
            }
        });
    }

    public function test_head_returns_object_lock_headers_for_the_selected_version(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $selectedVersion = $this->putLockedVersion($metadata, 'GOVERNANCE', 'ON');
            $currentVersion = $metadata->putObjectVersioned('bucket', 'key', 'owner', 3, '"new"', 'text/plain', 'new-path');
            $metadata->putObjectRetention('bucket', 'key', 'COMPLIANCE', '2098-01-01T00:00:00Z', $currentVersion);
            $metadata->putObjectLegalHold('bucket', 'key', 'OFF', $currentVersion);

            $request = $this->request(
                'HEAD',
                'http://127.0.0.1/bucket/key?versionId=' . rawurlencode($selectedVersion),
                S3Operation::HeadObject,
                'owner',
            );
            $response = $this->objectRead($metadata, $request);

            self::assertSame($selectedVersion, $response->getHeader('x-amz-version-id'));
            self::assertSame('GOVERNANCE', $response->getHeader('x-amz-object-lock-mode'));
            self::assertSame('2099-01-01T00:00:00Z', $response->getHeader('x-amz-object-lock-retain-until-date'));
            self::assertSame('ON', $response->getHeader('x-amz-object-lock-legal-hold'));
        });
    }

    public function test_missing_legal_hold_permission_hides_only_that_head_header(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $this->putLockedVersion($metadata, 'GOVERNANCE', 'ON');
            $resource = 'arn:aws:s3:::bucket/key';
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:GetObjectRetention'],
                    'Resource' => $resource,
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putBucketPolicy('bucket', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => 'visitor'],
                    'Action' => ['s3:GetObject', 's3:GetObjectRetention'],
                    'Resource' => $resource,
                ]],
            ], JSON_THROW_ON_ERROR));

            $request = $this->request('HEAD', 'http://127.0.0.1/bucket/key', S3Operation::HeadObject, 'visitor');
            $response = $this->objectRead($metadata, $request);

            self::assertSame(200, $response->getStatus());
            self::assertSame('GOVERNANCE', $response->getHeader('x-amz-object-lock-mode'));
            self::assertSame('2099-01-01T00:00:00Z', $response->getHeader('x-amz-object-lock-retain-until-date'));
            self::assertNull($response->getHeader('x-amz-object-lock-legal-hold'));
        });
    }

    public function test_explicit_delete_marker_keeps_its_cross_account_object_owner(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $markerVersion = $metadata->deleteObjectVersioned('bucket', 'key', 'writer');
            $metadata->putAccountPolicy('writer', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Action' => ['s3:GetObject', 's3:GetObjectVersion'],
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));

            $explicit = $this->request(
                'GET',
                'http://127.0.0.1/bucket/key?versionId=' . rawurlencode($markerVersion),
                S3Operation::GetObject,
                'writer',
            );
            $response = (new PolicyEnforcementMiddleware($metadata))->handleRequest($explicit, $this->terminal(
                static fn(): Response => (new AclEnforcementMiddleware($metadata))->handleRequest(
                    $explicit,
                    new class implements RequestHandler {
                        public function handleRequest(Request $request): Response
                        {
                            return new Response(204);
                        }
                    },
                ),
            ));
            self::assertSame(204, $response->getStatus());
            self::assertSame('writer', $explicit->getAttribute('s3.cachedObjectOwner'));
            self::assertSame('Allow', $explicit->getAttribute('s3.policyResult'));

            $current = $this->request('GET', 'http://127.0.0.1/bucket/key', S3Operation::GetObject, 'writer');
            $this->expectException(AccessDeniedException::class);
            (new PolicyEnforcementMiddleware($metadata))->handleRequest($current, $this->terminal(
                static fn(): Response => (new AclEnforcementMiddleware($metadata))->handleRequest(
                    $current,
                    new class implements RequestHandler {
                        public function handleRequest(Request $request): Response
                        {
                            return new Response(204);
                        }
                    },
                ),
            ));
        });
    }

    public function test_current_delete_marker_is_missing_for_select_and_tagging(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $metadata->deleteObjectVersioned('bucket', 'key', 'owner');
            $storage = $this->createMock(StorageBackend::class);
            $storage->expects(self::never())->method('getObjectByPath');

            foreach ([
                [S3Operation::GetObjectTagging, 'GET', 'tagging'],
                [S3Operation::SelectObjectContent, 'POST', 'select&select-type=2'],
            ] as [$operation, $method, $query]) {
                $request = $this->request($method, 'http://127.0.0.1/bucket/key?' . $query, $operation, 'owner');
                $handler = $operation === S3Operation::GetObjectTagging
                    ? new GetObjectTaggingHandler($metadata)
                    : new SelectObjectContentHandler($metadata, $storage);
                try {
                    (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                        static fn(): Response => (new CurrentObjectReadAuthorizationMiddleware($metadata))
                            ->handleRequest($request, $handler),
                    ));
                    self::fail($operation->value . ' must treat the current delete marker as missing.');
                } catch (NoSuchKeyException $error) {
                    self::assertSame([], $error->getExtraHeaders(), $operation->value);
                }
            }
        });
    }

    public function test_current_delete_marker_keeps_attributes_error_headers(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $markerVersion = $metadata->deleteObjectVersioned('bucket', 'key', 'owner');
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key?attributes', S3Operation::GetObjectAttributes, 'owner');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static fn(): Response => (new CurrentObjectReadAuthorizationMiddleware($metadata))
                        ->handleRequest($request, new GetObjectAttributesHandler($metadata)),
                ));
                self::fail('Current delete marker must return the established attributes error.');
            } catch (NoSuchKeyException $error) {
                self::assertSame([
                    'x-amz-delete-marker' => 'true',
                    'x-amz-version-id' => $markerVersion,
                ], $error->getExtraHeaders());
            }
        });
    }

    public function test_attributes_error_does_not_expose_new_delete_marker_after_null_snapshot(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->deleteObjectMetadata('bucket', 'key');
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key?attributes', S3Operation::GetObjectAttributes, 'owner');
            $markerVersion = null;

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request, &$markerVersion): Response {
                        $markerVersion = $metadata->deleteObjectVersioned('bucket', 'key', 'owner');

                        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                            $request,
                            new GetObjectAttributesHandler($metadata),
                        );
                    },
                ));
                self::fail('The new marker must not be exposed through an attributes error.');
            } catch (AccessDeniedException $error) {
                self::assertSame([], $error->getExtraHeaders());
                self::assertNotNull($markerVersion);
                self::assertSame($markerVersion, $metadata->getCurrentObjectVersion('bucket', 'key')?->versionId);
            }
        });
    }

    public function test_attributes_error_is_preserved_when_object_identity_is_unchanged(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key?attributes', S3Operation::GetObjectAttributes, 'owner');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                            $request,
                            new GetObjectAttributesHandler($metadata),
                        );
                    },
                ));
                self::fail('Missing attributes header must keep its original error.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('x-amz-object-attributes header is required.', $error->getMessage());
            }
        });
    }

    public function test_changed_current_object_owner_blocks_tag_read_after_initial_authorization(): void
    {
        $this->assertChangedOwnerBlocksMetadataRead(S3Operation::GetObjectTagging);
    }

    public function test_changed_current_object_owner_blocks_acl_read_after_initial_authorization(): void
    {
        $this->assertChangedOwnerBlocksMetadataRead(S3Operation::GetObjectAcl);
    }

    public function test_same_owner_null_version_overwrite_blocks_stale_head_response(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $request = $this->request('HEAD', 'http://127.0.0.1/bucket/key', S3Operation::HeadObject, 'owner');
            $request->setAttribute('credential', null);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                            $request,
                            new class ($metadata) implements RequestHandler {
                                public function __construct(private readonly SqliteMetadataStore $metadata) {}

                                public function handleRequest(Request $request): Response
                                {
                                    $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, md5('new'), 'text/plain', 'new-path');

                                    return (new HeadObjectHandler($this->metadata))->handleRequest($request);
                                }
                            },
                        );
                    },
                ));
                self::fail('A null-version overwrite must not return the stale response.');
            } catch (AccessDeniedException) {
                self::assertSame('new-path', $metadata->getObjectMetadata('bucket', 'key')?->systemMetadata['storagePath']);
            }
        });
    }

    public function test_revoked_cross_account_policy_blocks_tag_read_before_response(): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata): void {
            $metadata->putObjectTagging('bucket', 'key', [['key' => 'secret', 'value' => 'owner-only']]);
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => 's3:GetObjectTagging',
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putBucketPolicy('bucket', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Principal' => ['AWS' => 'visitor'],
                    'Action' => 's3:GetObjectTagging', 'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key?tagging', S3Operation::GetObjectTagging, 'visitor');

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request): Response {
                        $metadata->deleteBucketPolicy('bucket');

                        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                            $request,
                            new GetObjectTaggingHandler($metadata),
                        );
                    },
                ));
                self::fail('Revoked bucket policy must block the foreign metadata read.');
            } catch (AccessDeniedException) {
                self::assertSame('owner-only', $metadata->getObjectTagging('bucket', 'key')[0]['value']);
            }
        });
    }

    public function test_revoked_bucket_policy_replaces_stale_no_such_key_error(): void
    {
        $this->assertRevokedReadAuthorizationReplacesHandlerError('bucket-policy');
    }

    public function test_revoked_identity_policy_replaces_stale_no_such_key_error(): void
    {
        $this->assertRevokedReadAuthorizationReplacesHandlerError('identity-policy');
    }

    public function test_ignore_public_acls_replaces_stale_no_such_key_error(): void
    {
        $this->assertAclChangeReplacesHandlerError(true);
    }

    public function test_revoked_object_acl_replaces_stale_no_such_key_error(): void
    {
        $this->assertAclChangeReplacesHandlerError(false);
    }

    private function assertAclChangeReplacesHandlerError(bool $enableIgnorePublicAcls): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata) use ($enableIgnorePublicAcls): void {
            $action = 's3:GetObject';
            $resource = 'arn:aws:s3:::bucket/key';
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => $action, 'Resource' => $resource,
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putAcl('object', 'bucket/key?versionId=null', 'owner', [[
                'granteeType' => 'Group',
                'granteeId' => 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers',
                'permission' => 'READ',
            ]]);
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key', S3Operation::GetObject, 'visitor');
            $acl = new AclEnforcementMiddleware($metadata);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static fn(): Response => $acl->handleRequest($request, new class ($metadata, $request, $enableIgnorePublicAcls) implements RequestHandler {
                        public function __construct(
                            private readonly SqliteMetadataStore $metadata,
                            private readonly Request $request,
                            private readonly bool $enableIgnorePublicAcls,
                        ) {}

                        public function handleRequest(Request $request): Response
                        {
                            if ($this->enableIgnorePublicAcls) {
                                $this->metadata->putPublicAccessBlock('bucket', false, true, false, false);
                            } else {
                                $this->metadata->putAcl('object', 'bucket/key?versionId=null', 'owner', [[
                                    'granteeType' => 'CanonicalUser',
                                    'granteeId' => 'owner',
                                    'permission' => 'FULL_CONTROL',
                                ]]);
                            }

                            return (new CurrentObjectReadAuthorizationMiddleware($this->metadata))->handleRequest(
                                $this->request,
                                new class implements RequestHandler {
                                    public function handleRequest(Request $request): Response
                                    {
                                        throw new NoSuchKeyException();
                                    }
                                },
                            );
                        }
                    }),
                ));
                self::fail('The ACL authorization change must replace the stale handler error with AccessDenied.');
            } catch (AccessDeniedException) {
                if ($enableIgnorePublicAcls) {
                    self::assertTrue($metadata->getPublicAccessBlock('bucket')['ignorePublicAcls'] ?? false);
                } else {
                    self::assertSame('owner', $metadata->getAcl('object', 'bucket/key?versionId=null')[0]['granteeId']);
                }
            }
        });
    }

    /** @param 'bucket-policy'|'identity-policy' $revocation */
    private function assertRevokedReadAuthorizationReplacesHandlerError(string $revocation): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata) use ($revocation): void {
            $action = 's3:GetObject';
            $resource = 'arn:aws:s3:::bucket/key';
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Action' => $action, 'Resource' => $resource,
                ]],
            ], JSON_THROW_ON_ERROR));
            $metadata->putBucketPolicy('bucket', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow', 'Principal' => ['AWS' => 'visitor'],
                    'Action' => $action, 'Resource' => $resource,
                ]],
            ], JSON_THROW_ON_ERROR));
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key', S3Operation::GetObject, 'visitor');
            $acl = new AclEnforcementMiddleware($metadata);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static fn(): Response => $acl->handleRequest($request, new class ($metadata, $request, $revocation) implements RequestHandler {
                        public function __construct(
                            private readonly SqliteMetadataStore $metadata,
                            private readonly Request $request,
                            private readonly string $revocation,
                        ) {}

                        public function handleRequest(Request $request): Response
                        {
                            if ($this->revocation === 'bucket-policy') {
                                $this->metadata->deleteBucketPolicy('bucket');
                            } else {
                                $this->metadata->putAccountPolicy('visitor', '{"Statement":[]}');
                            }

                            return (new CurrentObjectReadAuthorizationMiddleware($this->metadata))->handleRequest(
                                $this->request,
                                new class implements RequestHandler {
                                    public function handleRequest(Request $request): Response
                                    {
                                        throw new NoSuchKeyException();
                                    }
                                },
                            );
                        }
                    }),
                ));
                self::fail("Revoked {$revocation} must replace the stale handler error with AccessDenied.");
            } catch (AccessDeniedException) {
                self::assertNotNull($metadata->getObjectMetadata('bucket', 'key'));
            }
        });
    }

    private function assertChangedOwnerBlocksMetadataRead(S3Operation $operation): void
    {
        $this->withMetadata(function (SqliteMetadataStore $metadata) use ($operation): void {
            $metadata->putObjectMetadata('bucket', 'key', 'visitor', 3, md5('old'), 'text/plain', 'visitor-path');
            $metadata->putAccountPolicy('visitor', json_encode([
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Action' => PolicyEnforcementMiddleware::operationToAction($operation),
                    'Resource' => 'arn:aws:s3:::bucket/key',
                ]],
            ], JSON_THROW_ON_ERROR));
            $selector = $operation === S3Operation::GetObjectAcl ? 'acl' : 'tagging';
            $request = $this->request('GET', 'http://127.0.0.1/bucket/key?' . $selector, $operation, 'visitor');
            $request->setAttribute('credential', null);

            try {
                (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
                    static function () use ($metadata, $request, $operation): Response {
                        $metadata->putObjectMetadata('bucket', 'key', 'owner', 3, md5('new'), 'text/plain', 'owner-path');
                        $metadata->putObjectTagging('bucket', 'key', [['key' => 'secret', 'value' => 'owner-only']]);

                        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                            $request,
                            new class ($metadata, $operation) implements RequestHandler {
                                public function __construct(
                                    private readonly SqliteMetadataStore $metadata,
                                    private readonly S3Operation $operation,
                                ) {}

                                public function handleRequest(Request $request): Response
                                {
                                    return $this->operation === S3Operation::GetObjectAcl
                                        ? (new GetObjectAclHandler($this->metadata))->handleRequest($request)
                                        : (new GetObjectTaggingHandler($this->metadata))->handleRequest($request);
                                }
                            },
                        );
                    },
                ));
                self::fail('A former object owner must not read the new owner metadata.');
            } catch (AccessDeniedException) {
                self::assertSame('owner', $metadata->getObjectMetadata('bucket', 'key')?->ownerId);
            }
        });
    }

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

    private function objectRead(SqliteMetadataStore $metadata, Request $request): Response
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer('old'));
        $operation = $request->getAttribute('s3.operation');
        $handler = $operation === S3Operation::GetObject
            ? new GetObjectHandler($metadata, $storage)
            : new HeadObjectHandler($metadata);

        return (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, $this->terminal(
            static fn(): Response => (new AclEnforcementMiddleware($metadata))->handleRequest(
                $request,
                new class ($metadata, $handler) implements RequestHandler {
                    public function __construct(
                        private readonly SqliteMetadataStore $metadata,
                        private readonly RequestHandler $handler,
                    ) {}

                    public function handleRequest(Request $request): Response
                    {
                        return (new CurrentObjectReadAuthorizationMiddleware($this->metadata))
                            ->handleRequest($request, $this->handler);
                    }
                },
            ),
        ));
    }

    private function putLockedVersion(SqliteMetadataStore $metadata, string $mode, string $legalHold): string
    {
        $metadata->setBucketVersioning('bucket', 'Enabled');
        $metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Enabled']);
        $versionId = $metadata->putObjectVersioned('bucket', 'key', 'owner', 3, '"old"', 'text/plain', 'old-path');
        $metadata->putObjectRetention('bucket', 'key', $mode, '2099-01-01T00:00:00Z', $versionId);
        $metadata->putObjectLegalHold('bucket', 'key', $legalHold, $versionId);

        return $versionId;
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
