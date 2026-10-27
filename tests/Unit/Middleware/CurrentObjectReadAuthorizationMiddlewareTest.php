<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutSummary;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentObjectReadAuthorizationMiddlewareTest extends TestCase
{
    public function test_head_retries_once_after_restore_completion_and_closes_stale_response(): void
    {
        [$metadata, $authorized, $request] = $this->pendingRestoreHeadFixture();
        $staleBody = new TrackingObjectReadStream();
        $retryBody = new TrackingObjectReadStream();
        $calls = 0;

        $response = (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            $this->handler(function (Request $request) use ($metadata, $staleBody, $retryBody, &$calls): Response {
                ++$calls;
                $snapshot = $request->getAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
                self::assertInstanceOf(ObjectInfo::class, $snapshot);
                if ($calls === 1) {
                    self::assertSame('pending', $snapshot->restoreStatus);
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, ['x-restore-state' => 'pending'], $staleBody);
                }

                self::assertSame('restored', $snapshot->restoreStatus);

                return new Response(200, ['x-restore-state' => 'restored'], $retryBody);
            }),
        );

        self::assertSame('pending', $authorized->restoreStatus);
        self::assertSame(2, $calls);
        self::assertSame('restored', $response->getHeader('x-restore-state'));
        self::assertTrue($staleBody->closeAttempted);
        self::assertFalse($retryBody->closeAttempted);
        $refreshed = $request->getAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
        self::assertInstanceOf(ObjectInfo::class, $refreshed);
        self::assertSame('2000-01-02T03:04:05+00:00', $refreshed->lastModified->format(\DateTimeInterface::ATOM));
    }

    public function test_explicitly_versioned_head_retries_once_after_that_version_completes_restore(): void
    {
        [$metadata, $authorized, $request, $versionId] = $this->pendingRestoreHeadFixture(versioned: true);
        self::assertNotNull($versionId);
        self::assertSame($versionId, $authorized->versionId);
        $metadata->putObjectVersioned('bucket', 'key', 'owner', 5, '"newer"', 'text/plain', 'newer-path', 'STANDARD');
        $request->setUri(Http::new('http://127.0.0.1/bucket/key?versionId=' . rawurlencode($versionId)));
        $staleBody = new TrackingObjectReadStream();
        $retryBody = new TrackingObjectReadStream();
        $calls = 0;

        $response = (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            $this->handler(function (Request $request) use ($metadata, $versionId, $staleBody, $retryBody, &$calls): Response {
                ++$calls;
                $snapshot = $request->getAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
                self::assertInstanceOf(ObjectInfo::class, $snapshot);
                self::assertSame($versionId, $snapshot->versionId);
                if ($calls === 1) {
                    self::assertSame('pending', $snapshot->restoreStatus);
                    $this->completeRestore($metadata, 'version-restored-path', $versionId);

                    return new Response(200, body: $staleBody);
                }

                self::assertSame('restored', $snapshot->restoreStatus);
                self::assertSame('version-restored-path', $snapshot->restoredStoragePath);

                return new Response(200, ['x-restore-state' => 'restored'], $retryBody);
            }),
        );

        self::assertSame(2, $calls);
        self::assertSame('restored', $response->getHeader('x-restore-state'));
        self::assertTrue($staleBody->closeAttempted);
        self::assertFalse($retryBody->closeAttempted);
    }

    public function test_head_with_positive_content_length_never_retries_after_restore_completion(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $request->setHeader('content-length', '1');

        $this->assertRestoreCompletionIsNotReplayed($metadata, $request);
    }

    public function test_head_with_transfer_encoding_never_retries_after_restore_completion(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $request->setHeader('transfer-encoding', 'chunked');

        $this->assertRestoreCompletionIsNotReplayed($metadata, $request);
    }

    /** @param array<string, mixed> $changedFields */
    #[DataProvider('immutableRestoreFieldDriftProvider')]
    public function test_head_never_retries_when_restore_completion_changes_immutable_fields(array $changedFields): void
    {
        $authorized = $this->restoringObject();
        $current = $this->restoringObject([
            'restoreStatus' => 'restored',
            'restoredStoragePath' => 'restored-path',
            'restoredStorageTier' => 'STANDARD',
            'restoreExpiresAt' => new \DateTimeImmutable('2030-01-02T00:00:00Z'),
            ...$changedFields,
        ]);
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getCurrentObjectVersion')
            ->with('bucket', 'key')
            ->willReturn($current);
        $metadata->expects(self::once())->method('getBucket')->with('bucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'generation',
        ));
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $body = new TrackingObjectReadStream();
        $calls = 0;
        $request = $this->request($authorized, S3Operation::HeadObject);
        $request->setMethod('HEAD');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(static function () use ($body, &$calls): Response {
                    ++$calls;

                    return new Response(200, body: $body);
                }),
            );
            self::fail('Immutable object drift during restore completion must fail closed.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Object changed during authorization.', $error->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertTrue($body->closeAttempted);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function immutableRestoreFieldDriftProvider(): iterable
    {
        yield 'size' => [['size' => 5]];
        yield 'etag' => [['etag' => '"changed"']];
        yield 'content type' => [['contentType' => 'application/json']];
        yield 'storage tier' => [['storageTier' => 'DEEP_ARCHIVE']];
        yield 'owner' => [['ownerId' => 'other-owner']];
        yield 'user metadata' => [['userMetadata' => ['color' => 'blue']]];
        yield 'primary storage path' => [['systemMetadata' => ['storagePath' => 'changed-cold-path']]];
        yield 'last modified' => [['lastModified' => new \DateTimeImmutable('2030-01-01T00:00:01Z')]];
        yield 'part layout digest' => [['partLayout' => new ObjectPartLayoutSummary(ObjectPartLayout::KNOWN_SINGLE, 1, null, str_repeat('a', 64))]];
    }

    public function test_head_denies_second_snapshot_drift_without_a_third_attempt(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $staleBody = new TrackingObjectReadStream();
        $retryBody = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $staleBody, $retryBody, &$calls): Response {
                    ++$calls;
                    if ($calls === 1) {
                        $this->completeRestore($metadata, 'first-restored-path');

                        return new Response(200, body: $staleBody);
                    }
                    $this->completeRestore($metadata, 'second-restored-path');

                    return new Response(200, body: $retryBody);
                }),
            );
            self::fail('A second snapshot drift must fail closed.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Object changed during authorization.', $error->getMessage());
        }

        self::assertSame(2, $calls);
        self::assertTrue($staleBody->closeAttempted);
        self::assertTrue($retryBody->closeAttempted);
    }

    public function test_head_does_not_retry_when_object_owner_changes_with_restore_state(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $staleBody = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $staleBody, &$calls): Response {
                    ++$calls;
                    $metadata->putObjectMetadata('bucket', 'key', 'other-owner', 4, '"etag"', 'text/plain', 'cold-path', 'GLACIER');
                    $metadata->updateObjectPlacement('bucket', 'key', null, 'GLACIER', 'GLACIER', 'cold-path');
                    $metadata->updateObjectRestoreStateWithTier(
                        'bucket',
                        'key',
                        null,
                        'restored',
                        'restored-path',
                        new \DateTimeImmutable('+1 day'),
                        'STANDARD',
                    );

                    return new Response(200, body: $staleBody);
                }),
            );
            self::fail('An ownership change must not be replayed.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $calls);
        self::assertTrue($staleBody->closeAttempted);
    }

    public function test_head_policy_drift_is_denied_before_retry_handler_runs(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $staleBody = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $staleBody, &$calls): Response {
                    ++$calls;
                    $metadata->putBucketPolicy('bucket', json_encode([
                        'Version' => '2012-10-17',
                        'Statement' => [[
                            'Effect' => 'Deny',
                            'Principal' => '*',
                            'Action' => 's3:GetObject',
                            'Resource' => 'arn:aws:s3:::bucket/key',
                        ]],
                    ], JSON_THROW_ON_ERROR));
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $staleBody);
                }),
            );
            self::fail('A new explicit policy denial must prevent replay.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $calls);
        self::assertTrue($staleBody->closeAttempted);
    }

    public function test_head_acl_drift_is_denied_before_retry_handler_runs(): void
    {
        [$metadata, $authorized, $request] = $this->pendingRestoreHeadFixture('');
        $metadata->putAcl('object', 'bucket/key?versionId=null', 'owner', [[
            'granteeType' => 'Group',
            'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers',
            'permission' => 'READ',
        ]]);
        $request->setAttribute('s3.policyResult', 'Neutral');
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);
        $staleBody = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $staleBody, &$calls): Response {
                    ++$calls;
                    $metadata->putAcl('object', 'bucket/key?versionId=null', 'owner', []);
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $staleBody);
                }),
            );
            self::fail('Removing the authorizing ACL must prevent replay.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $calls);
        self::assertTrue($staleBody->closeAttempted);
    }

    public function test_select_object_content_never_retries_after_restore_completion(): void
    {
        [$metadata, $authorized, $request] = $this->pendingRestoreHeadFixture();
        $request->setMethod('POST');
        $request->setAttribute('s3.operation', S3Operation::SelectObjectContent);
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);
        $body = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $body, &$calls): Response {
                    ++$calls;
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $body);
                }),
            );
            self::fail('SelectObjectContent must remain single-attempt.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $calls);
        self::assertTrue($body->closeAttempted);
    }

    public function test_get_object_never_retries_after_restore_completion(): void
    {
        [$metadata, $authorized, $request] = $this->pendingRestoreHeadFixture();
        $request->setMethod('GET');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);
        $body = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $body, &$calls): Response {
                    ++$calls;
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $body);
                }),
            );
            self::fail('GetObject must remain single-attempt.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $calls);
        self::assertTrue($body->closeAttempted);
    }

    public function test_head_aborts_without_replay_when_stale_response_cannot_be_closed(): void
    {
        [$metadata, , $request] = $this->pendingRestoreHeadFixture();
        $body = new FailingCloseObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $body, &$calls): Response {
                    ++$calls;
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $body);
                }),
            );
            self::fail('Replay requires a definitively closed stale response.');
        } catch (OperationAbortedException $error) {
            self::assertSame('Unable to dispose the stale response before retrying the request.', $error->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertTrue($body->closeAttempted);
    }

    public function test_identity_drift_closes_response_stream_without_masking_access_denied(): void
    {
        $authorized = $this->object('old-path');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getCurrentObjectVersion')
            ->with('bucket', 'key')
            ->willReturn($this->object('new-path'));
        $metadata->expects(self::once())->method('getBucket')->with('bucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'generation',
        ));
        $stream = new FailingCloseObjectReadStream();
        $request = $this->request($authorized);

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                new class ($stream) implements RequestHandler {
                    public function __construct(private readonly ReadableStream $stream) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->stream);
                    }
                },
            );
            self::fail('Identity drift must reject the already-opened response.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Object changed during authorization.', $error->getMessage());
            self::assertTrue($stream->closeAttempted);
        }
    }

    public function test_storage_tier_drift_with_same_path_closes_response_stream(): void
    {
        $this->assertReadRoutingDriftRejected(
            $this->object('shared-path', storageTier: 'STANDARD'),
            $this->object('shared-path', storageTier: 'GLACIER'),
        );
    }

    public function test_restore_copy_drift_with_same_primary_path_closes_response_stream(): void
    {
        $this->assertReadRoutingDriftRejected(
            $this->object(
                'primary-path',
                storageTier: 'GLACIER',
                restoreStatus: 'restored',
                restoredStorageTier: 'STANDARD',
                restoredStoragePath: 'restore-path',
                restoreExpiresAt: new \DateTimeImmutable('2030-01-01T00:00:00Z'),
            ),
            $this->object('primary-path', storageTier: 'GLACIER'),
        );
    }

    public function test_object_attributes_metadata_drift_with_same_storage_path_closes_stale_response(): void
    {
        $lastModified = new \DateTimeImmutable('2030-01-01T00:00:00Z');

        $this->assertReadRoutingDriftRejected(
            $this->object(
                'shared-path',
                etag: '"old-etag"',
                systemMetadata: ['checksum-sha256' => 'old-checksum'],
                lastModified: $lastModified,
            ),
            $this->object(
                'shared-path',
                etag: '"new-etag"',
                systemMetadata: ['checksum-sha256' => 'new-checksum'],
                lastModified: $lastModified,
            ),
            S3Operation::GetObjectAttributes,
        );
    }

    public function test_explicit_version_read_allows_only_version_listing_state_to_change(): void
    {
        $lastModified = new \DateTimeImmutable('2030-01-01T00:00:00Z');
        $authorized = $this->object(
            'shared-path',
            systemMetadata: ['isLatest' => '1'],
            lastModified: $lastModified,
            versionId: 'version-1',
        );
        $current = $this->object(
            'shared-path',
            systemMetadata: [
                'isLatest' => '0',
                'noncurrentAt' => '2030-01-02T00:00:00.000000Z',
            ],
            lastModified: $lastModified,
            versionId: 'version-1',
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::exactly(2))->method('getObjectMetadataByVersion')
            ->with('bucket', 'key', 'version-1')
            ->willReturn($current);
        $metadata->method('getBucket')->with('bucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'generation',
        ));
        $request = $this->request($authorized, S3Operation::GetObjectAttributes);
        $request->setUri(Http::new('http://127.0.0.1/bucket/key?attributes&versionId=version-1'));

        $response = (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            },
        );

        self::assertSame(200, $response->getStatus());
    }

    public function test_handler_cancellation_is_propagated_without_follow_up_metadata_io(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getCurrentObjectVersion');
        $metadata->expects(self::never())->method('getObjectMetadataByVersion');

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request($this->object('old-path')),
                new class ($cancellation) implements RequestHandler {
                    public function __construct(private readonly CancelledException $cancellation) {}

                    public function handleRequest(Request $request): Response
                    {
                        throw $this->cancellation;
                    }
                },
            );
            self::fail('Cancellation must leave the authorization boundary unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_metadata_read_does_not_rebind_to_a_recreated_bucket_generation(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getBucket')->with('bucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'new-generation',
        ));
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::once())->method('lockOwnerForUpdate')->with('owner');
        $metadata->expects(self::never())->method('getObjectMetadata');
        $handler = $this->createMock(RequestHandler::class);
        $handler->expects(self::never())->method('handleRequest');
        $request = $this->request($this->object('old-path'), S3Operation::GetObjectTagging);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.cachedBucketGeneration', 'old-generation');

        $this->expectException(OperationAbortedException::class);
        $this->expectExceptionMessage('Bucket ownership changed after authorization.');
        (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest($request, $handler);
    }

    public function test_handler_error_revalidation_uses_custom_store_exclusive_lock_fallback(): void
    {
        $authorized = $this->object('old-path');
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'generation',
        ));
        $metadata->method('getCurrentObjectVersion')->willReturn($authorized);
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::once())->method('lockOwnerForUpdate')->with('owner');
        $request = $this->request($authorized);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');
        $expected = new NoSuchKeyException();

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                new class ($expected) implements RequestHandler {
                    public function __construct(private readonly NoSuchKeyException $expected) {}

                    public function handleRequest(Request $request): Response
                    {
                        throw $this->expected;
                    }
                },
            );
            self::fail('A stable handler error must be rethrown after the fallback lock recheck.');
        } catch (NoSuchKeyException $error) {
            self::assertSame($expected, $error);
        }
    }

    public function test_handler_error_revalidation_holds_shared_lock_across_policy_and_snapshot_checks(): void
    {
        $authorized = $this->object('old-path');
        $current = $authorized;
        $events = [];
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$events): BucketInfo {
            $events[] = 'bucket-read';

            return new BucketInfo('bucket', 'owner', generation: 'generation');
        });
        $metadata->method('getCurrentObjectVersion')->willReturnCallback(static function () use (&$current): ObjectInfo {
            return $current;
        });
        $metadata->expects(self::once())->method('prepareOwnerReadLock')->with('owner');
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static function (callable $callback) use (&$events): mixed {
                $events = [];

                return $callback();
            });
        $metadata->expects(self::once())->method('lockOwnerForRead')->with('owner')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'owner-lock';
            });
        $metadata->expects(self::never())->method('lockOwnerForUpdate');
        $metadata->method('getBucketPolicy')->willReturnCallback(static function () use (&$current): ?string {
            $current = new ObjectInfo(
                'bucket',
                'key',
                4,
                '"etag"',
                ownerId: 'owner',
                systemMetadata: ['storagePath' => 'replacement-path'],
            );

            return null;
        });
        $request = $this->request($authorized);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        throw new NoSuchKeyException();
                    }
                },
            );
            self::fail('Snapshot drift during the locked error recheck must replace the handler error.');
        } catch (AccessDeniedException) {
            self::assertSame('owner-lock', $events[0] ?? null);
        }
    }

    private function request(ObjectInfo $authorized, S3Operation $operation = S3Operation::GetObject): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new('http://127.0.0.1/bucket/key'),
        );
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.cachedBucketGeneration', 'generation');
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);

        return $request;
    }

    /** @return array{SqliteMetadataStore, ObjectInfo, Request, ?string} */
    private function pendingRestoreHeadFixture(string $requester = 'owner', bool $versioned = false): array
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $versionId = null;
        if ($versioned) {
            $metadata->setBucketVersioning('bucket', 'Enabled');
            $versionId = $metadata->putObjectVersioned(
                'bucket',
                'key',
                'owner',
                4,
                '"etag"',
                'text/plain',
                'cold-path',
                'GLACIER',
            );
        } else {
            $metadata->putObjectMetadata('bucket', 'key', 'owner', 4, '"etag"', 'text/plain', 'cold-path', 'GLACIER');
        }
        $metadata->updateObjectPlacement('bucket', 'key', $versionId, 'GLACIER', 'GLACIER', 'cold-path');
        $pdo = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec("UPDATE s3_objects SET updated_at = '2000-01-02T03:04:05Z' WHERE bucket = 'bucket' AND key_name = 'key'");
        $metadata->updateObjectRestoreState('bucket', 'key', $versionId, 'pending');
        $authorized = $versionId === null
            ? $metadata->getCurrentObjectVersion('bucket', 'key')
            : $metadata->getObjectMetadataByVersion('bucket', 'key', $versionId);
        self::assertInstanceOf(ObjectInfo::class, $authorized);
        $bucket = $metadata->getBucket('bucket');
        self::assertInstanceOf(BucketInfo::class, $bucket);

        $request = $this->request($authorized, S3Operation::HeadObject);
        $request->setMethod('HEAD');
        $request->setAttribute('ownerId', $requester);
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);
        $request->setAttribute('s3.policyResult', $requester === 'owner' ? 'OwnerBypass' : 'Neutral');

        return [$metadata, $authorized, $request, $versionId];
    }

    private function completeRestore(SqliteMetadataStore $metadata, string $path, ?string $versionId = null): void
    {
        $metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'key',
            $versionId,
            'restored',
            $path,
            new \DateTimeImmutable('+1 day'),
            'STANDARD',
        );
    }

    private function assertRestoreCompletionIsNotReplayed(SqliteMetadataStore $metadata, Request $request): void
    {
        $body = new TrackingObjectReadStream();
        $calls = 0;

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                $this->handler(function () use ($metadata, $body, &$calls): Response {
                    ++$calls;
                    $this->completeRestore($metadata, 'restored-path');

                    return new Response(200, body: $body);
                }),
            );
            self::fail('A HEAD request carrying a body framing header must remain single-attempt.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Object changed during authorization.', $error->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertTrue($body->closeAttempted);
    }

    /** @param array<string, mixed> $overrides */
    private function restoringObject(array $overrides = []): ObjectInfo
    {
        $values = [
            'bucket' => 'bucket',
            'key' => 'key',
            'size' => 4,
            'etag' => '"etag"',
            'contentType' => 'text/plain',
            'storageClass' => 'GLACIER',
            'storageTier' => 'GLACIER',
            'transitionStatus' => 'available',
            'transitionTargetTier' => null,
            'transitionError' => null,
            'restoreStatus' => 'pending',
            'restoredStoragePath' => null,
            'restoreExpiresAt' => null,
            'ownerId' => 'owner',
            'versionId' => null,
            'isDeleteMarker' => false,
            'userMetadata' => [],
            'systemMetadata' => ['storagePath' => 'cold-path'],
            'lastModified' => new \DateTimeImmutable('2030-01-01T00:00:00Z'),
            'restoredStorageTier' => null,
            ...$overrides,
        ];

        return new ObjectInfo(...$values);
    }

    /** @param \Closure(Request): Response $callback */
    private function handler(\Closure $callback): RequestHandler
    {
        return new class ($callback) implements RequestHandler {
            /** @param \Closure(Request): Response $callback */
            public function __construct(private readonly \Closure $callback) {}

            public function handleRequest(Request $request): Response
            {
                return ($this->callback)($request);
            }
        };
    }

    /** @param array<string, string> $systemMetadata */
    private function object(
        string $storagePath,
        string $storageTier = 'STANDARD',
        ?string $restoreStatus = null,
        ?string $restoredStorageTier = null,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
        string $etag = '"etag"',
        array $systemMetadata = [],
        ?\DateTimeImmutable $lastModified = null,
        ?string $versionId = null,
    ): ObjectInfo {
        return new ObjectInfo(
            'bucket',
            'key',
            4,
            $etag,
            storageTier: $storageTier,
            restoreStatus: $restoreStatus,
            restoredStoragePath: $restoredStoragePath,
            restoreExpiresAt: $restoreExpiresAt,
            ownerId: 'owner',
            versionId: $versionId,
            systemMetadata: ['storagePath' => $storagePath, ...$systemMetadata],
            lastModified: $lastModified ?? new \DateTimeImmutable(),
            restoredStorageTier: $restoredStorageTier,
        );
    }

    private function assertReadRoutingDriftRejected(
        ObjectInfo $authorized,
        ObjectInfo $current,
        S3Operation $operation = S3Operation::GetObject,
    ): void {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getCurrentObjectVersion')
            ->with('bucket', 'key')
            ->willReturn($current);
        $metadata->expects(self::once())->method('getBucket')->with('bucket')->willReturn(new BucketInfo(
            'bucket',
            'owner',
            generation: 'generation',
        ));
        $stream = new FailingCloseObjectReadStream();

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request($authorized, $operation),
                new class ($stream) implements RequestHandler {
                    public function __construct(private readonly ReadableStream $stream) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->stream);
                    }
                },
            );
            self::fail('Read-routing drift must reject the already-opened response.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Object changed during authorization.', $error->getMessage());
            self::assertTrue($stream->closeAttempted);
        }
    }
}

/** @implements \IteratorAggregate<int, string> */
final class FailingCloseObjectReadStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    public bool $closeAttempted = false;

    private bool $read = false;

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->read) {
            return null;
        }

        $this->read = true;

        return 'data';
    }

    public function isReadable(): bool
    {
        return ! $this->read;
    }

    public function close(): void
    {
        $this->closeAttempted = true;

        throw new \RuntimeException('simulated close failure');
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}

/** @implements \IteratorAggregate<int, string> */
final class TrackingObjectReadStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    public bool $closeAttempted = false;

    public function read(?Cancellation $cancellation = null): ?string
    {
        return null;
    }

    public function isReadable(): bool
    {
        return ! $this->closeAttempted;
    }

    public function close(): void
    {
        $this->closeAttempted = true;
    }

    public function isClosed(): bool
    {
        return $this->closeAttempted;
    }

    public function onClose(\Closure $onClose): void {}
}
