<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Routing\S3Operation;

/**
 * Enforces bucket policies on incoming requests.
 *
 * Maps S3 operations to IAM action strings and evaluates them
 * against the bucket policy. Explicit Deny throws AccessDeniedException.
 */
final class PolicyEnforcementMiddleware implements Middleware
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

        if ($bucket === null || $bucket === '' || $operation === null) {
            return $requestHandler->handleRequest($request);
        }

        $bucketOwner = $this->metadata->getBucketOwner($bucket);
        // Cache for downstream middleware (AclEnforcementMiddleware) to avoid re-querying.
        $request->setAttribute('s3.cachedBucketOwner', $bucketOwner);
        $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
        if ($credential instanceof Credential && !$this->credentialScopeAllows($credential, $key, $request)) {
            throw new AccessDeniedException;
        }

        $action = self::operationToAction($operation);
        $resource = $key !== null
            ? "arn:aws:s3:::{$bucket}/{$key}"
            : "arn:aws:s3:::{$bucket}";

        $conditions = [];
        if ($ownerId !== '') {
            $conditions['aws:PrincipalArn'] = $ownerId;
        }
        $remoteAddress = $request->getClient()->getRemoteAddress();
        if ($remoteAddress instanceof \Amp\Socket\InternetAddress) {
            $conditions['aws:SourceIp'] = $remoteAddress->getAddress();
        }
        $conditions['aws:SecureTransport'] = $request->getClient()->getTlsInfo() !== null ? 'true' : 'false';

        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        if (isset($queryParams['prefix'])) {
            $conditions['s3:prefix'] = $queryParams['prefix'];
        }
        if (isset($queryParams['delimiter'])) {
            $conditions['s3:delimiter'] = $queryParams['delimiter'];
        }
        if (isset($queryParams['max-keys'])) {
            $conditions['s3:max-keys'] = $queryParams['max-keys'];
        }
        if (isset($queryParams['versionId'])) {
            $conditions['s3:VersionId'] = $queryParams['versionId'];
        }

        foreach (['x-amz-acl', 'x-amz-server-side-encryption', 'x-amz-storage-class'] as $header) {
            $value = $request->getHeader($header);
            if ($value !== null && $value !== '') {
                $conditions['s3:' . $header] = $value;
            }
        }
        $conditions['aws:CurrentTime'] = gmdate(\DateTimeInterface::ATOM);

        foreach ($this->requestObjectTags($request, $operation) as $tagKey => $tagValue) {
            $conditions['s3:RequestObjectTag/' . $tagKey] = $tagValue;
        }

        if ($key !== null) {
            foreach ($this->metadata->getObjectTagging($bucket, $key) as $tag) {
                if (isset($tag['key'], $tag['value'])) {
                    $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
                }
            }
        }

        $identityPolicies = [];
        if ($ownerId !== '') {
            $accountPolicy = $this->metadata->getAccountPolicy($ownerId);
            if ($accountPolicy !== null) {
                $identityPolicies[] = $accountPolicy;
            }
        }
        if ($credential instanceof Credential) {
            foreach ($credential->policyNames as $policyName) {
                if (!is_string($policyName) || $policyName === '') {
                    continue;
                }
                $namedPolicy = $this->metadata->getNamedPolicy($policyName);
                if ($namedPolicy === null) {
                    throw new AccessDeniedException('Named policy attached to credential does not exist.');
                }
                $identityPolicies[] = $namedPolicy;
            }
        }

        $identityResult = self::evaluatePolicies($identityPolicies, $action, $resource, $ownerId, $conditions);
        if ($identityResult === 'Deny') {
            throw new AccessDeniedException;
        }

        // Bucket owner bypass applies only after account/named identity policies.
        // Guard: ownerId must be non-empty to prevent anonymous users matching empty owner.
        if ($ownerId !== '' && $bucketOwner !== null && $bucketOwner === $ownerId) {
            $request->setAttribute('s3.policyResult', $identityResult === 'Allow' ? 'Allow' : 'OwnerBypass');
            return $requestHandler->handleRequest($request);
        }

        $policyJson = $this->metadata->getBucketPolicy($bucket);
        $resourceResult = $policyJson !== null
            ? PolicyEvaluator::evaluate($policyJson, $action, $resource, $ownerId, $conditions)
            : 'Neutral';

        $result = self::mergePolicyResults($identityResult, $resourceResult);

        if ($result === 'Deny') {
            throw new AccessDeniedException;
        }

        $request->setAttribute('s3.policyResult', $result);

        return $requestHandler->handleRequest($request);
    }

    /**
     * @param list<string> $policyJsonDocuments
     * @param array<string, string|list<string>> $conditions
     */
    private static function evaluatePolicies(
        array $policyJsonDocuments,
        string $action,
        string $resource,
        string $principal,
        array $conditions,
    ): string {
        $result = 'Neutral';

        foreach ($policyJsonDocuments as $policyJson) {
            $policyResult = PolicyEvaluator::evaluate(
                $policyJson,
                $action,
                $resource,
                $principal,
                $conditions,
                requirePrincipal: false,
            );
            $result = self::mergePolicyResults($result, $policyResult);
            if ($result === 'Deny') {
                return 'Deny';
            }
        }

        return $result;
    }

    private static function mergePolicyResults(string $left, string $right): string
    {
        if ($left === 'Deny' || $right === 'Deny') {
            return 'Deny';
        }
        if ($left === 'Allow' || $right === 'Allow') {
            return 'Allow';
        }

        return 'Neutral';
    }

    private function credentialScopeAllows(Credential $credential, ?string $key, Request $request): bool
    {
        if ($credential->allowedPrefixes === []) {
            return true;
        }

        $target = $key;
        if ($target === null) {
            $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
            $target = $queryParams['prefix'] ?? '';
        }

        foreach ($credential->allowedPrefixes as $prefix) {
            if (is_string($prefix) && str_starts_with($target, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function requestObjectTags(Request $request, S3Operation $operation): array
    {
        $tags = [];

        $taggingHeader = $request->getHeader('x-amz-tagging');
        if ($taggingHeader !== null && $taggingHeader !== '') {
            parse_str($taggingHeader, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $tags[$key] = $value;
                }
            }
        }

        if ($operation === S3Operation::PutObjectTagging) {
            $body = $request->getBody()->buffer();
            $request->setBody($body);
            foreach (self::parseTaggingXml($body) as $key => $value) {
                $tags[$key] = $value;
            }
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    private static function parseTaggingXml(string $body): array
    {
        if ($body === '') {
            return [];
        }

        try {
            $xml = new \SimpleXMLElement($body);
        } catch (\Throwable) {
            return [];
        }

        $tags = [];
        foreach ($xml->TagSet->Tag ?? [] as $tag) {
            $key = trim((string) ($tag->Key ?? ''));
            $value = (string) ($tag->Value ?? '');
            if ($key !== '') {
                $tags[$key] = $value;
            }
        }

        return $tags;
    }

    /**
     * Map an S3Operation enum to the IAM action string.
     */
    private static function operationToAction(S3Operation $operation): string
    {
        return match ($operation) {
            S3Operation::GetObject, S3Operation::HeadObject => 's3:GetObject',
            S3Operation::PutObject => 's3:PutObject',
            S3Operation::DeleteObject => 's3:DeleteObject',
            S3Operation::DeleteObjects => 's3:DeleteObject',
            S3Operation::CopyObject => 's3:PutObject',
            S3Operation::ListObjectsV2, S3Operation::ListObjects => 's3:ListBucket',
            S3Operation::ListObjectVersions => 's3:ListBucketVersions',
            S3Operation::CreateBucket => 's3:CreateBucket',
            S3Operation::DeleteBucket => 's3:DeleteBucket',
            S3Operation::HeadBucket => 's3:ListBucket',
            S3Operation::ListBuckets => 's3:ListAllMyBuckets',
            S3Operation::GetBucketAcl => 's3:GetBucketAcl',
            S3Operation::PutBucketAcl => 's3:PutBucketAcl',
            S3Operation::GetObjectAcl => 's3:GetObjectAcl',
            S3Operation::PutObjectAcl => 's3:PutObjectAcl',
            S3Operation::GetBucketPolicy => 's3:GetBucketPolicy',
            S3Operation::PutBucketPolicy => 's3:PutBucketPolicy',
            S3Operation::DeleteBucketPolicy => 's3:DeleteBucketPolicy',
            S3Operation::GetBucketVersioning => 's3:GetBucketVersioning',
            S3Operation::PutBucketVersioning => 's3:PutBucketVersioning',
            S3Operation::GetBucketLocation => 's3:GetBucketLocation',
            S3Operation::GetBucketCors => 's3:GetBucketCORS',
            S3Operation::PutBucketCors => 's3:PutBucketCORS',
            S3Operation::DeleteBucketCors => 's3:PutBucketCORS',
            S3Operation::GetBucketTagging => 's3:GetBucketTagging',
            S3Operation::PutBucketTagging => 's3:PutBucketTagging',
            S3Operation::DeleteBucketTagging => 's3:PutBucketTagging',
            S3Operation::GetObjectTagging => 's3:GetObjectTagging',
            S3Operation::PutObjectTagging => 's3:PutObjectTagging',
            S3Operation::DeleteObjectTagging => 's3:DeleteObjectTagging',
            S3Operation::GetBucketEncryption => 's3:GetEncryptionConfiguration',
            S3Operation::PutBucketEncryption => 's3:PutEncryptionConfiguration',
            S3Operation::DeleteBucketEncryption => 's3:PutEncryptionConfiguration',
            S3Operation::GetBucketLifecycle => 's3:GetLifecycleConfiguration',
            S3Operation::PutBucketLifecycle => 's3:PutLifecycleConfiguration',
            S3Operation::DeleteBucketLifecycle => 's3:PutLifecycleConfiguration',
            S3Operation::GetBucketNotification => 's3:GetBucketNotification',
            S3Operation::PutBucketNotification => 's3:PutBucketNotification',
            S3Operation::GetObjectLockConfig => 's3:GetObjectLockConfiguration',
            S3Operation::PutObjectLockConfig => 's3:PutObjectLockConfiguration',
            S3Operation::GetObjectRetention => 's3:GetObjectRetention',
            S3Operation::PutObjectRetention => 's3:PutObjectRetention',
            S3Operation::GetObjectLegalHold => 's3:GetObjectLegalHold',
            S3Operation::PutObjectLegalHold => 's3:PutObjectLegalHold',
            S3Operation::CreateMultipartUpload => 's3:PutObject',
            S3Operation::UploadPart, S3Operation::UploadPartCopy => 's3:PutObject',
            S3Operation::CompleteMultipartUpload => 's3:PutObject',
            S3Operation::AbortMultipartUpload => 's3:AbortMultipartUpload',
            S3Operation::ListParts => 's3:ListMultipartUploadParts',
            S3Operation::ListMultipartUploads => 's3:ListBucketMultipartUploads',
            S3Operation::GetBucketWebsite => 's3:GetBucketWebsite',
            S3Operation::PutBucketWebsite => 's3:PutBucketWebsite',
            S3Operation::DeleteBucketWebsite => 's3:DeleteBucketWebsite',
            S3Operation::GetPublicAccessBlock => 's3:GetBucketPublicAccessBlock',
            S3Operation::PutPublicAccessBlock => 's3:PutBucketPublicAccessBlock',
            S3Operation::DeletePublicAccessBlock => 's3:DeleteBucketPublicAccessBlock',
            S3Operation::RestoreObject => 's3:RestoreObject',
            S3Operation::SelectObjectContent => 's3:GetObject',
            default => 's3:' . $operation->value,
        };
    }
}
