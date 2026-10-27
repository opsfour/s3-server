<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\EntityTooSmallException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Exception\InvalidPartException;
use OpsFour\S3Server\Exception\InvalidPartOrderException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Xml\XmlRequestParser;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class CompleteMultipartUploadHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly bool $enforceMinPartSize = false,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly float $intentLeaseSeconds = 43_200.0,
        private readonly float $intentRenewalSeconds = 300.0,
        private readonly bool $requireLocalFencedCompletion = false,
        private readonly ?string $localFencedCompletionTier = null,
    ) {
        if (! is_finite($this->intentLeaseSeconds) || ! is_finite($this->intentRenewalSeconds)
            || $this->intentLeaseSeconds <= 0.0 || $this->intentRenewalSeconds <= 0.0
            || $this->intentRenewalSeconds * 3 > $this->intentLeaseSeconds) {
            throw new \InvalidArgumentException('Multipart completion renewal interval must fit within its lease.');
        }
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
        foreach (array_keys($request->getHeaders()) as $header) {
            $header = strtolower($header);
            if ($header === 'x-amz-sdk-checksum-algorithm' || str_starts_with($header, 'x-amz-checksum-')) {
                throw new \OpsFour\S3Server\Exception\NotImplementedException(
                    'Multipart additional checksums are not supported.',
                );
            }
        }
        $declaredObjectSize = self::declaredObjectSize(
            RequestHeaderValues::singleton($request, 'x-amz-mp-object-size'),
        );
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';

        $upload = $this->metadata->getMultipartUpload($uploadId);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }
        if (($upload['bucket_generation'] ?? '') !== $bucketInfo->generation) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Multipart upload belongs to an unverified bucket generation.',
            );
        }
        $ownershipSnapshot = MultipartDestinationOwnershipSnapshot::decode($upload['user_metadata']);
        if ($ownershipSnapshot !== null) {
            $ownershipSnapshot->assertConsistent($ownerId, $bucketInfo->ownerId);
        }
        $objectLockConfig = $this->metadata->getObjectLockConfig($bucket);
        $configurationFingerprint = $upload['user_metadata'][ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY] ?? null;
        if (is_string($configurationFingerprint)) {
            ObjectLockConfigurationSnapshot::assertFingerprint($configurationFingerprint, $objectLockConfig);
        } elseif (isset($upload['user_metadata']['__object-lock-mode'])
            || isset($upload['user_metadata']['__object-lock-retain-until-date'])
            || isset($upload['user_metadata']['__object-lock-legal-hold'])
            || self::hasDefaultObjectLockRetention($objectLockConfig)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Object Lock multipart upload must be reinitiated with checksum protection.',
            );
        }
        ObjectLockConfigurationSnapshot::capture($request, $objectLockConfig);
        $uploadTier = MultipartUploadTierResolver::resolve($upload, $this->storageTiers);
        $uploadStorage = $uploadTier->backend;
        $isLocalFilesystem = self::isLocalFilesystem($uploadStorage);
        $boundLocalTierSelected = $this->requireLocalFencedCompletion
            && ($this->localFencedCompletionTier === null
                || $this->localFencedCompletionTier === $uploadTier->name);
        $useLocalFencedCompletion = $boundLocalTierSelected && $isLocalFilesystem;
        if ($this->requireLocalFencedCompletion && $boundLocalTierSelected !== $isLocalFilesystem) {
            throw new InternalErrorException('Local multipart completion fence is not bound to this upload tier.');
        }
        if ($useLocalFencedCompletion && ! $this->metadata instanceof LocalMultipartCompletionIntentStore) {
            throw new InternalErrorException('Local multipart completion requires a qualified filesystem backend and fenced metadata store.');
        }
        $storageTier = $uploadTier->name;
        $uploadEncryptionMode = $upload['user_metadata']['__sse-algorithm'] ?? null;
        $this->assertPlainUploadAllowed($bucket, $uploadEncryptionMode);
        $partFormat = $upload['user_metadata']['__mpu-part-format'] ?? null;
        $encryptedV2 = $partFormat === EncryptedMultipartPartCodec::FORMAT;
        if ($partFormat !== null && $partFormat !== EncryptedMultipartPartCodec::PLAINTEXT_FORMAT && ! $encryptedV2) {
            throw new InternalErrorException('Unknown multipart part format.');
        }
        if ($uploadEncryptionMode !== null && ! $encryptedV2) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Legacy encrypted multipart upload must be aborted.');
        }
        if ($uploadEncryptionMode !== null) {
            EncryptionRequestResolver::requireEncryptionService($this->encryption, $uploadEncryptionMode);
        }
        $completionCustomerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $uploadEncryptionMode === 'SSE-C',
            $upload['user_metadata']['__sse-customer-key-md5'] ?? null,
        );
        if ($uploadEncryptionMode !== 'SSE-C' && $completionCustomerKey !== null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'SSE-C headers are not valid for this multipart upload.',
            );
        }
        // Parse XML body for parts list.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 2_097_152);
        $requestedParts = XmlRequestParser::parseCompleteMultipartUpload($body);

        // Get stored parts from metadata.
        $storedParts = $this->metadata->getParts($uploadId);
        $storedPartsMap = [];
        foreach ($storedParts as $sp) {
            $storedPartsMap[$sp['part_number']] = $sp;
        }

        // Validate parts.
        $prevPartNumber = 0;
        $validatedParts = [];

        foreach ($requestedParts as $rp) {
            $partNumber = $rp['partNumber'];
            $etag = $rp['etag'];

            // Parts must be in ascending order.
            if ($partNumber <= $prevPartNumber) {
                throw new InvalidPartOrderException();
            }
            $prevPartNumber = $partNumber;

            // Part must exist in stored parts.
            if (! isset($storedPartsMap[$partNumber])) {
                throw new InvalidPartException(
                    'One or more of the specified parts could not be found.',
                );
            }

            // ETag must match.
            $storedEtag = $storedPartsMap[$partNumber]['etag'];
            $normalizedStoredEtag = self::multipartPartEtag($storedEtag);
            if ($normalizedStoredEtag === null) {
                throw new InternalErrorException('Part ETag is not a valid MD5 hex string.');
            }
            $requestedEtag = self::multipartPartEtag($etag);
            if ($requestedEtag === null) {
                throw new InvalidPartException(
                    'One or more of the specified parts could not be found.',
                );
            }
            if ($requestedEtag !== $normalizedStoredEtag) {
                throw new InvalidPartException(
                    'One or more of the specified parts could not be found.',
                );
            }

            $validatedParts[] = [
                'partNumber' => $partNumber,
                'etag' => $storedEtag,
                'size' => $storedPartsMap[$partNumber]['size'],
                'storagePath' => $storedPartsMap[$partNumber]['storage_path'],
            ];
        }

        // Reject an object larger than S3's 50,000 GiB limit before creating a
        // durable attempt or invoking any storage assembly. The subtraction
        // inside sumObjectBytes() keeps the accumulation overflow-safe.
        $expectedSize = MultipartPartSize::sumObjectBytes(array_column($validatedParts, 'size'));

        // Validate minimum part size: all parts except the last must be >= 5 MiB.
        if ($this->enforceMinPartSize) {
            $minPartSize = 5 * 1024 * 1024;
            $lastIndex = count($validatedParts) - 1;

            for ($i = 0; $i < $lastIndex; $i++) {
                if ($validatedParts[$i]['size'] < $minPartSize) {
                    throw new EntityTooSmallException(
                        'Your proposed upload is smaller than the minimum allowed object size. '
                        . 'Each part must be at least 5 MiB, except the last part.',
                    );
                }
            }
        }

        // Reject oversized encrypted uploads before assembly writes a
        // persistent plaintext object. Subtraction also avoids integer
        // overflow when summing part sizes from metadata.
        if ($uploadEncryptionMode !== null && $expectedSize > $this->maxEncryptedObjectSize) {
            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
            );
        }
        if ($declaredObjectSize !== null && $declaredObjectSize !== $expectedSize) {
            throw new InvalidRequestException(
                'The specified multipart object size does not match the selected parts.',
            );
        }

        $quotaPrincipalId = $ownershipSnapshot instanceof MultipartDestinationOwnershipSnapshot
            ? $ownershipSnapshot->quotaPrincipalId
            : (BucketOwnership::isEnforced($this->metadata, $bucket) ? $bucketInfo->ownerId : $ownerId);
        $this->assertExpectedSizeWithinQuota(
            $request,
            $bucketInfo,
            $key,
            $upload,
            $ownershipSnapshot,
            $expectedSize,
            $storageTier,
            $validatedParts,
        );

        // A malformed part ETag must fail before assembly creates storage
        // data. The ETag is also part of the durable attempt's part digest.
        $partMd5s = '';
        foreach ($validatedParts as $part) {
            $hexEtag = trim($part['etag'], '"');
            $binaryEtag = ctype_xdigit($hexEtag) ? hex2bin($hexEtag) : false;
            if ($binaryEtag === false || strlen($binaryEtag) !== 16) {
                throw new InternalErrorException('Part ETag is not a valid MD5 hex string.');
            }
            $partMd5s .= $binaryEtag;
        }
        $etag = '"' . md5($partMd5s) . '-' . count($validatedParts) . '"';

        if (! $this->metadata instanceof MultipartWriteIntentStore
            || ! $uploadStorage instanceof RecoverableMultipartAssemblyBackend
        ) {
            throw new InternalErrorException('Multipart completion requires durable write-intent capable backends.');
        }

        $attemptId = bin2hex(random_bytes(16));
        $plannedPaths = $uploadStorage->planMultipartCompletionPaths(
            $bucket,
            $key,
            $bucketInfo->generation,
            $attemptId,
        );
        $intentNow = $this->metadata->multipartWriteIntentNow();
        $intent = new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: $uploadId,
            bucket: $bucket,
            bucketGeneration: $bucketInfo->generation,
            ownerId: $ownerId,
            storageTier: $storageTier,
            assemblyPath: $plannedPaths['assembly'],
            finalPath: $plannedPaths['final'],
            partDigest: MultipartWriteIntent::digestParts($validatedParts),
            sseMode: $uploadEncryptionMode,
            leaseUntil: $intentNow + $this->intentLeaseSeconds,
            nextAttemptAt: $intentNow + $this->intentLeaseSeconds,
        );
        $this->metadata->transaction(function () use ($ownerId, $bucketInfo, $request, $uploadId, $bucket, $key, $validatedParts, $intent, $uploadEncryptionMode): void {
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            ObjectLockConfigurationSnapshot::assertCurrent(
                $request,
                $this->metadata->getObjectLockConfig($bucket),
            );
            $this->assertPlainUploadAllowed($bucket, $uploadEncryptionMode);
            $this->assertUploadIsActive($uploadId, $bucket, $key, $ownerId, $bucketInfo->generation, $intent->storageTier);
            $this->assertSelectedPartsUnchanged($uploadId, $validatedParts);
            \assert($this->metadata instanceof MultipartWriteIntentStore);
            $this->metadata->createMultipartWriteIntent($intent);
        });

        // The local filesystem publisher is create-only, but the one-shot SQL
        // token must be durable before a worker can attempt its hard link.
        // Arm before starting the heartbeat so its expected-lease CAS cannot
        // race with a renewal of the same intent.
        $localDispatchId = null;
        $currentLease = $intent->leaseUntil;
        if ($useLocalFencedCompletion) {
            if (! $this->metadata instanceof LocalMultipartCompletionIntentStore) {
                $this->abandonAttempt($intent);
                throw new InternalErrorException('Local multipart completion requires a fenced metadata store.');
            }
            $localDispatchId = bin2hex(random_bytes(16));
        }

        try {
            if ($localDispatchId !== null) {
                \assert($this->metadata instanceof LocalMultipartCompletionIntentStore);
                $nextLease = max($currentLease + 0.001, $this->metadata->multipartWriteIntentNow() + $this->intentLeaseSeconds);
                if (! $this->metadata->armMultipartWriteIntent($intent->attemptId, $localDispatchId, $currentLease, $nextLease)) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Local multipart completion attempt could not be armed.');
                }
                $currentLease = $nextLease;
            }

            // Renew while an asynchronous storage worker assembles or writes the
            // object. The database clock and CAS prevent renewal after Abort,
            // collector takeover, or expiry. Stop before the publish transaction.
            $heartbeat = new QueueLeaseHeartbeat(function (float $_) use ($intent, &$currentLease): bool {
                \assert($this->metadata instanceof MultipartWriteIntentStore);
                $nextLease = $this->metadata->multipartWriteIntentNow() + $this->intentLeaseSeconds;
                if (! $this->metadata->renewMultipartWriteIntent($intent->attemptId, $currentLease, $nextLease)) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart completion attempt lost its lease.');
                }
                $currentLease = $nextLease;

                return true;
            }, $this->intentRenewalSeconds);
        } catch (\Throwable $error) {
            if ($localDispatchId !== null && $this->metadata instanceof LocalMultipartCompletionIntentStore) {
                try {
                    $this->metadata->retireLocalMultipartWriteIntentBeforeDispatch($intent->attemptId, $localDispatchId);
                } catch (\Throwable) {
                    // Preserve the arming failure. The undispatched collector
                    // can still retire an unarmed attempt after its lease.
                }
            } else {
                try {
                    $this->abandonAttempt($intent);
                } catch (\Throwable) {
                    // Preserve the failure that prevented worker submission.
                }
            }

            throw $error;
        }

        // New SSE uploads authenticate each stored ciphertext part and encrypt
        // the final object directly. Legacy uploads retain their assembly path.
        $userMetadata = $upload['user_metadata'];
        $encMeta = [];
        try {
            if ($encryptedV2) {
                [$writeResult, $encMeta] = $this->completeEncryptedParts(
                    $uploadStorage,
                    $bucket,
                    $key,
                    $uploadId,
                    $bucketInfo->generation,
                    $validatedParts,
                    $expectedSize,
                    $etag,
                    $uploadEncryptionMode,
                    $completionCustomerKey,
                    $intent->finalPath,
                    $request,
                );
            } else {
                $local = $useLocalFencedCompletion ? self::localFilesystemBackend($uploadStorage) : null;
                if ($local !== null) {
                    if ($localDispatchId === null) {
                        throw new InternalErrorException('Local multipart assembly has no armed dispatch.');
                    }
                    $writeResult = $local->assembleMultipartUploadFencedAtPath(
                        $bucket,
                        $key,
                        $bucketInfo->generation,
                        $attemptId,
                        $uploadId,
                        $validatedParts,
                        $intent->assemblyPath,
                    );
                } else {
                    $writeResult = $uploadStorage->assembleMultipartUploadAtPath(
                        $bucket,
                        $key,
                        $uploadId,
                        $validatedParts,
                        $intent->assemblyPath,
                    );
                }
                if ($writeResult->path !== $intent->assemblyPath
                    || $writeResult->size !== $expectedSize
                    || ! hash_equals(trim($etag, '"'), strtolower($writeResult->md5Hex))) {
                    throw new InternalErrorException('Multipart assembly result does not match selected parts.');
                }
            }
            $heartbeat->stop();
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                $heartbeat->stop(...),
                fn() => $this->abandonAttempt($intent),
            );
            if ($e instanceof InternalErrorException && $this->metadata->getMultipartUpload($uploadId) === null) {
                throw new NoSuchUploadException();
            }
            throw $e;
        }

        // Merge encryption metadata (replace any stubs from CreateMultipartUpload).
        // First, remove old stub encryption keys from user metadata.
        foreach (array_keys($userMetadata) as $k) {
            if (str_starts_with($k, '__sse-')) {
                unset($userMetadata[$k]);
            }
        }
        foreach ($encMeta as $k => $v) {
            $userMetadata['__' . $k] = $v;
        }

        // Recover metadata fields stored during CreateMultipartUpload.
        $contentEncoding = $userMetadata['__mpu-content-encoding'] ?? null;
        $contentLanguage = $userMetadata['__mpu-content-language'] ?? null;
        $contentDisposition = $userMetadata['__mpu-content-disposition'] ?? null;
        $cacheControl = $userMetadata['__mpu-cache-control'] ?? null;
        $storageClass = $userMetadata['__mpu-storage-class'] ?? 'STANDARD';
        if ($contentLanguage !== null) {
            $userMetadata['__content-language'] = $contentLanguage;
        }
        $objectLockHeaders = [
            'mode' => $userMetadata['__object-lock-mode'] ?? null,
            'retainUntilDate' => $userMetadata['__object-lock-retain-until-date'] ?? null,
            'legalHold' => $userMetadata['__object-lock-legal-hold'] ?? null,
        ];
        try {
            /** @var list<array{key: string, value: string}> $tags */
            $tags = [];
            if (isset($userMetadata['__mpu-tags'])) {
                $decodedTags = json_decode($userMetadata['__mpu-tags'], true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($decodedTags)) {
                    throw new \UnexpectedValueException();
                }
                /** @var list<array{key: string, value: string}> $tags */
                $tags = $decodedTags;
            }

            $aclGrants = AclGrantResolver::privateAcl($ownerId);
            $encodedAclGrants = $userMetadata['__mpu-acl-grants'] ?? null;
            if ($encodedAclGrants !== null) {
                /** @var list<array{granteeType: string, granteeId: string, permission: string}> $decodedAclGrants */
                $decodedAclGrants = json_decode($encodedAclGrants, true, 16, JSON_THROW_ON_ERROR);
                $aclGrants = AclGrantResolver::validateGrants($decodedAclGrants);
            }
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                fn() => $this->abandonAttempt($intent),
            );
            throw new InternalErrorException('Multipart upload metadata is invalid.', $e);
        }

        // Remove internal __mpu-* keys from persisted user metadata.
        unset(
            $userMetadata['__mpu-content-encoding'],
            $userMetadata['__mpu-content-language'],
            $userMetadata['__mpu-content-disposition'],
            $userMetadata['__mpu-cache-control'],
            $userMetadata['__mpu-storage-class'],
            $userMetadata['__mpu-acl-grants'],
            $userMetadata['__mpu-acl-explicit'],
            $userMetadata['__mpu-acl-header'],
            $userMetadata[MultipartDestinationOwnershipSnapshot::METADATA_KEY],
            $userMetadata[PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY],
            $userMetadata[PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY],
            $userMetadata['__mpu-tags'],
            $userMetadata['__mpu-part-format'],
            $userMetadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY],
            $userMetadata['__object-lock-mode'],
            $userMetadata['__object-lock-retain-until-date'],
            $userMetadata['__object-lock-legal-hold'],
        );

        // Store final object metadata (versioning-aware).
        $versionId = null;
        $oldObjectToClean = null;
        $oldPathsToClean = [];
        $cleanupParts = [];
        $event = $this->notifications?->createEvent(
            's3:ObjectCreated:CompleteMultipartUpload',
            $bucket,
            $key,
            $writeResult->size,
            $etag,
            $ownerId,
            request: $request,
            bucketGeneration: $bucketInfo->generation,
        ) ?? new S3Event(
            's3:ObjectCreated:CompleteMultipartUpload',
            $bucket,
            $key,
            $writeResult->size,
            $etag,
            $ownerId,
        );

        try {
            $versioning = $this->metadata->getBucketVersioning($bucket);
            $observedReplacementOwnerId = null;
            if ($versioning !== 'Enabled') {
                $observedNullVersion = $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null');
                if ($observedNullVersion !== null && ! $observedNullVersion->isDeleteMarker) {
                    $observedReplacementOwnerId = $observedNullVersion->ownerId;
                }
            }
            if ($versioning === 'Enabled') {
                $this->metadata->transaction(function () use (
                    $bucket,
                    $bucketInfo,
                    $key,
                    $ownerId,
                    $writeResult,
                    $etag,
                    $upload,
                    $storageClass,
                    $contentEncoding,
                    $contentDisposition,
                    $cacheControl,
                    $userMetadata,
                    $aclGrants,
                    $ownershipSnapshot,
                    $quotaPrincipalId,
                    $request,
                    $tags,
                    $objectLockHeaders,
                    $uploadId,
                    $validatedParts,
                    $intent,
                    $localDispatchId,
                    &$event,
                    $versioning,
                    $uploadEncryptionMode,
                    &$versionId,
                    &$cleanupParts,
                ) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire(
                        $this->metadata,
                        $ownerId,
                        $bucketInfo->ownerId,
                        $quotaPrincipalId,
                    );
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                    $this->assertPlainUploadAllowed($bucket, $uploadEncryptionMode);
                    [$storedOwnerId, $storedAclGrants, $storedQuotaPrincipalId] = $this->destinationDecision(
                        $ownershipSnapshot,
                        $bucket,
                        $bucketInfo->ownerId,
                        $ownerId,
                        $aclGrants,
                    );
                    $this->assertCurrentPublicAclAllowed($bucket, $storedAclGrants);
                    if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the multipart upload was being committed.',
                        );
                    }
                    $this->assertUploadIsActive($uploadId, $bucket, $key, $ownerId, $bucketInfo->generation, $intent->storageTier, $upload['user_metadata']);
                    (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
                        $request,
                        $upload['user_metadata'],
                        $tags,
                        $aclGrants,
                    );
                    $this->assertSelectedPartsUnchanged($uploadId, $validatedParts);
                    $this->quotas?->assertCanWriteObject(
                        $storedQuotaPrincipalId,
                        $bucket,
                        null,
                        $writeResult->size,
                        true,
                        $upload['upload_id'],
                        $ownerId,
                    );

                    $versionId = $this->metadata->putObjectVersioned(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $storedOwnerId,
                        size: $writeResult->size,
                        etag: $etag,
                        contentType: $upload['content_type'],
                        storagePath: $writeResult->path,
                        storageClass: $storageClass,
                        contentEncoding: $contentEncoding,
                        contentDisposition: $contentDisposition,
                        cacheControl: $cacheControl,
                        userMetadata: $userMetadata,
                    );
                    $this->metadata->updateObjectPlacement(
                        $bucket,
                        $key,
                        $versionId,
                        $storageClass,
                        $intent->storageTier,
                        $writeResult->path,
                    );
                    $this->metadata->putAcl('object', $bucket . '/' . $key, $storedOwnerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($bucket, $key, $versionId),
                        $storedOwnerId,
                        $storedAclGrants,
                    );
                    $this->metadata->putObjectTagging($bucket, $key, $tags, $versionId);
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $bucket,
                        $key,
                        $versionId,
                        $objectLockHeaders,
                    );
                    $event = $event->withVersionId($versionId);
                    $this->notifications?->enqueueWebhooks($event);
                    $cleanupParts = MultipartCleanup::stage(
                        $this->metadata,
                        $bucket,
                        $key,
                        $uploadId,
                        $ownerId,
                        $intent->storageTier,
                        $bucketInfo->generation,
                    );
                    if (! $this->consumeCompletionIntent($intent, $localDispatchId)) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart completion attempt expired.');
                    }
                });
            } else {
                // Wrap read-old + write-new in a transaction to prevent concurrent
                // overwrites from orphaning storage files.
                $this->metadata->transaction(function () use (
                    $bucket,
                    $bucketInfo,
                    $key,
                    $ownerId,
                    $writeResult,
                    $etag,
                    $upload,
                    $storageClass,
                    $contentEncoding,
                    $contentDisposition,
                    $cacheControl,
                    $userMetadata,
                    $aclGrants,
                    $ownershipSnapshot,
                    $quotaPrincipalId,
                    $request,
                    $tags,
                    $uploadId,
                    $validatedParts,
                    $intent,
                    $localDispatchId,
                    $event,
                    $versioning,
                    $observedReplacementOwnerId,
                    $uploadEncryptionMode,
                    &$oldObjectToClean,
                    &$oldPathsToClean,
                    &$cleanupParts,
                ) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire(
                        $this->metadata,
                        $ownerId,
                        $bucketInfo->ownerId,
                        $quotaPrincipalId,
                        $observedReplacementOwnerId ?? '',
                    );
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                    $this->assertPlainUploadAllowed($bucket, $uploadEncryptionMode);
                    [$storedOwnerId, $storedAclGrants, $storedQuotaPrincipalId] = $this->destinationDecision(
                        $ownershipSnapshot,
                        $bucket,
                        $bucketInfo->ownerId,
                        $ownerId,
                        $aclGrants,
                    );
                    $this->assertCurrentPublicAclAllowed($bucket, $storedAclGrants);
                    if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the multipart upload was being committed.',
                        );
                    }
                    $this->assertUploadIsActive($uploadId, $bucket, $key, $ownerId, $bucketInfo->generation, $intent->storageTier, $upload['user_metadata']);
                    (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
                        $request,
                        $upload['user_metadata'],
                        $tags,
                        $aclGrants,
                    );
                    $this->assertSelectedPartsUnchanged($uploadId, $validatedParts);
                    $nullVersion = $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null');
                    $oldObjectToClean = $nullVersion?->isDeleteMarker === true ? null : $nullVersion;
                    if ($oldObjectToClean !== null && (
                        $oldObjectToClean->ownerId === ''
                        || ! in_array(
                            $oldObjectToClean->ownerId,
                            [$ownerId, $bucketInfo->ownerId, $quotaPrincipalId, $observedReplacementOwnerId],
                            true,
                        )
                    )) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'The destination owner changed while the multipart upload was being committed.',
                        );
                    }
                    if ($oldObjectToClean !== null) {
                        (new \OpsFour\S3Server\ObjectLock\ObjectLockChecker($this->metadata))
                            ->checkProtection($bucket, $key, 'null');
                        if ($oldObjectToClean->restoredStoragePath !== null) {
                            \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($oldObjectToClean, $this->storageTiers);
                        }
                    }

                    $this->quotas?->assertCanWriteObject(
                        $storedQuotaPrincipalId,
                        $bucket,
                        $oldObjectToClean,
                        $writeResult->size,
                        false,
                        $upload['upload_id'],
                        $ownerId,
                    );

                    $this->metadata->putObjectMetadata(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $storedOwnerId,
                        size: $writeResult->size,
                        etag: $etag,
                        contentType: $upload['content_type'],
                        storagePath: $writeResult->path,
                        storageClass: $storageClass,
                        contentEncoding: $contentEncoding,
                        contentDisposition: $contentDisposition,
                        cacheControl: $cacheControl,
                        userMetadata: $userMetadata,
                    );
                    $this->metadata->updateObjectPlacement(
                        $bucket,
                        $key,
                        null,
                        $storageClass,
                        $intent->storageTier,
                        $writeResult->path,
                    );
                    $this->metadata->putAcl('object', $bucket . '/' . $key, $storedOwnerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($bucket, $key, null),
                        $storedOwnerId,
                        $storedAclGrants,
                    );
                    $this->metadata->putObjectTagging($bucket, $key, $tags);
                    (new ObjectLockRequestApplier($this->metadata))->apply($request, $bucket, $key, null);
                    $this->notifications?->enqueueWebhooks($event);
                    if ($oldObjectToClean !== null) {
                        $oldPathsToClean = $this->stageReplacedData($oldObjectToClean, $intent->storageTier, $writeResult->path);
                    }
                    $cleanupParts = MultipartCleanup::stage(
                        $this->metadata,
                        $bucket,
                        $key,
                        $uploadId,
                        $ownerId,
                        $intent->storageTier,
                        $bucketInfo->generation,
                    );
                    if (! $this->consumeCompletionIntent($intent, $localDispatchId)) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart completion attempt expired.');
                    }
                });
            }
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $e,
                fn() => $this->abandonAttempt($intent),
            );
            throw $e;
        }

        // Clean up old storage file on overwrite (non-versioned only).
        $this->deleteStagedData($bucket, $oldPathsToClean);

        MultipartCleanup::clean(
            $this->metadata,
            $uploadStorage,
            $bucket,
            $key,
            $uploadId,
            $intent->storageTier,
            $cleanupParts,
        );

        // Dispatch event notification.
        $this->notifications?->dispatchInternalEvent($event);

        // Build response.
        $location = (string) $request->getUri()->withQuery('')->withFragment('');

        $xml = XmlResponseBuilder::completeMultipartUploadResult($location, $bucket, $key, $etag);

        $responseHeaders = ['Content-Type' => 'application/xml'];

        if ($versioning === 'Suspended') {
            $responseHeaders['x-amz-version-id'] = 'null';
        } elseif ($versionId !== null) {
            $responseHeaders['x-amz-version-id'] = $versionId;
        }

        // Encryption response headers.
        $sseAlgo = $encMeta['sse-algorithm'] ?? null;
        if ($sseAlgo === 'SSE-C') {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $responseHeaders['x-amz-server-side-encryption-customer-key-MD5'] = $encMeta['sse-customer-key-md5'];
        } elseif ($sseAlgo === 'AES256') {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
            body: $xml,
        );
    }

    /** @param array<string, mixed>|null $config */
    private static function hasDefaultObjectLockRetention(?array $config): bool
    {
        return ($config['objectLockEnabled'] ?? null) === 'Enabled'
            && isset($config['rule']['defaultRetention']);
    }

    private static function multipartPartEtag(string $etag): ?string
    {
        foreach (['/\A"([0-9a-f]{32})"\z/D', '/\A([0-9a-f]{32})\z/D'] as $pattern) {
            if (preg_match($pattern, $etag, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private static function declaredObjectSize(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || ! ctype_digit($value)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'x-amz-mp-object-size must be a non-negative decimal integer.',
            );
        }

        $digits = ltrim($value, '0');
        $max = (string) MultipartPartSize::MAX_OBJECT_BYTES;
        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                'Expected multipart object size exceeds the 50,000 GiB maximum.',
            );
        }

        return (int) $value;
    }

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts
     * @return array{StorageWriteResult, array<string, string>}
     */
    private function completeEncryptedParts(
        RecoverableMultipartAssemblyBackend $storage,
        string $bucket,
        string $key,
        string $uploadId,
        string $bucketGeneration,
        array $parts,
        int $expectedSize,
        string $etag,
        ?string $mode,
        ?string $customerKey,
        string $finalPath,
        Request $request,
    ): array {
        if ($mode === null || $this->encryption === null) {
            throw new InternalErrorException('Encrypted multipart completion is unavailable.');
        }
        $lock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
        $completionCancellation = null;
        try {
            BufferedSsePayload::assertMemoryHeadroom($expectedSize);
            $plaintext = '';
            foreach ($parts as $part) {
                $stream = $storage->getObjectByPath($part['storagePath']);
                $readCancellation = null;
                try {
                    [$stored] = BufferedSsePayload::read(
                        $stream,
                        $part['size'] + EncryptedMultipartPartCodec::MAX_OVERHEAD,
                    );
                } catch (\Amp\CancelledException $error) {
                    $readCancellation = $error;
                    throw $error;
                } finally {
                    \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                        $readCancellation,
                        $stream->close(...),
                    );
                }
                $partPlaintext = EncryptedMultipartPartCodec::decode(
                    $this->encryption,
                    $mode,
                    $stored,
                    $customerKey,
                    $bucketGeneration,
                    $bucket,
                    $uploadId,
                    $part['partNumber'],
                );
                if (strlen($partPlaintext) !== $part['size']
                    || ! hash_equals(trim($part['etag'], '"'), md5($partPlaintext))) {
                    throw new InternalErrorException('Encrypted multipart part does not match its metadata.');
                }
                $plaintext .= $partPlaintext;
            }
            if (strlen($plaintext) !== $expectedSize) {
                throw new InternalErrorException('Encrypted multipart object size does not match its parts.');
            }

            if ($mode === EncryptionRequestResolver::SSE_C) {
                if ($customerKey === null) {
                    throw new InternalErrorException('Multipart customer key is unavailable.');
                }
                $encrypted = $this->encryption->encryptSseC($plaintext, $customerKey);
                $customerKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                if ($customerKeyMd5 === null) {
                    throw new InternalErrorException('Multipart customer key digest is unavailable.');
                }
                $encMeta = [
                    'sse-algorithm' => 'SSE-C',
                    'sse-iv' => $encrypted['iv'],
                    'sse-tag' => $encrypted['tag'],
                    'sse-customer-key-md5' => $customerKeyMd5,
                ];
            } elseif ($mode === EncryptionRequestResolver::SSE_S3) {
                $encrypted = $this->encryption->encryptSseS3($plaintext);
                $encMeta = [
                    'sse-algorithm' => 'AES256',
                    'sse-key' => $encrypted['encryptedDataKey'],
                    'sse-iv' => $encrypted['iv'],
                    'sse-tag' => $encrypted['tag'],
                ];
            } else {
                throw new InternalErrorException('Unsupported encrypted multipart mode.');
            }
            $ciphertext = $encrypted['ciphertext'];
            if (strlen($ciphertext) !== $expectedSize) {
                throw new InternalErrorException('Encrypted multipart final payload size is invalid.');
            }
            $physical = $storage->putObjectAtPath(
                $bucket,
                $key,
                new \Amp\ByteStream\ReadableBuffer($ciphertext),
                $finalPath,
            );
            if ($physical->path !== $finalPath || $physical->size !== strlen($ciphertext)
                || ! hash_equals(md5($ciphertext), strtolower($physical->md5Hex))) {
                throw new InternalErrorException('Encrypted multipart final write does not match its planned payload.');
            }

            return [new StorageWriteResult($finalPath, $expectedSize, trim($etag, '"')), $encMeta];
        } catch (\Amp\CancelledException $error) {
            $completionCancellation = $error;
            throw $error;
        } finally {
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                $completionCancellation,
                $lock->release(...),
            );
        }
    }

    /** @return list<array{tier: string, path: string}> */
    private function stageReplacedData(ObjectInfo $object, string $preserveTier, string $preservePath): array
    {
        $paths = [];
        $path = $object->systemMetadata['storagePath'] ?? null;
        if ($path !== null && $path !== '' && ($path !== $preservePath || $object->storageTier !== $preserveTier)) {
            $this->storageTiers->tier($object->storageTier);
            $paths[] = ['tier' => $object->storageTier, 'path' => $path];
        }

        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            $restoredTier = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($object, $this->storageTiers);
            if ($object->restoredStoragePath !== $preservePath || $restoredTier->name !== $preserveTier) {
                $duplicate = false;
                foreach ($paths as $candidate) {
                    if ($candidate['tier'] === $restoredTier->name && $candidate['path'] === $object->restoredStoragePath) {
                        $duplicate = true;
                        break;
                    }
                }
                if (! $duplicate) {
                    $paths[] = ['tier' => $restoredTier->name, 'path' => $object->restoredStoragePath];
                }
            }
        }
        foreach ($paths as $entry) {
            $this->metadata->enqueueStorageGarbage($object->bucket, $entry['tier'], $entry['path']);
        }

        return $paths;
    }

    /** @param list<array{tier: string, path: string}> $paths */
    private function deleteStagedData(string $bucket, array $paths): void
    {
        foreach ($paths as $entry) {
            try {
                \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                    $this->metadata,
                    $this->storageTiers->tier($entry['tier'])->backend,
                    $bucket,
                    $entry['tier'],
                    $entry['path'],
                );
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                // The committed exact-path job remains for the collector.
            }
        }
    }

    private function deleteUncommittedPath(string $bucket, string $storageTier, string $path): void
    {
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $this->storageTiers->tier($storageTier)->backend,
            $bucket,
            $storageTier,
            $path,
            'complete_multipart_uncommitted_path',
        );
    }

    private function abandonAttempt(MultipartWriteIntent $intent): void
    {
        \assert($this->metadata instanceof MultipartWriteIntentStore);
        if (! $this->metadata->abandonMultipartWriteIntent($intent->attemptId, $this->metadata->multipartWriteIntentNow())) {
            return;
        }

        // Only the local collector may replace a dispatched path with its
        // permanent seal. An inline delete could race after that replacement
        // and let a late worker publish bytes into the freed name.
        if (self::isLocalFilesystem($this->storageTiers->tier($intent->storageTier)->backend)) {
            return;
        }

        // The durable tombstone remains for later reconciliation, including
        // remote writes that may finish after this inline delete.
        foreach (array_unique([$intent->assemblyPath, $intent->finalPath]) as $path) {
            $this->deleteUncommittedPath($intent->bucket, $intent->storageTier, $path);
        }
    }

    private function consumeCompletionIntent(MultipartWriteIntent $intent, ?string $localDispatchId): bool
    {
        if ($localDispatchId !== null) {
            return $this->metadata instanceof LocalMultipartCompletionIntentStore
                && $this->metadata->consumeMultipartWriteIntentWithDispatch($intent->attemptId, $localDispatchId);
        }

        return $this->metadata instanceof MultipartWriteIntentStore
            && $this->metadata->consumeMultipartWriteIntent($intent->attemptId);
    }

    private static function isLocalFilesystem(StorageBackend $backend): bool
    {
        return self::localFilesystemBackend($backend) !== null;
    }

    private static function localFilesystemBackend(StorageBackend $backend): ?FilesystemBackend
    {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend instanceof FilesystemBackend ? $backend : null;
    }

    private function assertPlainUploadAllowed(string $bucket, ?string $uploadEncryptionMode): void
    {
        $bucketEncryption = $this->metadata->getBucketEncryption($bucket);
        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
        if ($uploadEncryptionMode === null && $bucketEncryption !== null) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Bucket default encryption changed before multipart completion.',
            );
        }
    }

    /**
     * Reject a known-size completion before assembly when the immutable final
     * principal cannot admit it. Publication repeats the same check under its
     * own transaction so a concurrent quota consumer cannot race this guard.
     *
     * @param array<string, mixed> $upload
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $selectedParts
     */
    private function assertExpectedSizeWithinQuota(
        Request $request,
        \OpsFour\S3Server\Dto\BucketInfo $bucketInfo,
        string $key,
        array $upload,
        ?MultipartDestinationOwnershipSnapshot $ownershipSnapshot,
        int $expectedSize,
        string $storageTier,
        array $selectedParts,
    ): void {
        if ($this->quotas === null) {
            return;
        }

        $initiatorId = (string) $upload['owner_id'];
        $snapshotQuotaPrincipalId = $ownershipSnapshot === null ? null : $ownershipSnapshot->quotaPrincipalId;
        $candidateQuotaPrincipals = $snapshotQuotaPrincipalId === null
            ? [$initiatorId, $bucketInfo->ownerId]
            : [$snapshotQuotaPrincipalId, $bucketInfo->ownerId];
        $hasRelevantQuota = false;
        foreach (array_unique($candidateQuotaPrincipals) as $candidateQuotaPrincipal) {
            if ($this->quotas->hasQuotaForOwner($candidateQuotaPrincipal)) {
                $hasRelevantQuota = true;
                break;
            }
        }
        if (! $hasRelevantQuota) {
            return;
        }

        $this->metadata->transaction(function () use (
            $request,
            $bucketInfo,
            $key,
            $upload,
            $snapshotQuotaPrincipalId,
            $expectedSize,
            $storageTier,
            $selectedParts,
        ): void {
            $initiatorId = (string) $upload['owner_id'];
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire(
                $this->metadata,
                $initiatorId,
                $snapshotQuotaPrincipalId ?? $initiatorId,
                $bucketInfo->ownerId,
            );
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            $this->assertUploadIsActive(
                (string) $upload['upload_id'],
                $bucketInfo->name,
                $key,
                $initiatorId,
                $bucketInfo->generation,
                $storageTier,
                $upload['user_metadata'],
            );
            $this->assertSelectedPartsUnchanged((string) $upload['upload_id'], $selectedParts);

            $versioning = $this->metadata->getBucketVersioning($bucketInfo->name);
            $existingObject = null;
            if ($versioning !== 'Enabled') {
                $nullVersion = $this->metadata->getObjectMetadataByVersion($bucketInfo->name, $key, 'null');
                $existingObject = $nullVersion?->isDeleteMarker === true ? null : $nullVersion;
            }

            // Marker-less uploads retain their historical current-mode rule,
            // but resolve it only after both possible principals are locked.
            $quotaPrincipalId = $snapshotQuotaPrincipalId
                ?? (BucketOwnership::isEnforced($this->metadata, $bucketInfo->name)
                    ? $bucketInfo->ownerId
                    : $initiatorId);

            $this->quotas->assertCanWriteObject(
                $quotaPrincipalId,
                $bucketInfo->name,
                $existingObject,
                $expectedSize,
                $versioning === 'Enabled',
                (string) $upload['upload_id'],
                $initiatorId,
            );
        });
    }

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $legacyAclGrants
     * @return array{string, list<array{granteeType: string, granteeId: string, permission: string}>, string}
     */
    private function destinationDecision(
        ?MultipartDestinationOwnershipSnapshot $snapshot,
        string $bucket,
        string $bucketOwnerId,
        string $initiatorId,
        array $legacyAclGrants,
    ): array {
        if ($snapshot !== null) {
            return [$snapshot->ownerId, $snapshot->aclGrants, $snapshot->quotaPrincipalId];
        }

        // Pre-feature uploads deliberately retain the historical behavior:
        // only current Enforced mode substitutes the bucket owner. Preferred
        // never infers a transfer for a marker-less upload.
        if (BucketOwnership::isEnforced($this->metadata, $bucket)) {
            return [$bucketOwnerId, AclGrantResolver::privateAcl($bucketOwnerId), $bucketOwnerId];
        }

        return [$initiatorId, $legacyAclGrants, $initiatorId];
    }

    /** @param array<string, string>|null $expectedMetadata */
    private function assertUploadIsActive(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        string $bucketGeneration,
        string $storageTier,
        ?array $expectedMetadata = null,
    ): void {
        $upload = $this->metadata->getMultipartUpload($uploadId);
        if (
            $upload === null
            || $upload['bucket'] !== $bucket
            || $upload['key_name'] !== $key
            || $upload['owner_id'] !== $ownerId
        ) {
            throw new NoSuchUploadException();
        }
        if (($upload['bucket_generation'] ?? '') !== $bucketGeneration) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Multipart upload belongs to an unverified bucket generation.',
            );
        }
        if (($upload['storage_tier'] ?? null) !== $storageTier) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Multipart upload storage tier changed during completion.',
            );
        }
        if ($expectedMetadata !== null && $upload['user_metadata'] !== $expectedMetadata) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Multipart upload options changed during completion.',
            );
        }
    }

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $selectedParts
     */
    private function assertSelectedPartsUnchanged(string $uploadId, array $selectedParts): void
    {
        $currentParts = [];
        foreach ($this->metadata->getParts($uploadId) as $part) {
            $currentParts[$part['part_number']] = $part;
        }

        foreach ($selectedParts as $selected) {
            $current = $currentParts[$selected['partNumber']] ?? null;
            if ($current === null
                || $current['etag'] !== $selected['etag']
                || $current['size'] !== $selected['size']
                || $current['storage_path'] !== $selected['storagePath']) {
                throw new InvalidPartException('A selected part changed while the multipart upload was being assembled.');
            }
        }
    }

    /** @param list<array{granteeType: string, granteeId: string, permission: string}> $grants */
    private function assertCurrentPublicAclAllowed(string $bucket, array $grants): void
    {
        $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
        if ($publicAccessBlock !== null
            && $publicAccessBlock['blockPublicAcls']
            && AclGrantResolver::isPublic($grants)) {
            throw new \OpsFour\S3Server\Exception\AccessDeniedException(
                'Public ACLs are blocked by the bucket Public Access Block configuration.',
            );
        }
    }

}
