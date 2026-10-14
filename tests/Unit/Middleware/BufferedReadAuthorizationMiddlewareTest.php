<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Middleware\BufferedReadAuthorizationMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BufferedReadAuthorizationMiddlewareTest extends TestCase
{
    #[DataProvider('protectedOperations')]
    public function test_every_buffered_read_rechecks_under_a_shared_owner_lock(S3Operation $operation): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->expects(self::once())->method('prepareOwnerReadLock')->with('owner');
        $metadata->expects(self::once())->method('lockOwnerForRead')->with('owner');
        $metadata->expects(self::never())->method('lockOwnerForUpdate');
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->expects(self::once())->method('getAccountPolicy')->with('owner')->willReturn(null);
        if ($operation === S3Operation::ListBuckets) {
            $metadata->expects(self::never())->method('getBucket');
            $metadata->expects(self::never())->method('getBucketPolicy');
        } else {
            $metadata->expects(self::exactly(3))->method('getBucket')->with('bucket')->willReturn(self::bucket());
            $metadata->expects(self::once())->method('getBucketPolicy')->with('bucket')->willReturn(null);
        }

        $response = (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $this->request($operation),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'buffered');
                }
            },
        );

        self::assertSame('buffered', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_list_buckets_rechecks_current_identity_policy_without_policy_result_attribute(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getAccountPolicy')->willReturn(
            self::policy('Deny', 's3:ListAllMyBuckets', '*'),
        );
        $request = $this->request(S3Operation::ListBuckets);
        self::assertFalse($request->hasAttribute('s3.policyResult'));

        $this->expectException(AccessDeniedException::class);
        (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'must not escape');
                }
            },
        );
    }

    public function test_account_policy_revocation_closes_response_without_masking_access_denied(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->method('getAccountPolicy')->willReturn(
            self::policy('Deny', 's3:GetBucketCORS', 'arn:aws:s3:::bucket'),
        );
        $stream = new FailingCloseBufferedReadStream();

        try {
            (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request(S3Operation::GetBucketCors),
                new class ($stream) implements RequestHandler {
                    public function __construct(private readonly ReadableStream $stream) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->stream);
                    }
                },
            );
            self::fail('A revoked identity policy must suppress the buffered response.');
        } catch (AccessDeniedException $error) {
            self::assertTrue($stream->closeAttempted);
            self::assertSame('Access Denied', $error->getMessage());
        }
    }

    public function test_public_access_block_revocation_suppresses_a_bucket_acl_read_grant(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->method('getAccountPolicy')->willReturn(
            self::policy('Allow', 's3:ListBucket', 'arn:aws:s3:::bucket'),
        );
        $metadata->method('getBucketPolicy')->willReturn(null);
        $metadata->method('getBucketOwnershipControls')->willReturn(null);
        $metadata->method('getAcl')->willReturn([[
            'granteeType' => 'Group',
            'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers',
            'permission' => 'READ',
        ]]);
        $metadata->method('getPublicAccessBlock')->willReturn([
            'blockPublicAcls' => false,
            'ignorePublicAcls' => true,
            'blockPublicPolicy' => false,
            'restrictPublicBuckets' => false,
        ]);
        $request = $this->request(S3Operation::ListObjects, 'reader', 'owner');
        $request->setAttribute('s3.policyResult', 'Neutral');

        $this->expectException(AccessDeniedException::class);
        (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'old listing');
                }
            },
        );
    }

    public function test_current_named_policy_is_locked_and_rechecked(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->expects(self::once())->method('lockNamedPoliciesForRead')->with(['reader-policy'])->willReturn([
            'reader-policy' => self::policy('Deny', 's3:GetBucketTagging', 'arn:aws:s3:::bucket'),
        ]);
        $metadata->expects(self::never())->method('lockNamedPoliciesForUpdate');
        $request = $this->request(S3Operation::GetBucketTagging);
        $request->setAttribute('credential', new Credential('key', 'secret', 'owner', policyNames: ['reader-policy']));

        $this->expectException(AccessDeniedException::class);
        (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'old tags');
                }
            },
        );
    }

    public function test_generation_drift_closes_response_before_return(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner', generation: 'replacement'));
        $metadata->expects(self::never())->method('getAccountPolicy');
        $stream = new FailingCloseBufferedReadStream();

        try {
            (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request(S3Operation::GetBucketLogging),
                new class ($stream) implements RequestHandler {
                    public function __construct(private readonly ReadableStream $stream) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->stream);
                    }
                },
            );
            self::fail('A response from another bucket generation must not escape.');
        } catch (OperationAbortedException $error) {
            self::assertTrue($stream->closeAttempted);
            self::assertSame('Bucket ownership changed after authorization.', $error->getMessage());
        }
    }

    public function test_list_parts_rechecks_its_context_specific_resource_consent(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->method('getAccountPolicy')->willReturn(
            self::policy('Allow', 's3:ListMultipartUploadParts', 'arn:aws:s3:::bucket/key'),
        );
        $metadata->method('getBucketPolicy')->willReturn(null);
        $request = $this->request(S3Operation::ListParts, 'reader', 'owner');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.policyResult', 'Allow');

        $this->expectException(NoSuchUploadException::class);
        (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    $request->setAttribute('s3.listPartsUploadOwner', 'uploader');

                    return new Response(200, body: 'parts');
                }
            },
        );
    }

    public function test_custom_metadata_store_falls_back_to_exclusive_owner_locks(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->expects(self::once())->method('lockOwnerForUpdate')->with('owner');
        $metadata->expects(self::once())->method('transaction')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());

        $response = (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $this->request(S3Operation::HeadBucket),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            },
        );

        self::assertSame(200, $response->getStatus());
    }

    public function test_handler_cancellation_is_propagated_without_revalidation(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('transaction');

        try {
            (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request(S3Operation::GetBucketLocation),
                new class ($cancellation) implements RequestHandler {
                    public function __construct(private readonly CancelledException $cancellation) {}

                    public function handleRequest(Request $request): Response
                    {
                        throw $this->cancellation;
                    }
                },
            );
            self::fail('Cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_revalidation_cancellation_is_propagated_without_being_replaced_by_close_failure(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('transaction')->willThrowException($cancellation);
        $stream = new FailingCloseBufferedReadStream();

        try {
            (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
                $this->request(S3Operation::GetBucketLocation),
                new class ($stream) implements RequestHandler {
                    public function __construct(private readonly ReadableStream $stream) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->stream);
                    }
                },
            );
            self::fail('Read-commit cancellation must propagate unchanged.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertTrue($stream->closeAttempted);
        }
    }

    public function test_revoked_policy_replaces_handler_error_that_would_disclose_read_state(): void
    {
        $metadata = $this->sharedMetadata();
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->method('getAccountPolicy')->willReturn(
            self::policy('Deny', 's3:GetBucketCORS', 'arn:aws:s3:::bucket'),
        );

        $this->expectException(AccessDeniedException::class);
        (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $this->request(S3Operation::GetBucketCors),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new NoSuchBucketException('stale handler result');
                }
            },
        );
    }

    public function test_lazy_object_reads_remain_owned_by_current_object_middleware(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('transaction');
        $response = (new BufferedReadAuthorizationMiddleware($metadata))->handleRequest(
            $this->request(S3Operation::GetObject),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, body: 'object');
                }
            },
        );

        self::assertSame('object', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_parallel_buffered_readers_overlap_while_owner_writer_waits(): void
    {
        $gate = new BufferedReadTestLockGate();
        $firstHeld = new DeferredFuture();
        $secondHeld = new DeferredFuture();
        $releaseFirst = new DeferredFuture();
        $releaseSecond = new DeferredFuture();
        $policyRead = 0;
        $metadata = $this->sharedMetadata(false);
        $metadata->method('prepareOwnerReadLock');
        $metadata->expects(self::exactly(2))->method('lockOwnerForRead')
            ->willReturnCallback($gate->acquireRead(...));
        $metadata->expects(self::once())->method('lockOwnerForUpdate')
            ->willReturnCallback($gate->acquireWrite(...));
        $metadata->expects(self::exactly(3))->method('transaction')
            ->willReturnCallback($gate->transaction(...));
        $metadata->method('getBucket')->willReturn(self::bucket());
        $metadata->method('getBucketPolicy')->willReturn(null);
        $metadata->expects(self::exactly(2))->method('getAccountPolicy')
            ->willReturnCallback(static function () use (&$policyRead, $firstHeld, $secondHeld, $releaseFirst, $releaseSecond): ?string {
                ++$policyRead;
                if ($policyRead === 1) {
                    $firstHeld->complete();
                    $releaseFirst->getFuture()->await();
                } else {
                    $secondHeld->complete();
                    $releaseSecond->getFuture()->await();
                }

                return null;
            });
        $middleware = new BufferedReadAuthorizationMiddleware($metadata);
        $terminal = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200, body: 'buffered');
            }
        };
        $first = \Amp\async(fn(): Response => $middleware->handleRequest($this->request(S3Operation::GetBucketCors), $terminal));
        $second = null;
        $writer = null;

        try {
            $firstHeld->getFuture()->await(new TimeoutCancellation(5));
            $second = \Amp\async(fn(): Response => $middleware->handleRequest($this->request(S3Operation::GetBucketCors), $terminal));
            $secondHeld->getFuture()->await(new TimeoutCancellation(5));
            self::assertSame(2, $gate->activeReaders);
            $writer = \Amp\async(static function () use ($metadata): void {
                $metadata->transaction(static function () use ($metadata): void {
                    $metadata->lockOwnerForUpdate('owner');
                });
            });
            self::assertFalse($writer->isComplete());

            $releaseFirst->complete();
            self::assertSame(200, $first->await(new TimeoutCancellation(5))->getStatus());
            self::assertFalse($writer->isComplete());

            $releaseSecond->complete();
            self::assertSame(200, $second->await(new TimeoutCancellation(5))->getStatus());
            $writer->await(new TimeoutCancellation(5));
        } finally {
            if (! $releaseFirst->getFuture()->isComplete()) {
                $releaseFirst->complete();
            }
            if (! $releaseSecond->getFuture()->isComplete()) {
                $releaseSecond->complete();
            }
            $first->await(new TimeoutCancellation(5));
            $second?->await(new TimeoutCancellation(5));
            $writer?->await(new TimeoutCancellation(5));
        }
    }

    /** @return iterable<string, array{S3Operation}> */
    public static function protectedOperations(): iterable
    {
        foreach ([
            S3Operation::ListBuckets,
            S3Operation::HeadBucket,
            S3Operation::ListObjects,
            S3Operation::ListObjectsV2,
            S3Operation::ListObjectVersions,
            S3Operation::GetBucketLocation,
            S3Operation::GetBucketVersioning,
            S3Operation::GetBucketAcl,
            S3Operation::GetBucketPolicy,
            S3Operation::GetBucketCors,
            S3Operation::GetBucketTagging,
            S3Operation::GetBucketLifecycle,
            S3Operation::GetBucketNotification,
            S3Operation::GetBucketEncryption,
            S3Operation::GetObjectLockConfig,
            S3Operation::ListMultipartUploads,
            S3Operation::GetBucketWebsite,
            S3Operation::GetPublicAccessBlock,
            S3Operation::GetBucketPolicyStatus,
            S3Operation::GetBucketLogging,
            S3Operation::GetBucketOwnershipControls,
            S3Operation::ListParts,
        ] as $operation) {
            yield $operation->value => [$operation];
        }
    }

    private function sharedMetadata(bool $passthroughTransaction = true): MockObject&MetadataStore
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        if ($passthroughTransaction) {
            $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        }

        return $metadata;
    }

    private function request(S3Operation $operation, string $requester = 'owner', string $bucketOwner = 'owner'): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'GET',
            Http::new($operation === S3Operation::ListBuckets ? 'http://127.0.0.1/' : 'http://127.0.0.1/bucket'),
        );
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('ownerId', $requester);
        if ($operation !== S3Operation::ListBuckets) {
            $request->setAttribute('s3.bucket', 'bucket');
            $request->setAttribute('s3.cachedBucketGeneration', 'generation');
            $request->setAttribute('s3.cachedBucketOwner', $bucketOwner);
            $request->setAttribute('s3.policyResult', $requester === $bucketOwner ? 'OwnerBypass' : 'Neutral');
        }

        return $request;
    }

    private static function bucket(): BucketInfo
    {
        return new BucketInfo('bucket', 'owner', generation: 'generation');
    }

    private static function policy(string $effect, string $action, string $resource): string
    {
        return json_encode([
            'Statement' => [[
                'Effect' => $effect,
                'Action' => $action,
                'Resource' => $resource,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}

/** @implements \IteratorAggregate<int, string> */
final class FailingCloseBufferedReadStream implements \IteratorAggregate, ReadableStream
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

        return 'must not escape';
    }

    public function isReadable(): bool
    {
        return true;
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

final class BufferedReadTestLockGate
{
    public int $activeReaders = 0;

    /** @var array<int, 'read'|'write'> */
    private array $held = [];

    /** @var DeferredFuture<void>|null */
    private ?DeferredFuture $readersReleased = null;

    public function transaction(callable $callback): mixed
    {
        try {
            return $callback();
        } finally {
            $fiber = \Fiber::getCurrent();
            $id = $fiber === null ? 0 : spl_object_id($fiber);
            $mode = $this->held[$id] ?? null;
            unset($this->held[$id]);
            if ($mode === 'read') {
                --$this->activeReaders;
                if ($this->activeReaders === 0 && $this->readersReleased !== null) {
                    $this->readersReleased->complete();
                    $this->readersReleased = null;
                }
            }
        }
    }

    public function acquireRead(string $ownerId): void
    {
        ++$this->activeReaders;
        $fiber = \Fiber::getCurrent();
        $this->held[$fiber === null ? 0 : spl_object_id($fiber)] = 'read';
    }

    public function acquireWrite(string $ownerId): void
    {
        if ($this->activeReaders > 0) {
            $this->readersReleased ??= new DeferredFuture();
            $this->readersReleased->getFuture()->await();
        }
        $fiber = \Fiber::getCurrent();
        $this->held[$fiber === null ? 0 : spl_object_id($fiber)] = 'write';
    }
}
