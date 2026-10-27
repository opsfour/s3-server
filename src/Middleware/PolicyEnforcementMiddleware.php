<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\AuthorizedObjectSnapshot;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Multipart\MultipartAclSnapshot;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Policy\AdditionalActionAuthorizer;
use OpsFour\S3Server\Policy\ExistingObjectTagResolver;
use OpsFour\S3Server\Policy\NamedPolicySnapshot;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Enforces bucket policies on incoming requests.
 *
 * Maps S3 operations to IAM action strings and evaluates them
 * against the bucket policy. Explicit Deny throws AccessDeniedException.
 */
final class PolicyEnforcementMiddleware implements Middleware
{
    private const string MULTIPART_POLICY_CONTEXT_INPUT_ATTRIBUTE = 's3.multipartCompletionPolicyContextInput';

    public const string MULTIPART_POLICY_CONTEXT_VERSION_KEY = '__mpu-policy-context-version';

    public const string MULTIPART_POLICY_CONTEXT_HEADERS_KEY = '__mpu-policy-condition-headers';

    public const string MULTIPART_POLICY_CONTEXT_VERSION = '1';

    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    /** Recheck current policy and object tags under the write transaction. */
    public function assertAllowedAtCommit(Request $request): void
    {
        $this->assertAllowedWithLockedPolicies($request, false);
    }

