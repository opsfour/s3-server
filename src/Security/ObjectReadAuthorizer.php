<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Security;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Routing\S3Operation;

final readonly class ObjectReadAuthorizer
{
    public function __construct(
        private MetadataStore $metadata,
    ) {}

    public function assertAllowed(Request $request, ObjectInfo $object): void
    {
        $ownerId = $request->hasAttribute('ownerId')
            ? (string) $request->getAttribute('ownerId')
            : '';
        $credential = $request->hasAttribute('credential')
            ? $request->getAttribute('credential')
            : null;
        $bucketOwner = $this->metadata->getBucketOwner($object->bucket);
        if ($bucketOwner === null) {
            throw new AccessDeniedException();
        }

        if (
            $credential instanceof Credential
            && $credential->allowedPrefixes !== []
            && ! self::prefixAllowed($credential, $object->key)
        ) {
            throw new AccessDeniedException();
        }

        $conditions = $this->conditions($request, $object, $ownerId);
        $resource = "arn:aws:s3:::{$object->bucket}/{$object->key}";
        $identityPolicies = [];
        if ($ownerId !== '') {
            $accountPolicy = $this->metadata->getAccountPolicy($ownerId);
            if ($accountPolicy !== null) {
                $identityPolicies[] = $accountPolicy;
            }
        }
        if ($credential instanceof Credential) {
            foreach ($credential->policyNames as $policyName) {
                if ($policyName === '') {
                    continue;
                }
                $namedPolicy = $this->metadata->getNamedPolicy($policyName);
                if ($namedPolicy === null) {
                    throw new AccessDeniedException('Named policy attached to credential does not exist.');
                }
                $identityPolicies[] = $namedPolicy;
            }
        }

        $identityResult = self::evaluatePolicies(
            $identityPolicies,
            $resource,
            $ownerId,
            $conditions,
        );
        $bucketPolicy = $this->metadata->getBucketPolicy($object->bucket);
        $resourceResult = $bucketPolicy !== null
            ? PolicyEvaluator::evaluate(
                $bucketPolicy,
                's3:GetObject',
                $resource,
                $ownerId,
                $conditions,
            )
            : 'Neutral';

        if ($identityResult === 'Deny' || $resourceResult === 'Deny') {
            throw new AccessDeniedException();
        }

        $publicAccessBlock = $this->metadata->getPublicAccessBlock($object->bucket);
        if (
            $resourceResult === 'Allow'
            && $bucketPolicy !== null
            && $publicAccessBlock !== null
            && $publicAccessBlock['restrictPublicBuckets']
            && PolicyEvaluator::isPublicPolicy($bucketPolicy)
            && ($ownerId === '' || $bucketOwner !== $ownerId)
        ) {
            throw new AccessDeniedException();
        }

        if (
            ($ownerId !== '' && $ownerId === $bucketOwner)
            || $identityResult === 'Allow'
            || $resourceResult === 'Allow'
        ) {
            return;
        }

        $resourceName = ObjectVersionResolver::aclResourceName(
            $object->bucket,
            $object->key,
            $object->versionId,
        );
        $grants = $this->metadata->getAcl('object', $resourceName);
        if ($grants === [] && ($object->versionId === null || $object->versionId === 'null')) {
            $grants = $this->metadata->getAcl('object', $object->bucket . '/' . $object->key);
        }
        $ignorePublicAcls = $publicAccessBlock['ignorePublicAcls'] ?? false;

        if (! AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: $ownerId,
            ownerId: $bucketOwner,
            grants: $grants,
            isAuthenticated: $ownerId !== '',
            ignorePublicAcls: $ignorePublicAcls,
        )) {
            throw new AccessDeniedException();
        }
    }

    /**
     * @param list<string> $policies
     * @param array<string, string|list<string>> $conditions
     */
    private static function evaluatePolicies(
        array $policies,
        string $resource,
        string $ownerId,
        array $conditions,
    ): string {
        $result = 'Neutral';
        foreach ($policies as $policy) {
            $current = PolicyEvaluator::evaluate(
                $policy,
                's3:GetObject',
                $resource,
                $ownerId,
                $conditions,
                requirePrincipal: false,
            );
            if ($current === 'Deny') {
                return 'Deny';
            }
            if ($current === 'Allow') {
                $result = 'Allow';
            }
        }

        return $result;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function conditions(Request $request, ObjectInfo $object, string $ownerId): array
    {
        $conditions = [
            'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
            'aws:SecureTransport' => $request->getClient()->getTlsInfo() !== null ? 'true' : 'false',
        ];
        if ($ownerId !== '') {
            $conditions['aws:PrincipalArn'] = $ownerId;
        }
        $remoteAddress = $request->getClient()->getRemoteAddress();
        if ($remoteAddress instanceof \Amp\Socket\InternetAddress) {
            $conditions['aws:SourceIp'] = $remoteAddress->getAddress();
        }
        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }
        if ($object->versionId !== null) {
            $conditions['s3:VersionId'] = $object->versionId;
        }
        foreach ($this->metadata->getObjectTagging($object->bucket, $object->key, $object->versionId) as $tag) {
            $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
        }

        return $conditions;
    }

    private static function prefixAllowed(Credential $credential, string $key): bool
    {
        foreach ($credential->allowedPrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
