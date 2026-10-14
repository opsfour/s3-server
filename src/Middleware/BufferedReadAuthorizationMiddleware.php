<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\CancelledException;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerReadLock;
use OpsFour\S3Server\Routing\S3Operation;

/** Recheck authorization before returning fully buffered service and bucket reads. */
final readonly class BufferedReadAuthorizationMiddleware implements Middleware
{
    private const array OPERATIONS = [
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
    ];

    public function __construct(private MetadataStore $metadata) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        if (! $operation instanceof S3Operation || ! in_array($operation, self::OPERATIONS, true)) {
            return $requestHandler->handleRequest($request);
        }

        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        $bucket = is_string($bucket) && $bucket !== '' ? $bucket : null;
        $cachedGeneration = $request->hasAttribute('s3.cachedBucketGeneration')
            ? $request->getAttribute('s3.cachedBucketGeneration')
            : null;
        $authorizedGeneration = is_string($cachedGeneration) ? $cachedGeneration : null;
        $cachedOwner = $request->hasAttribute('s3.cachedBucketOwner')
            ? $request->getAttribute('s3.cachedBucketOwner')
            : null;
        $authorizedOwner = is_string($cachedOwner) ? $cachedOwner : '';

        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->validate($request, $operation, $bucket, $authorizedGeneration, $authorizedOwner);
            throw $error;
        }

        try {
            $this->validate($request, $operation, $bucket, $authorizedGeneration, $authorizedOwner);
        } catch (\Throwable $error) {
            try {
                $response->getBody()->close();
            } catch (\Throwable) {
                // Preserve the authorization, generation, or cancellation failure.
            }
            throw $error;
        }

        return $response;
    }

    private function validate(
        Request $request,
        S3Operation $operation,
        ?string $bucket,
        ?string $authorizedGeneration,
        string $authorizedOwner,
    ): void {
        $requester = $request->hasAttribute('ownerId') ? (string) $request->getAttribute('ownerId') : '';
        $owners = $operation === S3Operation::ListBuckets
            ? [$requester]
            : [$requester, $authorizedOwner];
        OwnerReadLock::prepare($this->metadata, ...$owners);

        $this->metadata->transaction(function () use ($request, $operation, $bucket, $authorizedGeneration, $authorizedOwner, $owners): void {
            OwnerReadLock::acquire($this->metadata, ...$owners);
            $policy = new PolicyEnforcementMiddleware($this->metadata);
            if ($operation === S3Operation::ListBuckets) {
                $policy->assertListBucketsAllowedAtReadCommit($request);

                return;
            }

            if ($bucket === null) {
                throw new OperationAbortedException('Bucket context changed after authorization.');
            }
            $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedGeneration);
            $policy->assertAllowedAtReadCommit($request);
            if ($operation === S3Operation::ListParts
                && (string) $request->getAttribute('ownerId') !== $authorizedOwner) {
                $uploadOwner = $request->hasAttribute('s3.listPartsUploadOwner')
                    ? $request->getAttribute('s3.listPartsUploadOwner')
                    : null;
                if (! is_string($uploadOwner)
                    || ($uploadOwner !== (string) $request->getAttribute('ownerId')
                        && $request->getAttribute('s3.policyResult') !== 'Allow')) {
                    throw new NoSuchUploadException();
                }
            }
            $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedGeneration);
        });
    }

    private function assertBucketGenerationUnchanged(Request $request, string $bucket, ?string $authorizedGeneration): void
    {
        $cachedGeneration = $request->hasAttribute('s3.cachedBucketGeneration')
            ? $request->getAttribute('s3.cachedBucketGeneration')
            : null;
        $current = $this->metadata->getBucket($bucket);
        if ($authorizedGeneration === null) {
            if ($cachedGeneration !== null || $current !== null) {
                throw new OperationAbortedException('Bucket ownership changed after authorization.');
            }

            return;
        }
        if ($cachedGeneration !== $authorizedGeneration
            || $current === null
            || $current->generation !== $authorizedGeneration) {
            throw new OperationAbortedException('Bucket ownership changed after authorization.');
        }
    }
}