    /**
     * Recheck the options that will actually be published by a multipart
     * completion. CompleteMultipartUpload carries only the upload ID; its
     * tags, ACL, and Object Lock settings were supplied at initiation.
     *
     * @param array<string, string> $uploadMetadata
     * @param list<array{key: string, value: string}> $tags
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    public function assertMultipartCompletionAllowedAtCommit(
        Request $request,
        array $uploadMetadata,
        array $tags,
        array $aclGrants,
    ): void {
        // Direct handler callers do not have an ingress authorization decision.
        // Preserve their validation contract without pretending to authorize them.
        if (! $request->hasAttribute('s3.policyResult')) {
            $this->attachMultipartCompletionContext($request, $uploadMetadata, $tags, $aclGrants);

            return;
        }

        $request->setAttribute(self::MULTIPART_POLICY_CONTEXT_INPUT_ATTRIBUTE, [
            'metadata' => $uploadMetadata,
            'tags' => $tags,
            'aclGrants' => $aclGrants,
        ]);
        try {
            $this->assertAllowedAtCommit($request);
        } finally {
            $request->removeAttribute(self::MULTIPART_POLICY_CONTEXT_INPUT_ATTRIBUTE);
        }
    }

    /**
     * @param array<string, string> $uploadMetadata
     * @param list<array{key: string, value: string}> $tags
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    private function attachMultipartCompletionContext(Request $request, array $uploadMetadata, array $tags, array $aclGrants): void
    {
        $ownerId = (string) $request->getAttribute('ownerId');
        $requestTags = [];
        foreach ($tags as $tag) {
            $requestTags[$tag['key']] = $tag['value'];
        }
        if (($uploadMetadata[self::MULTIPART_POLICY_CONTEXT_VERSION_KEY] ?? null) !== self::MULTIPART_POLICY_CONTEXT_VERSION) {
            throw new OperationAbortedException(
                'This multipart upload predates exact policy-context snapshots; abort it and initiate a new upload.',
            );
        }
        try {
            $headers = json_decode(
                $uploadMetadata[self::MULTIPART_POLICY_CONTEXT_HEADERS_KEY] ?? '',
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $error) {
            throw new InternalErrorException('Multipart upload policy header snapshot is invalid.', $error);
        }
        if (! is_array($headers)) {
            throw new InternalErrorException('Multipart upload policy header snapshot is invalid.');
        }
        $allowedHeaders = [
            'x-amz-acl' => true,
            'x-amz-server-side-encryption' => true,
            'x-amz-storage-class' => true,
        ];
        foreach ($headers as $header => $value) {
            if (! is_string($header) || ! isset($allowedHeaders[$header]) || ! is_string($value) || $value === '') {
                throw new InternalErrorException('Multipart upload policy header snapshot is invalid.');
            }
        }
        if (! array_key_exists(MultipartAclSnapshot::METADATA_KEY, $uploadMetadata)) {
            throw new InternalErrorException('Multipart upload ACL snapshot is missing.');
        }

        $hasExplicitAcl = isset($uploadMetadata['__mpu-acl-explicit']);
        $storedAclHeader = $uploadMetadata['__mpu-acl-header'] ?? null;
        $snapshotAclHeader = $headers['x-amz-acl'] ?? null;
        if (($hasExplicitAcl && $uploadMetadata['__mpu-acl-explicit'] !== '1')
            || ($snapshotAclHeader !== null
                && (! $hasExplicitAcl || $storedAclHeader !== $snapshotAclHeader))
            || ($storedAclHeader !== null && $snapshotAclHeader !== $storedAclHeader)) {
            throw new InternalErrorException('Multipart upload ACL policy snapshot is inconsistent.');
        }
        if ($snapshotAclHeader !== null) {
            $cachedBucketOwner = $request->hasAttribute('s3.cachedBucketOwner')
                ? $request->getAttribute('s3.cachedBucketOwner')
                : null;
            $bucketOwner = is_string($cachedBucketOwner)
                ? $cachedBucketOwner
                : $this->metadata->getBucketOwner((string) $request->getAttribute('s3.bucket'));
            try {
                $aclRequest = clone $request;
                foreach (array_keys($aclRequest->getHeaders()) as $header) {
                    $header = strtolower($header);
                    if ($header === 'x-amz-acl' || str_starts_with($header, 'x-amz-grant-')) {
                        $aclRequest->removeHeader($header);
                    }
                }
                $aclRequest->setHeader('x-amz-acl', $snapshotAclHeader);
                $expectedAclGrants = AclGrantResolver::fromHeaders(
                    $aclRequest,
                    $ownerId,
                    'object',
                    $bucketOwner,
                );
            } catch (\OpsFour\S3Server\Exception\InvalidArgumentException $error) {
                throw new InternalErrorException('Multipart upload ACL policy snapshot is invalid.', $error);
            }
            if ($expectedAclGrants === null || $aclGrants !== $expectedAclGrants) {
                throw new InternalErrorException('Multipart upload ACL policy snapshot is inconsistent.');
            }
        } elseif (! $hasExplicitAcl && $aclGrants !== AclGrantResolver::privateAcl($ownerId)) {
            throw new InternalErrorException('Multipart upload ACL policy snapshot is inconsistent.');
        }

        $snapshotSse = $headers['x-amz-server-side-encryption'] ?? null;
        if ($snapshotSse !== null
            && ($snapshotSse !== 'AES256' || ($uploadMetadata['__sse-algorithm'] ?? null) !== 'AES256')) {
            throw new InternalErrorException('Multipart upload encryption policy snapshot is inconsistent.');
        }

        $snapshotStorageClass = $headers['x-amz-storage-class'] ?? null;
        $effectiveStorageClass = $uploadMetadata['__mpu-storage-class'] ?? 'STANDARD';
        if ($snapshotStorageClass !== null) {
            try {
                $validatedStorageClass = \OpsFour\S3Server\Http\StorageClassValidator::requested($snapshotStorageClass);
            } catch (\OpsFour\S3Server\Exception\InvalidArgumentException $error) {
                throw new InternalErrorException('Multipart upload storage-class policy snapshot is invalid.', $error);
            }
            if ($validatedStorageClass !== $effectiveStorageClass) {
                throw new InternalErrorException('Multipart upload storage-class policy snapshot is inconsistent.');
            }
        } elseif ($effectiveStorageClass !== 'STANDARD') {
            throw new InternalErrorException('Multipart upload storage-class policy snapshot is inconsistent.');
        }

        $request->setAttribute('s3.multipartCompletionPolicyContext', [
            'tags' => $requestTags,
            'headers' => $headers,
            'hasAcl' => $hasExplicitAcl,
            'hasRetention' => isset($uploadMetadata['__object-lock-mode'])
                || isset($uploadMetadata['__object-lock-retain-until-date']),
            'hasLegalHold' => isset($uploadMetadata['__object-lock-legal-hold']),
        ]);
    }

    /** Recheck read authorization under a shared owner-lock transaction. */
    public function assertAllowedAtReadCommit(Request $request): void
    {
        $this->assertAllowedWithLockedPolicies($request, true);
    }

