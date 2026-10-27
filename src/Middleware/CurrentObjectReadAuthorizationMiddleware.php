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
use OpsFour\S3Server\ObjectLock\ObjectLockResponseHeaders;
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
        $response = null;
        $retryResponse = null;
        $handlerError = null;
        try {
            $response = $requestHandler->handleRequest($request);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $handlerError = $error;
        }
        $retry = $response !== null
            && $this->isReplaySafeRead($request, $operation, $withinWriteTransaction)
            && $request->hasAttribute('s3.policyResult')
            ? function () use ($request, $requestHandler, $operation, $bucket, $key, $versionId, $authorizedBucketGeneration, $authorizedBucketOwner, $authorized, &$response, &$retryResponse): array {
                \assert($response instanceof Response);
                self::closeResponseBeforeReplay($response);
                $response = null;

                return $this->retryReadWithinAuthorizationLock(
                    $request,
                    $requestHandler,
                    $operation,
                    $bucket,
                    $key,
                    $versionId,
                    $authorizedBucketGeneration,
                    $authorizedBucketOwner,
                    $authorized,
                    $retryResponse,
                );
            }
        : null;
        try {
            $validation = $this->validate(
                $request,
                $operation,
                $bucket,
                $key,
                $versionId,
                $authorizedBucketGeneration,
                $authorizedBucketOwner,
                $authorized,
                $withinWriteTransaction,
                $retry,
            );
            if ($validation['response'] !== null) {
                $response = $validation['response'];
                $handlerError = null;
            }
        } catch (\Throwable $error) {
            if ($response !== null) {
                self::closeResponse($response);
            }
            if ($retryResponse !== null && $retryResponse !== $response) {
                self::closeResponse($retryResponse);
            }

            throw $error;
        }
        $retryResponse = null;
        if ($handlerError !== null) {
            throw $handlerError;
        }
        \assert($response instanceof Response);

        foreach ($validation['objectLockHeaders'] as $header => $value) {
            $response->setHeader($header, $value);
        }

        if ($operation === S3Operation::GetObject) {
            if ($validation['bucket'] === null) {
                self::closeResponse($response);
                throw new OperationAbortedException('Bucket disappeared after authorization.');
            }
            $contentLength = $response->getHeader('content-length');
            $observedBucket = $validation['bucket'];
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

        return $response;
    }

    /**
     * @param (\Closure(): array{bucket: ?BucketInfo, response: Response, objectLockHeaders: array<non-empty-string, string>})|null $retry
     * @return array{bucket: ?BucketInfo, response: ?Response, objectLockHeaders: array<non-empty-string, string>}
     */
    private function validate(
        Request $request,
        S3Operation $operation,
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $authorizedBucketGeneration,
        string $authorizedBucketOwner,
        ?ObjectInfo $authorized,
        bool $withinWriteTransaction,
        ?\Closure $retry,
    ): array {
        $validate = function () use ($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner, $authorized, $operation, $key, $versionId, $withinWriteTransaction, $retry): array {
            $this->assertBucketUnchanged($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner);
            $current = $this->lookupObject($operation, $bucket, $key, $versionId);
            if (! self::sameObject($authorized, $current)) {
                return $this->retryOrDeny($retry, $authorized, $current);
            }
            $policy = new PolicyEnforcementMiddleware($this->metadata);
            if ($withinWriteTransaction) {
                $policy->assertAllowedAtCommit($request);
            } else {
                $policy->assertAllowedAtReadCommit($request);
            }
            $observedBucket = $this->assertBucketUnchanged($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner);
            $current = $this->lookupObject($operation, $bucket, $key, $versionId);
            if (! self::sameObject($authorized, $current)) {
                return $this->retryOrDeny($retry, $authorized, $current);
            }

            $objectLockHeaders = $current !== null
                && in_array($operation, [S3Operation::GetObject, S3Operation::HeadObject], true)
                ? (new ObjectLockResponseHeaders($this->metadata))->forObject($request, $current)
                : [];

            return [
                'bucket' => $observedBucket,
                'response' => null,
                'objectLockHeaders' => $objectLockHeaders,
            ];
        };
        if (! $request->hasAttribute('s3.policyResult') || $withinWriteTransaction) {
            return $validate();
        }

        $readOwners = [
            (string) $request->getAttribute('ownerId'),
            $authorizedBucketOwner,
            $authorized === null ? '' : $authorized->ownerId,
        ];
        OwnerReadLock::prepare($this->metadata, ...$readOwners);

        return $this->metadata->transaction(function () use ($validate, $readOwners): array {
            OwnerReadLock::acquire($this->metadata, ...$readOwners);

            return $validate();
        });
    }

    /** @return array{bucket: ?BucketInfo, response: Response, objectLockHeaders: array<non-empty-string, string>} */
    private function retryReadWithinAuthorizationLock(
        Request $request,
        RequestHandler $requestHandler,
        S3Operation $operation,
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $authorizedBucketGeneration,
        string $authorizedBucketOwner,
        ?ObjectInfo $originallyAuthorized,
        ?Response &$retryResponse,
    ): array {
        $policy = new PolicyEnforcementMiddleware($this->metadata);
        $policy->assertAllowedAtReadCommit($request);
        $snapshot = $request->getAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
        $refreshed = $snapshot instanceof ObjectInfo ? $snapshot : null;
        $this->assertBucketUnchanged($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner);
        if (! self::sameObject($originallyAuthorized, $refreshed)
            && ! self::isCompletedRestoreTransition($originallyAuthorized, $refreshed)) {
            throw new AccessDeniedException('Object changed during authorization.');
        }
        try {
            $retryResponse = $requestHandler->handleRequest($request);
            $observedBucket = $this->assertBucketUnchanged($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner);
            $this->assertObjectUnchanged($refreshed, $operation, $bucket, $key, $versionId);
            $policy->assertAllowedAtReadCommit($request);
            $observedBucket = $this->assertBucketUnchanged($request, $bucket, $authorizedBucketGeneration, $authorizedBucketOwner);
            $this->assertObjectUnchanged($refreshed, $operation, $bucket, $key, $versionId);

            $objectLockHeaders = $refreshed !== null
                && in_array($operation, [S3Operation::GetObject, S3Operation::HeadObject], true)
                ? (new ObjectLockResponseHeaders($this->metadata))->forObject($request, $refreshed)
                : [];

            return [
                'bucket' => $observedBucket,
                'response' => $retryResponse,
                'objectLockHeaders' => $objectLockHeaders,
            ];
        } catch (\Throwable $error) {
            if ($retryResponse !== null) {
                self::closeResponse($retryResponse);
                $retryResponse = null;
            }

            throw $error;
        }
    }

    /**
     * @param (\Closure(): array{bucket: ?BucketInfo, response: Response, objectLockHeaders: array<non-empty-string, string>})|null $retry
     * @return array{bucket: ?BucketInfo, response: Response, objectLockHeaders: array<non-empty-string, string>}
     */
    private function retryOrDeny(?\Closure $retry, ?ObjectInfo $authorized, ?ObjectInfo $current): array
    {
        if ($retry === null || ! self::isCompletedRestoreTransition($authorized, $current)) {
            throw new AccessDeniedException('Object changed during authorization.');
        }

        return $retry();
    }

    private function isReplaySafeRead(Request $request, S3Operation $operation, bool $withinWriteTransaction): bool
    {
        if ($withinWriteTransaction
            || $operation !== S3Operation::HeadObject
            || $request->getMethod() !== 'HEAD'
            || $request->hasHeader('transfer-encoding')) {
            return false;
        }

        $contentLength = $request->getHeader('content-length');

        return $contentLength === null || trim($contentLength) === '0';
    }

    private static function closeResponse(Response $response): void
    {
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
            // Preserve the authorization, handler, or cancellation failure.
        }
    }

    private static function closeResponseBeforeReplay(Response $response): void
    {
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
            throw new OperationAbortedException('Unable to dispose the stale response before retrying the request.');
        }
    }

    private function assertBucketUnchanged(
        Request $request,
        string $bucket,
        ?string $authorizedGeneration,
        string $authorizedOwner,
    ): ?BucketInfo {
        $current = $this->assertBucketGenerationUnchanged($request, $bucket, $authorizedGeneration);
        if ($authorizedOwner !== '' && $current !== null && $current->ownerId !== $authorizedOwner) {
            throw new OperationAbortedException('Bucket ownership changed after authorization.');
        }

        return $current;
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
        if (! self::sameObject($authorized, $this->lookupObject($operation, $bucket, $key, $versionId))) {
            throw new AccessDeniedException('Object changed during authorization.');
        }
    }

    /** @phpstan-impure */
    private function lookupObject(S3Operation $operation, string $bucket, string $key, ?string $versionId): ?ObjectInfo
    {
        return AuthorizedObjectSnapshot::lookupForRead($this->metadata, $operation, $bucket, $key, $versionId);
    }

    private static function sameObject(?ObjectInfo $left, ?ObjectInfo $right): bool
    {
        return AuthorizedObjectSnapshot::identity($left) === AuthorizedObjectSnapshot::identity($right);
    }

    private static function isCompletedRestoreTransition(?ObjectInfo $authorized, ?ObjectInfo $current): bool
    {
        if ($authorized === null
            || $current === null
            || $authorized->restoreStatus !== 'pending'
            || $current->restoreStatus !== 'restored'
            || $current->restoredStorageTier === null
            || $current->restoredStoragePath === null
            || $current->restoreExpiresAt === null) {
            return false;
        }

        return self::restoreStableIdentity($authorized) === self::restoreStableIdentity($current);
    }

    /**
     * Identify every authorization and routing field that a restore completion
     * is not allowed to change. Version-list bookkeeping is intentionally
     * ignored for the same reason as AuthorizedObjectSnapshot::identity().
     *
     * @return array<string, bool|int|string|null|array<string, string>>
     */
    private static function restoreStableIdentity(ObjectInfo $object): array
    {
        $userMetadata = $object->userMetadata;
        $systemMetadata = $object->systemMetadata;
        unset($systemMetadata['isLatest'], $systemMetadata['noncurrentAt']);
        ksort($userMetadata, SORT_STRING);
        ksort($systemMetadata, SORT_STRING);

        return [
            'bucket' => $object->bucket,
            'key' => $object->key,
            'size' => $object->size,
            'etag' => $object->etag,
            'contentType' => $object->contentType,
            'storageClass' => $object->storageClass,
            'storageTier' => $object->storageTier,
            'transitionStatus' => $object->transitionStatus,
            'transitionTargetTier' => $object->transitionTargetTier,
            'transitionError' => $object->transitionError,
            'ownerId' => $object->ownerId,
            'versionId' => $object->versionId,
            'isDeleteMarker' => $object->isDeleteMarker,
            'userMetadata' => $userMetadata,
            'systemMetadata' => $systemMetadata,
            'lastModified' => $object->lastModified->format('U.u'),
            'partLayoutDigest' => $object->partLayout?->digest,
        ];
    }
}
