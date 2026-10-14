<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\CancelledException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\BucketGenerationGuard;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerReadLock;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\GenerationGuardedReadableStream;

/** Keep current-object reads bound to the object authorized by policy and ACL checks. */
final readonly class CurrentObjectReadAuthorizationMiddleware implements Middleware
{
    public function __construct(private MetadataStore $metadata) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        if (!in_array($operation, [
            S3Operation::GetObject, S3Operation::HeadObject, S3Operation::GetObjectAttributes,
            S3Operation::GetObjectAcl, S3Operation::GetObjectTagging,
            S3Operation::GetObjectRetention, S3Operation::GetObjectLegalHold,
            S3Operation::SelectObjectContent,
        ], true) || ! $request->hasAttribute(AuthorizedObjectSnapshot::ATTRIBUTE)) {
            return $requestHandler->handleRequest($request);
        }

        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $cachedBucketGeneration = $request->hasAttribute('s3.cachedBucketGeneration')
            ? $request->getAttribute('s3.cachedBucketGeneration')
            : null;
        $authorizedBucketGeneration = is_string($cachedBucketGeneration) ? $cachedBucketGeneration : null;
        $versionId = $operation->selectsObjectVersion()
            ? (QueryStringParser::parse($request->getUri()->getQuery())['versionId'] ?? null)
            : null;
        if (in_array($operation, [
            S3Operation::GetObjectAcl, S3Operation::GetObjectTagging,
            S3Operation::GetObjectRetention, S3Operation::GetObjectLegalHold,
        ], true)) {
            $bucketInfo = $this->metadata->getBucket($bucket);
            if ($bucketInfo === null) {
                return $this->dispatchBoundRead($request, $requestHandler, $operation, $bucket, $key, $versionId, $authorizedBucketGeneration);
            }

            return $this->metadata->transaction(function () use ($request, $requestHandler, $operation, $bucket, $key, $versionId, $bucketInfo, $authorizedBucketGeneration): Response {
                OwnerWriteLock::acquire($this->metadata, (string) $request->getAttribute('ownerId'), $bucketInfo->ownerId);
                BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);

                return $this->dispatchBoundRead($request, $requestHandler, $operation, $bucket, $key, $versionId, $authorizedBucketGeneration, true);
            });
        }

        return $this->dispatchBoundRead($request, $requestHandler, $operation, $bucket, $key, $versionId, $authorizedBucketGeneration);
    }

    private function dispatchBoundRead(
        Request $request,
        RequestHandler $requestHandler,
        S3Operation $operation,
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $authorizedBucketGeneration,
        bool $withinWriteTransaction = false,
    ): Response {
        $snapshot = $request->getAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
        $authorized = $snapshot instanceof ObjectInfo ? $snapshot : null;
        $cachedBucketOwner = $request->hasAttribute('s3.cachedBucketOwner')
            ? $request->getAttribute('s3.cachedBucketOwner')
            : null;
        $authorizedBucketOwner = is_string($cachedBucketOwner) ? $cachedBucketOwner : '';
        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            // A handler can inspect the current object while constructing an
            // error (for example, a new delete marker's version headers).
            // Suppress that error if it no longer describes the authorized
            // object; otherwise preserve the original exception unchanged.
            $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedBucketGeneration);
            $this->assertObjectUnchanged($authorized, $operation, $bucket, $key, $versionId);
            throw $error;
        }
        try {
            $validate = function () use ($request, $bucket, $authorizedBucketGeneration, $authorized, $operation, $key, $versionId, $withinWriteTransaction): ?BucketInfo {
                $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedBucketGeneration);
                $this->assertObjectUnchanged($authorized, $operation, $bucket, $key, $versionId);
                $policy = new PolicyEnforcementMiddleware($this->metadata);
                if ($withinWriteTransaction) {
                    $policy->assertAllowedAtCommit($request);
                } else {
                    $policy->assertAllowedAtReadCommit($request);
                }
                $observedBucket = $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedBucketGeneration);
                $this->assertObjectUnchanged($authorized, $operation, $bucket, $key, $versionId);

                return $observedBucket;
            };
            if ($request->hasAttribute('s3.policyResult') && ! $withinWriteTransaction) {
                $readOwners = [
                    (string) $request->getAttribute('ownerId'),
                    $authorizedBucketOwner,
                    $authorized === null ? '' : $authorized->ownerId,
                ];
                OwnerReadLock::prepare($this->metadata, ...$readOwners);
                $observedBucket = $this->metadata->transaction(function () use ($validate, $readOwners): ?BucketInfo {
                    OwnerReadLock::acquire(
                        $this->metadata,
                        ...$readOwners,
                    );

                    return $validate();
                });
            } else {
                $observedBucket = $validate();
            }
            if ($operation === S3Operation::GetObject) {
                if ($observedBucket === null) {
                    throw new OperationAbortedException('Bucket disappeared after authorization.');
                }
                $contentLength = $response->getHeader('content-length');
                $response->setBody(new GenerationGuardedReadableStream(
                    $response->getBody(),
                    function () use ($observedBucket, $request): void {
                        BucketGenerationGuard::assertCurrent($this->metadata, $observedBucket, $request);
                    },
                ));
                if ($contentLength !== null) {
                    $response->setHeader('content-length', $contentLength);
                }
            }
        } catch (\Throwable $error) {
            try {
                $response->getBody()->close();
            } catch (\Throwable) {
                // Preserve the authorization or cancellation failure.
            }

            throw $error;
        }

        return $response;
    }

    private function assertBucketGenerationUnchanged(Request $request, string $bucket, ?string $authorizedGeneration): ?BucketInfo
    {
        $cachedGeneration = $request->hasAttribute('s3.cachedBucketGeneration')
            ? $request->getAttribute('s3.cachedBucketGeneration')
            : null;
        $current = $this->metadata->getBucket($bucket);
        if ($authorizedGeneration === null) {
            if ($cachedGeneration !== null || $current !== null) {
                throw new OperationAbortedException('Bucket ownership changed after authorization.');
            }

            return null;
        }
        if ($cachedGeneration !== $authorizedGeneration
            || $current === null
            || $current->generation !== $authorizedGeneration) {
            throw new OperationAbortedException('Bucket ownership changed after authorization.');
        }

        return $current;
    }

    private function assertObjectUnchanged(?ObjectInfo $authorized, S3Operation $operation, string $bucket, string $key, ?string $versionId): void
    {
        $current = AuthorizedObjectSnapshot::lookupForRead($this->metadata, $operation, $bucket, $key, $versionId);
        if (AuthorizedObjectSnapshot::identity($authorized) !== AuthorizedObjectSnapshot::identity($current)) {
            throw new AccessDeniedException('Object changed during authorization.');
        }
    }
}