    /** Recheck ListBuckets identity policies under a shared owner-lock transaction. */
    public function assertListBucketsAllowedAtReadCommit(Request $request): void
    {
        $this->attachLockedNamedPolicies($request, true);
        $this->assertListBucketsAllowed($request, (string) $request->getAttribute('ownerId'));
    }

    private function assertAllowedWithLockedPolicies(Request $request, bool $shared): void
    {
        // Direct handler callers do not pass through this middleware. Keep
        // their established contract; HTTP requests carry the first decision.
        if (! $request->hasAttribute('s3.policyResult')) {
            return;
        }

        $this->attachLockedNamedPolicies($request, $shared);

        $this->handleRequest($request, new class ($this->metadata) implements RequestHandler {
            public function __construct(private readonly MetadataStore $metadata) {}

            public function handleRequest(Request $request): Response
            {
                return (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                    $request,
                    new class implements RequestHandler {
                        public function handleRequest(Request $request): Response
                        {
                            return new Response(204);
                        }
                    },
                );
            }
        });
    }

    private function attachLockedNamedPolicies(Request $request, bool $shared): void
    {
        $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
        if (! $credential instanceof Credential || $credential->policyNames === []) {
            return;
        }

        NamedPolicySnapshot::attach(
            $request,
            $shared && $this->metadata instanceof SharedAuthorizationLockStore
                ? $this->metadata->lockNamedPoliciesForRead($credential->policyNames)
                : $this->metadata->lockNamedPoliciesForUpdate($credential->policyNames),
        );
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        $key = $request->hasAttribute('s3.key') ? $request->getAttribute('s3.key') : null;
        if ($key === '') {
            $key = null;
        }
        $ownerId = $request->hasAttribute('ownerId') ? $request->getAttribute('ownerId') : '';
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;

        if ($operation === S3Operation::ListBuckets) {
            $this->assertListBucketsAllowed($request, (string) $ownerId);

            return $requestHandler->handleRequest($request);
        }

        if ($bucket === null || $bucket === '' || $operation === null) {
            return $requestHandler->handleRequest($request);
        }

        $bucketInfo = $this->metadata->getBucket($bucket);
        $bucketOwner = $bucketInfo?->ownerId;
        $expectedBucketOwner = $request->getHeader('x-amz-expected-bucket-owner');
        if ($operation !== S3Operation::CreateBucket
            && $expectedBucketOwner !== null
            && $bucketOwner !== null
            && $expectedBucketOwner !== $bucketOwner) {
            throw new AccessDeniedException('Expected bucket owner does not match.');
        }
        // Cache for downstream middleware (AclEnforcementMiddleware) to avoid re-querying.
        $request->setAttribute('s3.cachedBucketOwner', $bucketOwner);
        $request->setAttribute('s3.cachedBucketGeneration', $bucketInfo?->generation);
        if ($operation === S3Operation::PutBucketLogging
            && ($ownerId === '' || $bucketOwner !== $ownerId)) {
            throw new AccessDeniedException();
        }
        $credential = $request->hasAttribute('credential') ? $request->getAttribute('credential') : null;
        if ($credential instanceof Credential
            && $operation !== S3Operation::DeleteObjects
            && !$this->credentialScopeAllows($credential, $key, $request)) {
            throw new AccessDeniedException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $multipartPolicyContextError = null;
        if ($operation === S3Operation::CompleteMultipartUpload) {
            $contextInput = $request->hasAttribute(self::MULTIPART_POLICY_CONTEXT_INPUT_ATTRIBUTE)
                ? $request->getAttribute(self::MULTIPART_POLICY_CONTEXT_INPUT_ATTRIBUTE)
                : null;
            if (is_array($contextInput)) {
                // A commit-time metadata snapshot supersedes the ingress snapshot.
                if ($request->hasAttribute('s3.multipartCompletionPolicyContext')) {
                    $request->removeAttribute('s3.multipartCompletionPolicyContext');
                }
                try {
                    /** @var array<string, string> $uploadMetadata */
                    $uploadMetadata = $contextInput['metadata'];
                    /** @var list<array{key: string, value: string}> $tags */
                    $tags = $contextInput['tags'];
                    /** @var list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants */
                    $aclGrants = $contextInput['aclGrants'];
                    $this->attachMultipartCompletionContext($request, $uploadMetadata, $tags, $aclGrants);
                } catch (OperationAbortedException|InternalErrorException $error) {
                    $multipartPolicyContextError = $error;
                } catch (\Throwable $error) {
                    $multipartPolicyContextError = new InternalErrorException(
                        'Multipart upload policy options are invalid.',
                        $error,
                    );
                }
            } elseif (! $request->hasAttribute('s3.multipartCompletionPolicyContext')) {
                $upload = $this->metadata->getMultipartUpload($queryParams['uploadId'] ?? '');
                if ($upload !== null && $upload['bucket'] === $bucket && $upload['key_name'] === $key
                    && $upload['owner_id'] === $ownerId
                    && ($upload['bucket_generation'] ?? '') === $bucketInfo?->generation) {
                    try {
                        $uploadMetadata = $upload['user_metadata'];
                        /** @var list<array{key: string, value: string}> $tags */
                        $tags = isset($uploadMetadata['__mpu-tags'])
                            ? ObjectTagValidator::validate(json_decode($uploadMetadata['__mpu-tags'], true, 16, JSON_THROW_ON_ERROR), 10)
                            : [];
                        $aclGrants = array_key_exists(MultipartAclSnapshot::METADATA_KEY, $uploadMetadata)
                            ? MultipartAclSnapshot::decode(
                                $uploadMetadata[MultipartAclSnapshot::METADATA_KEY],
                                (string) $ownerId,
                            )
                            : AclGrantResolver::privateAcl((string) $ownerId);
                        $this->attachMultipartCompletionContext($request, $uploadMetadata, $tags, $aclGrants);
                    } catch (OperationAbortedException|InternalErrorException $error) {
                        $multipartPolicyContextError = $error;
                    } catch (\Throwable $error) {
                        $multipartPolicyContextError = new InternalErrorException(
                            'Multipart upload policy options are invalid.',
                            $error,
                        );
                    }
                }
            }
        }
        if ($operation === S3Operation::SelectObjectContent && array_key_exists('versionId', $queryParams)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'versionId is not supported for S3 Select.',
            );
        }
        $requestedVersionId = $operation->selectsObjectVersion() ? ($queryParams['versionId'] ?? null) : null;
        $objectOwner = null;
        if ($key !== null && $operation->usesExistingObjectOwner()) {
            $readOperation = in_array($operation, [
                S3Operation::GetObject, S3Operation::HeadObject, S3Operation::GetObjectAttributes,
                S3Operation::GetObjectAcl, S3Operation::GetObjectTagging,
                S3Operation::GetObjectRetention, S3Operation::GetObjectLegalHold,
                S3Operation::SelectObjectContent,
            ], true);
            $object = $readOperation
                ? AuthorizedObjectSnapshot::lookupForRead($this->metadata, $operation, $bucket, $key, $requestedVersionId)
                : ($requestedVersionId !== null
                    ? $this->metadata->getObjectMetadataByVersion($bucket, $key, $requestedVersionId)
                    : $this->metadata->getObjectMetadata($bucket, $key));
            if ($readOperation) {
                $request->setAttribute(AuthorizedObjectSnapshot::ATTRIBUTE, $object);
            }
            $objectOwner = $readOperation && $requestedVersionId === null && $object?->isDeleteMarker
                ? null
                : $object?->ownerId;
            if ($objectOwner !== null && BucketOwnership::isEnforced($this->metadata, $bucket)) {
                $objectOwner = $bucketOwner;
            }
            $request->setAttribute('s3.cachedObjectOwner', $objectOwner);
        }
        $action = self::operationToAction($operation);
        if ($requestedVersionId !== null) {
            $action = match ($operation) {
                S3Operation::GetObject, S3Operation::HeadObject,
                S3Operation::GetObjectAttributes => 's3:GetObjectVersion',
                S3Operation::DeleteObject => 's3:DeleteObjectVersion',
                S3Operation::GetObjectAcl => 's3:GetObjectVersionAcl',
                S3Operation::PutObjectAcl => 's3:PutObjectVersionAcl',
                S3Operation::GetObjectTagging => 's3:GetObjectVersionTagging',
                S3Operation::PutObjectTagging => 's3:PutObjectVersionTagging',
                S3Operation::DeleteObjectTagging => 's3:DeleteObjectVersionTagging',
                default => $action,
            };
        }
        $attributesAction = $operation === S3Operation::GetObjectAttributes
            ? ($requestedVersionId !== null ? 's3:GetObjectVersionAttributes' : 's3:GetObjectAttributes')
            : null;
        $resource = $key !== null
            ? "arn:aws:s3:::{$bucket}/{$key}"
            : "arn:aws:s3:::{$bucket}";

        $conditions = [];
        if ($ownerId !== '') {
            $conditions['aws:PrincipalArn'] = $ownerId;
        }
        $sourceIp = \OpsFour\S3Server\Security\RequestNetworkContext::sourceIp($request);
        if ($sourceIp !== null) {
            $conditions['aws:SourceIp'] = $sourceIp;
        }
        $conditions['aws:SecureTransport'] = \OpsFour\S3Server\Security\RequestNetworkContext::secureTransport($request) ? 'true' : 'false';

        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }

