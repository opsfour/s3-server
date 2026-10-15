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
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class CurrentObjectReadAuthorizationMiddlewareTest extends TestCase
{
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
