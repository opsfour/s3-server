<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class CurrentObjectReadStreamGenerationTest extends TestCase
{
    public function test_read_commit_uses_shared_named_policy_lock_when_available(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->expects(self::once())->method('lockNamedPoliciesForRead')
            ->with(['named'])
            ->willReturn(['named' => '{"Statement":[]}']);
        $metadata->expects(self::never())->method('lockNamedPoliciesForUpdate');
        $request = $this->policyRecheckRequest();

        (new PolicyEnforcementMiddleware($metadata))->assertAllowedAtReadCommit($request);

        self::assertSame(['named' => '{"Statement":[]}'], $request->getAttribute('s3.lockedNamedPolicies'));
    }

    public function test_read_commit_falls_back_to_exclusive_named_policy_lock(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('lockNamedPoliciesForUpdate')
            ->with(['named'])
            ->willReturn(['named' => '{"Statement":[]}']);
        $request = $this->policyRecheckRequest();

        (new PolicyEnforcementMiddleware($metadata))->assertAllowedAtReadCommit($request);

        self::assertSame(['named' => '{"Statement":[]}'], $request->getAttribute('s3.lockedNamedPolicies'));
    }

    public function test_write_commit_keeps_exclusive_named_policy_lock(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->expects(self::never())->method('lockNamedPoliciesForRead');
        $metadata->expects(self::once())->method('lockNamedPoliciesForUpdate')
            ->with(['named'])
            ->willReturn(['named' => '{"Statement":[]}']);
        $request = $this->policyRecheckRequest();

        (new PolicyEnforcementMiddleware($metadata))->assertAllowedAtCommit($request);

        self::assertSame(['named' => '{"Statement":[]}'], $request->getAttribute('s3.lockedNamedPolicies'));
    }

    public function test_bucket_reuse_before_first_body_read_closes_source_without_old_bytes(): void
    {
        $currentBucket = self::bucket('old-generation');
        $source = new ReadableIterableStream(['old']);
        $response = $this->dispatch($currentBucket, $source, 3);
        self::assertSame('3', $response->getHeader('content-length'));
        $currentBucket = self::bucket('new-generation');

        try {
            $response->getBody()->read();
            self::fail('Old bytes must not be read after bucket-name reuse.');
        } catch (OperationAbortedException) {
            self::assertTrue($source->isClosed());
        }
    }

    public function test_bucket_reuse_between_body_chunks_closes_source_before_next_chunk(): void
    {
        $currentBucket = self::bucket('old-generation');
        $firstChunk = str_repeat('a', 65_536);
        $source = new ReadableIterableStream([$firstChunk, 'secret']);
        $response = $this->dispatch($currentBucket, $source, strlen($firstChunk) + 6);
        self::assertSame($firstChunk, $response->getBody()->read());
        $currentBucket = self::bucket('new-generation');

        try {
            $response->getBody()->read();
            self::fail('The next chunk must not expose the old bucket body.');
        } catch (OperationAbortedException) {
            self::assertTrue($source->isClosed());
        }
    }

    public function test_stably_missing_bucket_preserves_handler_no_such_bucket_after_policy_snapshot(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturn(null);
        $metadata->method('getCurrentObjectVersion')->willReturn(null);
        $metadata->expects(self::once())->method('transaction')->willReturnCallback(
            static fn(callable $callback): mixed => $callback(),
        );
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/missing/key'));
        $request->setAttribute('s3.bucket', 'missing');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'owner');

        try {
            (new PolicyEnforcementMiddleware($metadata))->handleRequest($request, new class ($metadata) implements RequestHandler {
                public function __construct(private readonly MetadataStore $metadata) {}

                public function handleRequest(Request $request): Response
                {
                    return (new CurrentObjectReadAuthorizationMiddleware($this->metadata))->handleRequest(
                        $request,
                        new class implements RequestHandler {
                            public function handleRequest(Request $request): Response
                            {
                                throw new NoSuchBucketException();
                            }
                        },
                    );
                }
            });
            self::fail('A stable missing bucket must remain NoSuchBucket.');
        } catch (NoSuchBucketException) {
            self::assertTrue($request->hasAttribute(AuthorizedObjectSnapshot::ATTRIBUTE));
            self::assertNull($request->getAttribute('s3.cachedBucketGeneration'));
        }
    }

    public function test_final_policy_recheck_and_object_identity_share_owner_locked_transaction(): void
    {
        $bucket = self::bucket('generation');
        $authorized = new ObjectInfo('bucket', 'key', 3, '"etag"', ownerId: 'owner', systemMetadata: ['storagePath' => 'old']);
        $current = $authorized;
        $source = new ReadableIterableStream(['old']);
        $transactionEvents = [];
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            SharedAuthorizationLockStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getBucket')->willReturnCallback(static function () use ($bucket, &$transactionEvents): BucketInfo {
            $transactionEvents[] = 'bucket-read';

            return $bucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturnCallback(static function () use (&$current): ObjectInfo {
            return $current;
        });
        $metadata->expects(self::once())->method('transaction')->willReturnCallback(static function (callable $callback) use (&$transactionEvents): mixed {
            $transactionEvents = [];

            return $callback();
        });
        $metadata->expects(self::once())->method('prepareOwnerReadLock')->with('owner');
        $metadata->expects(self::once())->method('lockOwnerForRead')->with('owner')->willReturnCallback(static function () use (&$transactionEvents): void {
            $transactionEvents[] = 'owner-lock';
        });
        $metadata->expects(self::never())->method('lockOwnerForUpdate');
        $metadata->method('getBucketPolicy')->willReturnCallback(static function () use (&$current): ?string {
            $current = new ObjectInfo('bucket', 'key', 3, '"etag"', ownerId: 'owner', systemMetadata: ['storagePath' => 'replacement']);

            return null;
        });
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/key'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('s3.cachedBucketGeneration', 'generation');
        $request->setAttribute('s3.cachedBucketOwner', 'owner');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.policyResult', 'OwnerBypass');
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $authorized);

        try {
            (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
                $request,
                new class ($source) implements RequestHandler {
                    public function __construct(private readonly ReadableIterableStream $source) {}

                    public function handleRequest(Request $request): Response
                    {
                        return new Response(200, body: $this->source);
                    }
                },
            );
            self::fail('Object replacement during policy recheck must not release the response.');
        } catch (AccessDeniedException) {
            self::assertTrue($source->isClosed());
            self::assertSame('owner-lock', $transactionEvents[0] ?? null);
        }
    }

    private function dispatch(BucketInfo &$currentBucket, ReadableIterableStream $source, int $size): Response
    {
        $object = new ObjectInfo(
            'bucket',
            'key',
            $size,
            '"etag"',
            ownerId: 'owner',
            systemMetadata: ['storagePath' => 'objects/old-generation'],
        );
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static function () use (&$currentBucket): BucketInfo {
            return $currentBucket;
        });
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/key'));
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('s3.cachedBucketGeneration', 'old-generation');
        $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $object);

        return (new CurrentObjectReadAuthorizationMiddleware($metadata))->handleRequest(
            $request,
            new class ($source, $size) implements RequestHandler {
                public function __construct(
                    private readonly ReadableIterableStream $source,
                    private readonly int $size,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    return new Response(200, ['content-length' => (string) $this->size], $this->source);
                }
            },
        );
    }

    private static function bucket(string $generation): BucketInfo
    {
        return new BucketInfo('bucket', 'owner', generation: $generation);
    }

    private function policyRecheckRequest(): Request
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/'));
        $request->setAttribute('s3.policyResult', 'Allow');
        $request->setAttribute('credential', new Credential('key', 'secret', 'owner', policyNames: ['named']));

        return $request;
    }
}