        if (isset($queryParams['prefix'])) {
            $conditions['s3:prefix'] = $queryParams['prefix'];
        }
        if (isset($queryParams['delimiter'])) {
            $conditions['s3:delimiter'] = $queryParams['delimiter'];
        }
        if (isset($queryParams['max-keys'])) {
            $conditions['s3:max-keys'] = $queryParams['max-keys'];
        }
        if ($requestedVersionId !== null) {
            $conditions['s3:VersionId'] = $requestedVersionId;
        }

        foreach (['x-amz-acl', 'x-amz-server-side-encryption', 'x-amz-storage-class'] as $header) {
            $value = $this->writeOptionHeader($request, $operation, $header);
            if ($value !== null && $value !== '') {
                $conditions['s3:' . $header] = $value;
            }
        }
        $conditions['aws:CurrentTime'] = gmdate(\DateTimeInterface::ATOM);
        AuthType::addToPolicyContext($request, $conditions);

        $requestTags = $this->requestObjectTags($request, $operation);
        foreach ($requestTags as $tagKey => $tagValue) {
            $conditions['s3:RequestObjectTag/' . $tagKey] = $tagValue;
        }

        if ($key !== null) {
            foreach (ExistingObjectTagResolver::forRequest(
                $this->metadata,
                $bucket,
                $key,
                $operation,
                $requestedVersionId,
            ) as $tag) {
                $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
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
                if ($policyName === '') {
                    continue;
                }
                $namedPolicy = NamedPolicySnapshot::get($request, $this->metadata, $policyName);
                if ($namedPolicy === null) {
                    throw new AccessDeniedException('Named policy attached to credential does not exist.');
                }
                $identityPolicies[] = $namedPolicy;
            }
        }

