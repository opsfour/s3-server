<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\RequestNetworkContext;

/**
 * Checks IAM actions required by optional fields on a write operation.
 */
final readonly class AdditionalActionAuthorizer
{
    public function __construct(private MetadataStore $metadata) {}

    /**
     * @param list<array{key: string, value: string}> $requestTags
     */
    public function assertAllowed(Request $request, string $bucket, string $key, string $action, array $requestTags = []): void
    {
        $ownerId = $request->hasAttribute('ownerId') ? (string) $request->getAttribute('ownerId') : '';
        $bucketOwner = $this->metadata->getBucketOwner($bucket);
        if ($bucketOwner === null) {
            throw new AccessDeniedException();
        }

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
        foreach (['x-amz-acl', 'x-amz-server-side-encryption', 'x-amz-storage-class'] as $header) {
            $value = $request->getHeader($header);
            if ($value !== null && $value !== '') {
                $conditions['s3:' . $header] = $value;
            }
        }
        foreach ($requestTags as $tag) {
            $conditions['s3:RequestObjectTag/' . $tag['key']] = $tag['value'];
        }
        if ($action === 's3:PutObjectTagging') {
            $operation = $request->hasAttribute('s3.operation')
                ? $request->getAttribute('s3.operation') : null;
            foreach (ExistingObjectTagResolver::forRequest(
                $this->metadata,
                $bucket,
                $key,
                $operation instanceof S3Operation ? $operation : S3Operation::PutObject,
            ) as $tag) {
                $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
            }
        }

        $resource = "arn:aws:s3:::{$bucket}/{$key}";
        $identityAllowed = false;
        $policies = [];
        if ($ownerId !== '') {
            $accountPolicy = $this->metadata->getAccountPolicy($ownerId);
            if ($accountPolicy !== null) {
                $policies[] = $accountPolicy;
            }
        }
        $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
        if ($credential instanceof Credential) {
            foreach ($credential->policyNames as $policyName) {
                if ($policyName === '') {
                    continue;
                }
                $policy = NamedPolicySnapshot::get($request, $this->metadata, $policyName);
                if ($policy === null) {
                    throw new AccessDeniedException('Named policy attached to credential does not exist.');
                }
                $policies[] = $policy;
            }
        }

        foreach ($policies as $policy) {
            $result = PolicyEvaluator::evaluate($policy, $action, $resource, $ownerId, $conditions, requirePrincipal: false);
            if ($result === 'Deny') {
                throw new AccessDeniedException();
            }
            $identityAllowed = $identityAllowed || $result === 'Allow';
        }

        $bucketPolicy = $this->metadata->getBucketPolicy($bucket);
        $resourceResult = $bucketPolicy !== null
            ? PolicyEvaluator::evaluate($bucketPolicy, $action, $resource, $ownerId, $conditions)
            : 'Neutral';
        if ($resourceResult === 'Deny') {
            throw new AccessDeniedException();
        }

        if ($ownerId !== '' && $ownerId === $bucketOwner) {
            return;
        }
        if ($identityAllowed && $resourceResult === 'Allow' && $bucketPolicy !== null) {
            $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
            if ($publicAccessBlock === null
                || ! $publicAccessBlock['restrictPublicBuckets']
                || ! PolicyEvaluator::isPublicPolicy($bucketPolicy)) {
                return;
            }
        }

        throw new AccessDeniedException();
    }
}
