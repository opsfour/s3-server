<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\RequestNetworkContext;

/**
 * Authorizes a direct or batched object deletion for its specific key and version.
 */
final class ObjectDeletePolicyGuard
{
    public function __construct(private readonly MetadataStore $metadata) {}

    /**
     * @return bool Whether this entry may bypass governance retention.
     */
    public function check(
        Request $request,
        string $bucket,
        string $key,
        ?string $versionId,
        string $ownerId,
        string $bucketOwner,
        ?ObjectInfo $resolvedTarget = null,
        bool $targetResolved = false,
        bool $lockNamedPolicies = true,
    ): bool {
        if ($targetResolved && $resolvedTarget !== null) {
            $this->assertResolvedTarget($resolvedTarget, $bucket, $key, $versionId);
        }

        $action = $versionId === null ? 's3:DeleteObject' : 's3:DeleteObjectVersion';
        $resource = "arn:aws:s3:::{$bucket}/{$key}";
        $conditions = [
            'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
            'aws:SecureTransport' => RequestNetworkContext::secureTransport($request) ? 'true' : 'false',
        ];
        if ($ownerId !== '') {
            $conditions['aws:PrincipalArn'] = $ownerId;
        }
        $address = RequestNetworkContext::sourceIp($request);
        if ($address !== null) {
            $conditions['aws:SourceIp'] = $address;
        }
        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }
        AuthType::addToPolicyContext($request, $conditions);
        if ($versionId !== null) {
            $conditions['s3:VersionId'] = $versionId;
        }
        $authorizationTarget = $resolvedTarget;
        $tagTarget = $resolvedTarget;
        if ($targetResolved && $authorizationTarget === null && $versionId === null) {
            $versioning = $this->metadata->getBucketVersioning($bucket);
            if ($versioning === 'Enabled' || $versioning === 'Suspended') {
                // Marker-only deletes have no quota-accounted removal target,
                // but established authorization still checks the current object.
                $authorizationTarget = $this->metadata->getObjectMetadata($bucket, $key);
                if ($versioning === 'Enabled') {
                    $tagTarget = $authorizationTarget;
                }
            }
        }
        $existingTags = $targetResolved
            ? $this->tagsForResolvedTarget($tagTarget)
            : ExistingObjectTagResolver::forRequest(
                $this->metadata,
                $bucket,
                $key,
                S3Operation::DeleteObject,
                $versionId,
            );
        foreach ($existingTags as $tag) {
            $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
        }

        $policies = [];
        if ($ownerId !== '') {
            $accountPolicy = $this->metadata->getAccountPolicy($ownerId);
            if ($accountPolicy !== null) {
                $policies[] = $accountPolicy;
            }
        }
        $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
        if ($credential instanceof Credential) {
            $namedPolicies = [];
            if ($credential->policyNames !== []) {
                if ($lockNamedPolicies) {
                    $namedPolicies = $this->metadata->lockNamedPoliciesForUpdate($credential->policyNames);
                    NamedPolicySnapshot::attach($request, $namedPolicies);
                } else {
                    foreach ($credential->policyNames as $policyName) {
                        if ($policyName === '') {
                            continue;
                        }
                        $policy = $this->metadata->getNamedPolicy($policyName);
                        if ($policy !== null) {
                            $namedPolicies[$policyName] = $policy;
                        }
                    }
                }
            }
            if ($credential->allowedPrefixes !== []
                && !array_any($credential->allowedPrefixes, static fn(string $prefix): bool => str_starts_with($key, $prefix))) {
                throw new AccessDeniedException();
            }
            foreach ($credential->policyNames as $policyName) {
                if ($policyName === '') {
                    continue;
                }
                $policy = $lockNamedPolicies
                    ? NamedPolicySnapshot::get($request, $this->metadata, $policyName)
                    : ($namedPolicies[$policyName] ?? null);
                if ($policy === null) {
                    throw new AccessDeniedException('Named policy attached to credential does not exist.');
                }
                $policies[] = $policy;
            }
        }
        $bucketPolicy = $this->metadata->getBucketPolicy($bucket);
        $identityAllowed = false;
        foreach ($policies as $policy) {
            $result = PolicyEvaluator::evaluate($policy, $action, $resource, $ownerId, $conditions, requirePrincipal: false);
            if ($result === 'Deny') {
                throw new AccessDeniedException();
            }
            $identityAllowed = $identityAllowed || $result === 'Allow';
        }
        $resourceResult = $bucketPolicy !== null
            ? PolicyEvaluator::evaluate($bucketPolicy, $action, $resource, $ownerId, $conditions)
            : 'Neutral';
        if ($resourceResult === 'Deny') {
            throw new AccessDeniedException();
        }