        $identityResult = self::evaluatePolicies($identityPolicies, $action, $resource, $ownerId, $conditions);
        if ($identityResult === 'Deny') {
            throw new AccessDeniedException();
        }
        $attributesIdentityResult = $attributesAction !== null
            ? self::evaluatePolicies($identityPolicies, $attributesAction, $resource, $ownerId, $conditions)
            : null;
        if ($attributesIdentityResult === 'Deny') {
            throw new AccessDeniedException();
        }

        if ($operation === S3Operation::CreateBucket
            && $request->getHeader('x-amz-bucket-object-lock-enabled') === 'true') {
            foreach (['s3:PutBucketVersioning', 's3:PutBucketObjectLockConfiguration'] as $additionalAction) {
                if (self::evaluatePolicies($identityPolicies, $additionalAction, $resource, $ownerId, $conditions) === 'Deny') {
                    throw new AccessDeniedException();
                }
            }
        }

        if ($operation === S3Operation::CreateBucket) {
            $additionalActions = [];
            if ($request->getHeader('x-amz-object-ownership') !== null) {
                $additionalActions[] = 's3:PutBucketOwnershipControls';
            }
            if ($request->getHeader('x-amz-acl') !== null) {
                $additionalActions[] = 's3:PutBucketAcl';
            } else {
                foreach ($request->getHeaders() as $header => $_values) {
                    if (str_starts_with(strtolower($header), 'x-amz-grant-')) {
                        $additionalActions[] = 's3:PutBucketAcl';
                        break;
                    }
                }
            }
            foreach ($additionalActions as $additionalAction) {
                if (self::evaluatePolicies($identityPolicies, $additionalAction, $resource, $ownerId, $conditions) === 'Deny') {
                    throw new AccessDeniedException();
                }
            }
        }

