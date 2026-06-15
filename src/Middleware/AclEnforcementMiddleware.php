<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Exception\AccessDeniedException;
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

        // Skip ACL check for non-existent buckets — the handler will throw NoSuchBucketException.
        if ($cachedBucketOwner === null) {
            return $requestHandler->handleRequest($request);
        }

        // Determine resource type and name for ACL lookup.
        // For object operations: check object ACL first, then fall back to bucket ACL.
        // Bucket WRITE grants PutObject/DeleteObject; bucket READ grants ListObjects.
        $resourceType = $key !== null ? 'object' : 'bucket';
        $resourceName = $key !== null ? "{$bucket}/{$key}" : $bucket;

        // Fetch ACL grants.
        $grants = [];
        try {
            $grants = $this->metadata->getAcl($resourceType, $resourceName);
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
                } catch (\Throwable) {
                }
            }
        }

        // If no explicit grants, check if owner.
        // Guard: ownerId must be non-empty to prevent anonymous users matching empty owner.
        if ($grants === []) {
            if ($ownerId !== '' && $cachedBucketOwner === $ownerId) {
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
        } catch (\Throwable) {
            // Fail-closed: if we can't check PAB, deny access.
            throw new AccessDeniedException('Unable to verify public access block configuration.');
        }

        // Use cached bucket owner.
        $resourceOwner = $cachedBucketOwner;

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