        if ($ownerId === '' || $ownerId !== $bucketOwner) {
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null
                && $pab['restrictPublicBuckets']
                && $bucketPolicy !== null
                && PolicyEvaluator::isPublicPolicy($bucketPolicy)) {
                throw new AccessDeniedException();
            }

            // In a suspended bucket, an unversioned DELETE replaces the null
            // version even when a numbered version is currently latest.
            // Authorize against the object being removed, not that latest version.
            if ($targetResolved) {
                $object = $authorizationTarget;
            } elseif ($versionId !== null) {
                $object = $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
            } elseif ($this->metadata->getBucketVersioning($bucket) === 'Suspended') {
                $object = $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null')
                    ?? $this->metadata->getObjectMetadata($bucket, $key);
            } else {
                $object = $this->metadata->getObjectMetadata($bucket, $key);
            }
            $effectiveOwner = $object !== null && BucketOwnership::isEnforced($this->metadata, $bucket)
                ? $bucketOwner : $object?->ownerId;
            $ownsExistingObject = $object !== null && $ownerId !== '' && $effectiveOwner === $ownerId;
            if ($ownerId !== '' && ! $identityAllowed) {
                throw new AccessDeniedException();
            }
            // The bucket owner may remove a foreign-owned object, but a bucket
            // policy cannot delegate that exception to another account.
            if ($object !== null && $effectiveOwner !== $bucketOwner && ! $ownsExistingObject) {
                throw new AccessDeniedException();
            }
            $bucketWriteGrant = $versionId === null && $ownsExistingObject && AclEvaluator::isAllowed(
                S3Operation::PutObject,
                $ownerId,
                $bucketOwner,
                $this->metadata->getAcl('bucket', $bucket),
                ignorePublicAcls: $pab['ignorePublicAcls'] ?? false,
            );
            if ($resourceResult !== 'Allow' && ! $bucketWriteGrant) {
                throw new AccessDeniedException();
            }
        }

        if (strtolower($request->getHeader('x-amz-bypass-governance-retention') ?? '') !== 'true') {
            return false;
        }

        $bypassIdentityAllowed = $ownerId !== '' && $ownerId === $bucketOwner;
        $bypassResourceAllowed = $bypassIdentityAllowed;
        foreach ($policies as $policy) {
            $result = PolicyEvaluator::evaluate($policy, 's3:BypassGovernanceRetention', $resource, $ownerId, $conditions, requirePrincipal: false);
            if ($result === 'Deny') {
                return false;
            }
            $bypassIdentityAllowed = $bypassIdentityAllowed || $result === 'Allow';
        }
        if ($bucketPolicy !== null) {
            $result = PolicyEvaluator::evaluate($bucketPolicy, 's3:BypassGovernanceRetention', $resource, $ownerId, $conditions);
            if ($result === 'Deny') {
                return false;
            }
            $bypassResourceAllowed = $bypassResourceAllowed || $result === 'Allow';
        }

        return $bypassIdentityAllowed && $bypassResourceAllowed;
    }

    /** @return list<array{key: string, value: string}> */
    private function tagsForResolvedTarget(?ObjectInfo $target): array
    {
        if ($target === null || $target->isDeleteMarker) {
            return [];
        }

        return $this->metadata->getObjectTagging($target->bucket, $target->key, $target->versionId);
    }

    private function assertResolvedTarget(
        ObjectInfo $target,
        string $bucket,
        string $key,
        ?string $versionId,
    ): void {
        if ($target->bucket !== $bucket
            || $target->key !== $key
            || ($versionId !== null && $target->versionId !== $versionId)) {
            throw new \LogicException('Resolved delete target does not match the requested object version.');
        }
    }
}
