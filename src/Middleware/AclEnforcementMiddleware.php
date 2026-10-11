<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;

/**
 * Enforces ACL permissions on incoming requests.
 *
 * Runs after PolicyEnforcementMiddleware. If policy explicitly allowed,
 * ACL check is skipped (AWS union model).
 */
final class AclEnforcementMiddleware implements Middleware
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        $key = $request->hasAttribute('s3.key') ? $request->getAttribute('s3.key') : null;
        if ($key === '') {
            $key = null;
        }
        $ownerId = $request->hasAttribute('ownerId') ? $request->getAttribute('ownerId') : '';
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        $policyResult = $request->hasAttribute('s3.policyResult') ? $request->getAttribute('s3.policyResult') : null;

        // Skip ACL check if policy explicitly allowed or if owner bypass.
        if ($policyResult === 'Allow' || $policyResult === 'OwnerBypass') {
            return $requestHandler->handleRequest($request);
        }

        if ($bucket === null || $bucket === '' || $operation === null) {
            return $requestHandler->handleRequest($request);
        }

        // Use cached bucket owner from PolicyEnforcementMiddleware to avoid re-querying.
        $cachedBucketOwner = $request->hasAttribute('s3.cachedBucketOwner')
            ? $request->getAttribute('s3.cachedBucketOwner')
            : $this->metadata->getBucketOwner($bucket);

        // Bucket creation has no existing owner or ACL. It is allowed only for
        // authenticated callers after identity-policy evaluation.
        if ($cachedBucketOwner === null) {
            if ($operation === S3Operation::CreateBucket) {
                if ($ownerId === '') {
                    throw new AccessDeniedException();
                }

                return $requestHandler->handleRequest($request);
            }

            // Do not reveal bucket existence to anonymous callers.
            if ($ownerId === '') {
                throw new AccessDeniedException();
            }

            // Authenticated callers receive the operation-specific not-found
            // response from the handler.
            return $requestHandler->handleRequest($request);
        }

        // Deletes, multipart aborts, and part listings require context-specific
        // ownership and policy checks in their handlers.
        if (in_array($operation, [S3Operation::DeleteObject, S3Operation::DeleteObjects, S3Operation::AbortMultipartUpload, S3Operation::ListParts], true)) {
            return $requestHandler->handleRequest($request);
        }

        // Determine resource type and name for ACL lookup.
        // For object operations: check object ACL first, then fall back to bucket ACL.
        // Bucket WRITE grants PutObject/DeleteObject; bucket READ grants ListObjects.
        $resourceType = $key !== null ? 'object' : 'bucket';
        $ownershipEnforced = BucketOwnership::isEnforced($this->metadata, $bucket);
        $resourceName = $bucket;
        if ($key !== null) {
            $versionId = $operation->selectsObjectVersion()
                ? (QueryStringParser::parse($request->getUri()->getQuery())['versionId'] ?? null)
                : null;
            if ($versionId === null) {
                $versionId = $this->metadata->getObjectMetadata($bucket, $key)?->versionId;
            }
            $resourceName = ObjectVersionResolver::aclResourceName($bucket, $key, $versionId);
        }

        // Fetch ACL grants.
        $grants = [];
        try {
            if ($ownershipEnforced && $key !== null) {
                // Object ACL grants are disabled. Bucket WRITE grants remain
                // available to authorize new object writes.
                $grants = AclEvaluator::operationToPermission($operation) === 'WRITE'
                    ? $this->metadata->getAcl('bucket', $bucket)
                    : [];
            } else {
                $grants = $this->metadata->getAcl($resourceType, $resourceName);
                if (
                    $grants === []
                    && $key !== null
                    && ($versionId === null || $versionId === 'null')
                ) {
                    $grants = $this->metadata->getAcl('object', "{$bucket}/{$key}");
                }
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
        }

        // For object WRITE operations with no object-level ACL, fall back to bucket ACL.
        // Bucket WRITE grants PutObject/DeleteObject. Bucket READ only grants ListObjects,
        // NOT GetObject — individual object read requires object-level ACL.
        if ($grants === [] && $key !== null) {
            $requiredPerm = AclEvaluator::operationToPermission($operation);
            if ($requiredPerm === 'WRITE') {
                try {
                    $grants = $this->metadata->getAcl('bucket', $bucket);
                } catch (\Amp\CancelledException $error) {
                    throw $error;
                } catch (\Throwable) {
                }
            }
        }

        $resourceOwner = $cachedBucketOwner;
        if ($key !== null && $operation->usesExistingObjectOwner()) {
            $objectOwner = $request->hasAttribute('s3.cachedObjectOwner')
                ? $request->getAttribute('s3.cachedObjectOwner')
                : (isset($versionId)
                    ? $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId)?->ownerId
                    : $this->metadata->getObjectMetadata($bucket, $key)?->ownerId);
            if ($objectOwner !== null && ! $ownershipEnforced) {
                $resourceOwner = $objectOwner;
            }
        }

        // If no explicit grants, check if owner.
        // Guard: ownerId must be non-empty to prevent anonymous users matching empty owner.
        if ($grants === []) {
            if ($ownerId !== '' && $resourceOwner === $ownerId) {
                return $requestHandler->handleRequest($request);
            }
        }

        // Check Public Access Block for ignorePublicAcls.
        $ignorePublicAcls = false;
        try {
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null) {
                $ignorePublicAcls = $pab['ignorePublicAcls'];
            }
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // Fail-closed: if we can't check PAB, deny access.
            throw new AccessDeniedException('Unable to verify public access block configuration.');
        }

        if (!AclEvaluator::isAllowed(
            operation: $operation,
            requesterId: $ownerId,
            ownerId: $resourceOwner,
            grants: $grants,
            isAuthenticated: $ownerId !== '',
            ignorePublicAcls: $ignorePublicAcls,
        )) {
            throw new AccessDeniedException();
        }

        return $requestHandler->handleRequest($request);
    }
}