        $policyJson = $this->metadata->getBucketPolicy($bucket);
        // Allow only the bucket owner to remove a legacy invalid policy that
        // would otherwise deny every request, including policy deletion.
        $recoveringInvalidPolicy = $operation === S3Operation::DeleteBucketPolicy
            && $ownerId !== ''
            && $ownerId === $bucketOwner
            && $policyJson !== null
            && PolicyEvaluator::hasUnsupportedConditionsInDocument($policyJson);
        $resourceResult = $policyJson !== null && ! $recoveringInvalidPolicy
            ? PolicyEvaluator::evaluate($policyJson, $action, $resource, $ownerId, $conditions)
            : 'Neutral';
        $attributesResourceResult = $attributesAction !== null && $policyJson !== null
            ? PolicyEvaluator::evaluate($policyJson, $attributesAction, $resource, $ownerId, $conditions)
            : null;

        // Explicit resource-policy deny also applies to the bucket owner.
        if ($resourceResult === 'Deny' || $attributesResourceResult === 'Deny') {
            throw new AccessDeniedException();
        }

        // A bucket policy cannot grant access to an object owned by another
        // account. Its explicit Deny still applies in bucket context.
        if ($objectOwner !== null && $objectOwner !== $bucketOwner) {
            $resourceResult = 'Neutral';
            if ($attributesResourceResult !== null) {
                $attributesResourceResult = 'Neutral';
            }
        }

        // Attributes need both read and attributes actions. Object READ ACLs
        // grant the base GetObject action but do not grant the attributes
        // action, so a non-owner needs an explicit bucket-policy allow for it.
        if ($attributesAction !== null && $ownerId !== ''
            && ($bucketOwner !== $ownerId || ($objectOwner !== null && $objectOwner !== $ownerId))
            && $attributesIdentityResult !== 'Allow') {
            throw new AccessDeniedException();
        }
        if ($attributesAction !== null
            && ($ownerId === '' || ($objectOwner ?? $bucketOwner) !== $ownerId)
            && $attributesResourceResult !== 'Allow') {
            throw new AccessDeniedException();
        }

        $this->assertAdditionalWriteActions($request, $operation, $bucket, $key, $requestTags);

        $canBypassGovernance = false;
        if (strtolower($request->getHeader('x-amz-bypass-governance-retention') ?? '') === 'true') {
            $bypassIdentityResult = self::evaluatePolicies(
                $identityPolicies,
                's3:BypassGovernanceRetention',
                $resource,
                $ownerId,
                $conditions,
            );
            $bypassResourceResult = $policyJson !== null
                ? PolicyEvaluator::evaluate(
                    $policyJson,
                    's3:BypassGovernanceRetention',
                    $resource,
                    $ownerId,
                    $conditions,
                )
                : 'Neutral';
            $isBucketOwner = $ownerId !== '' && $bucketOwner === $ownerId;
            $canBypassGovernance = $bypassIdentityResult !== 'Deny'
                && $bypassResourceResult !== 'Deny'
                && ($isBucketOwner || ($bypassIdentityResult === 'Allow' && $bypassResourceResult === 'Allow'));
        }
        $request->setAttribute('s3.canBypassGovernanceRetention', $canBypassGovernance);

        // A public bucket policy activates RestrictPublicBuckets for the whole
        // bucket. Foreign and anonymous callers must not bypass it through an
        // ACL merely because the public-policy statement does not match this
        // particular request.
        if ($policyJson !== null) {
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null
                && $pab['restrictPublicBuckets']
                && PolicyEvaluator::isPublicPolicy($policyJson)
                && ($ownerId === '' || $bucketOwner !== $ownerId)) {
                throw new AccessDeniedException();
            }
        }

        // Existing object operations use the object owner for implicit access.
        // Bucket operations and new writes use the bucket owner.
        $resourceOwner = $objectOwner ?? $bucketOwner;
        // Object ownership inside someone else's bucket does not grant the
        // requester's account an IAM permission to act in that bucket.
        // DeleteObjects applies the same rule to each requested key.
        if ($ownerId !== '' && $bucketOwner !== null
            && ($ownerId !== $bucketOwner || ($resourceOwner !== null && $ownerId !== $resourceOwner))
            && $operation !== S3Operation::DeleteObjects && $identityResult !== 'Allow') {
            throw new AccessDeniedException();
        }
        if ($ownerId !== '' && $resourceOwner !== null && $resourceOwner === $ownerId) {
            $request->setAttribute(
                's3.policyResult',
                $identityResult === 'Allow' || $resourceResult === 'Allow' ? 'Allow' : 'OwnerBypass',
            );

            return $this->dispatchAuthorizedRequest($request, $requestHandler, $multipartPolicyContextError);
        }

        // An identity policy owned by another account cannot grant access to
        // this bucket by itself. Resource policy or ACL permission must come
        // from the bucket side; identity policies still provide explicit Deny.
        $result = $resourceResult;

        if ($result === 'Deny') {
            throw new AccessDeniedException();
        }

        $request->setAttribute('s3.policyResult', $result);

        return $this->dispatchAuthorizedRequest($request, $requestHandler, $multipartPolicyContextError);
    }

    private function dispatchAuthorizedRequest(
        Request $request,
        RequestHandler $requestHandler,
        OperationAbortedException|InternalErrorException|null $multipartPolicyContextError,
    ): Response {
        if ($multipartPolicyContextError !== null) {
            // Complete the ordinary authorization boundary before exposing
            // whether an owned multipart upload has legacy or corrupt context.
            (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                $request,
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        return new Response(204);
                    }
                },
            );

            throw $multipartPolicyContextError;
        }

        return $requestHandler->handleRequest($request);
    }

    private function assertListBucketsAllowed(Request $request, string $ownerId): void
    {
        if ($ownerId === '') {
            throw new AccessDeniedException();
        }

        $conditions = [
            'aws:PrincipalArn' => $ownerId,
            'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
            'aws:SecureTransport' => \OpsFour\S3Server\Security\RequestNetworkContext::secureTransport($request) ? 'true' : 'false',
        ];
        $sourceIp = \OpsFour\S3Server\Security\RequestNetworkContext::sourceIp($request);
        if ($sourceIp !== null) {
            $conditions['aws:SourceIp'] = $sourceIp;
        }
        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }
        AuthType::addToPolicyContext($request, $conditions);

        $policies = [];
        $accountPolicy = $this->metadata->getAccountPolicy($ownerId);
        if ($accountPolicy !== null) {
            $policies[] = $accountPolicy;
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

        if (self::evaluatePolicies($policies, 's3:ListAllMyBuckets', '*', $ownerId, $conditions) === 'Deny') {
            throw new AccessDeniedException();
        }
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
            if (str_starts_with($target, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $requestTags */
    private function assertAdditionalWriteActions(Request $request, S3Operation $operation, string $bucket, ?string $key, array $requestTags): void
    {
        if ($key === null || !in_array($operation, [
            S3Operation::PutObject,
            S3Operation::PostObject,
            S3Operation::CopyObject,
            S3Operation::CreateMultipartUpload,
            S3Operation::CompleteMultipartUpload,
        ], true)) {
            return;
        }

        $authorizer = new AdditionalActionAuthorizer($this->metadata);
        $completion = $operation === S3Operation::CompleteMultipartUpload
            && $request->hasAttribute('s3.multipartCompletionPolicyContext')
            ? $request->getAttribute('s3.multipartCompletionPolicyContext') : null;
        $completion = is_array($completion) ? $completion : null;
        if ($operation === S3Operation::CompleteMultipartUpload && $completion === null) {
            // Completion-time headers cannot replace the initiation snapshot.
            return;
        }
        $conditionHeaders = $completion['headers'] ?? null;
        $tags = [];
        foreach ($requestTags as $tagKey => $tagValue) {
            $tags[] = ['key' => $tagKey, 'value' => $tagValue];
        }
        if ($tags !== [] && $operation !== S3Operation::CopyObject) {
            $authorizer->assertAllowed($request, $bucket, $key, 's3:PutObjectTagging', $tags, $conditionHeaders);
        }
        $hasAcl = $completion !== null ? $completion['hasAcl'] : (
            $request->getHeader('x-amz-acl') !== null
            || $request->getHeader('x-amz-grant-read') !== null
            || $request->getHeader('x-amz-grant-write') !== null
            || $request->getHeader('x-amz-grant-full-control') !== null
            || $request->getHeader('x-amz-grant-read-acp') !== null
            || $request->getHeader('x-amz-grant-write-acp') !== null
        );
        if ($hasAcl) {
            $authorizer->assertAllowed($request, $bucket, $key, 's3:PutObjectAcl', $tags, $conditionHeaders);
        }
        $hasRetention = $completion !== null ? $completion['hasRetention'] : (
            $request->getHeader('x-amz-object-lock-mode') !== null
            || $request->getHeader('x-amz-object-lock-retain-until-date') !== null
        );
        if ($hasRetention) {
            $authorizer->assertAllowed($request, $bucket, $key, 's3:PutObjectRetention', $tags, $conditionHeaders);
        }
        $hasLegalHold = $completion !== null
            ? $completion['hasLegalHold']
            : $request->getHeader('x-amz-object-lock-legal-hold') !== null;
        if ($hasLegalHold) {
            $authorizer->assertAllowed($request, $bucket, $key, 's3:PutObjectLegalHold', $tags, $conditionHeaders);
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestObjectTags(Request $request, S3Operation $operation): array
    {
        $completion = $operation === S3Operation::CompleteMultipartUpload
            && $request->hasAttribute('s3.multipartCompletionPolicyContext')
            ? $request->getAttribute('s3.multipartCompletionPolicyContext') : null;
        if (is_array($completion)) {
            return $completion['tags'];
        }
        if ($operation === S3Operation::CompleteMultipartUpload) {
            return [];
        }
        $tags = [];

        $taggingHeader = $request->getHeader('x-amz-tagging');
        // CopyObject ignores this header unless tagging is explicitly replaced.
        // An ignored value must not satisfy a RequestObjectTag condition.
        $usesTaggingHeader = $operation !== S3Operation::CopyObject
            || strtoupper($request->getHeader('x-amz-tagging-directive') ?? 'COPY') === 'REPLACE';
        if ($usesTaggingHeader && $taggingHeader !== null && $taggingHeader !== '') {
            foreach (ObjectTagValidator::parseHeader($taggingHeader) as $tag) {
                $tags[$tag['key']] = $tag['value'];
            }
        }

        if ($operation === S3Operation::PutObjectTagging) {
            $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 262_144);
            $request->setBody($body);
            foreach (ObjectTagValidator::validate(XmlRequestParser::parseTagging($body), 10) as $tag) {
                $tags[$tag['key']] = $tag['value'];
            }
        }

        return $tags;
    }

    private function writeOptionHeader(Request $request, S3Operation $operation, string $header): ?string
    {
        $completion = $operation === S3Operation::CompleteMultipartUpload
            && $request->hasAttribute('s3.multipartCompletionPolicyContext')
            ? $request->getAttribute('s3.multipartCompletionPolicyContext') : null;
        if (is_array($completion)) {
            return $completion['headers'][$header] ?? null;
        }
        if ($operation === S3Operation::CompleteMultipartUpload) {
            return null;
        }

        return $request->getHeader($header);
    }

    /**
     * Map an S3Operation enum to the IAM action string.
     */
    public static function operationToAction(S3Operation $operation): string
    {
        return match ($operation) {
            S3Operation::GetObject, S3Operation::HeadObject => 's3:GetObject',
            S3Operation::PutObject, S3Operation::PostObject => 's3:PutObject',
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
            S3Operation::GetObjectLockConfig => 's3:GetBucketObjectLockConfiguration',
            S3Operation::PutObjectLockConfig => 's3:PutBucketObjectLockConfiguration',
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
            S3Operation::DeletePublicAccessBlock => 's3:PutBucketPublicAccessBlock',
            S3Operation::RestoreObject => 's3:RestoreObject',
            S3Operation::SelectObjectContent => 's3:GetObject',
            S3Operation::GetObjectAttributes => 's3:GetObject',
            S3Operation::GetBucketPolicyStatus => 's3:GetBucketPolicyStatus',
            S3Operation::GetBucketLogging => 's3:GetBucketLogging',
            S3Operation::PutBucketLogging => 's3:PutBucketLogging',
            S3Operation::GetBucketOwnershipControls => 's3:GetBucketOwnershipControls',
            S3Operation::PutBucketOwnershipControls, S3Operation::DeleteBucketOwnershipControls => 's3:PutBucketOwnershipControls',
        };
    }
}
