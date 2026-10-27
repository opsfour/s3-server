<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Acl\ObjectWriteOwnershipDecision;
use OpsFour\S3Server\Auth\AwsChunkedContentLength;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Handler\Multipart\MultipartPartLogicalValidator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Metadata\BucketGenerationGuard;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteDispatchToken;
use OpsFour\S3Server\Storage\LocalObjectWriteOutcome;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectReservation;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectStage;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/**
 * Handles PutObject (PUT /{bucket}/{key}).
 *
 * Streams unencrypted requests into storage; direct SSE requests are bounded
 * in process memory and encrypted before the destination write. Computes the
 * plaintext MD5 ETag and writes object metadata. Supports standard S3
 * headers including Content-Type, Content-Encoding, Content-Disposition,
 * Cache-Control, x-amz-storage-class, and x-amz-meta-* user metadata.
 *
 * The storage backend is responsible for atomic writes (temp file + rename).
 */
final class PutObjectHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly ?LocalFencedObjectWriter $localFencedWriter = null,
        private readonly ?LocalObjectWriteLeaseGuardFactory $localLeaseFactory = null,
        private readonly ?AwsS3ForegroundObjectWriter $awsObjectWriter = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
        if (($localFencedWriter === null) !== ($localLeaseFactory === null)) {
            throw new \InvalidArgumentException('Local object writer and lease factory must be configured together.');
        }
        if ($localFencedWriter !== null && $awsObjectWriter !== null) {
            throw new \InvalidArgumentException('Local and AWS object coordinators are mutually exclusive.');
        }
        if ($localFencedWriter !== null) {
            if (! $metadata instanceof ObjectWriteIntentStore) {
                throw new \InvalidArgumentException('Local object writes require durable metadata intents.');
            }
            $default = $this->storageTiers->defaultTier();
            $backend = $default->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof FilesystemBackend || $storage !== $default->backend
                || $default->name !== $localFencedWriter->storageTierName()
                || realpath($backend->getBasePath()) !== $localFencedWriter->storageRoot()) {
                throw new \InvalidArgumentException('Local object writer must match the default filesystem tier and root.');
            }
        }
        if ($awsObjectWriter !== null) {
            $default = $this->storageTiers->defaultTier();
            $backend = $default->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $metadata instanceof ObjectWriteIntentStore
                || ! $backend instanceof AwsS3StorageBackend
                || $storage !== $default->backend
                || $awsObjectWriter->storageTierName() !== $default->name
                || ($profile = $backend->admittedProfile()) === null
                || ! $awsObjectWriter->isBoundTo($backend, $profile, $metadata)) {
                throw new \InvalidArgumentException('AWS object writer must match the intent-capable default AWS tier.');
            }
        }
    }

    public function handleRequest(Request $request): Response
    {
        // Validate the complete checksum declaration before any resource
        // lookup or storage write. Verified streaming trailers are attached
        // after the request body has been consumed and are checked again then.
        $expectedChecksumAlgorithm = MultipartPartLogicalValidator::expectedClientChecksumAlgorithm($request);
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = (string) $request->getAttribute('ownerId');

        // 1. Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }
        // Anonymous POST Object writes are authorized as anonymous throughout
        // the request, while the resulting object belongs to the bucket owner.
        $objectOwnerId = $ownerId !== '' ? $ownerId : $bucketInfo->ownerId;
        $ownershipDecision = BucketOwnership::resolveObjectWrite(
            $request,
            BucketOwnership::effectiveMode($this->metadata, $bucket),
            $objectOwnerId,
            $bucketInfo->ownerId,
        );
        $this->assertPublicAclAllowed($bucket, $ownershipDecision->aclGrants);
        $tagging = $this->parseObjectTagging($request->getHeader('x-amz-tagging'));
        $encryptionMode = EncryptionRequestResolver::resolveDestination(
            $request,
            $this->metadata,
            $bucket,
            $this->encryption,
        );
        (new ObjectLockRequestApplier($this->metadata))->validate($request, $bucket);

        // 2. Evaluate conditional PUT headers (If-Match, If-None-Match).
        $ifMatch = RequestHeaderValues::combined($request, 'if-match');
        $ifNoneMatch = RequestHeaderValues::combined($request, 'if-none-match');

        $this->evaluatePutConditionals($bucket, $key, $ifMatch, $ifNoneMatch);

        // 3. Extract metadata from request headers.
        $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
        $contentEncoding = RequestHeaderValues::combined($request, 'content-encoding');
        $contentLanguage = RequestHeaderValues::combined($request, 'content-language');
        $contentDisposition = $request->getHeader('content-disposition');
        $cacheControl = RequestHeaderValues::combined($request, 'cache-control');
        $expires = $request->getHeader('expires');

        // Storage class (default STANDARD).
        $storageClass = \OpsFour\S3Server\Http\StorageClassValidator::requested($request->getHeader('x-amz-storage-class'));

        // User metadata (x-amz-meta-* headers).
        $userMetadata = UserMetadataExtractor::extract($request);

        // Store Expires in system metadata if provided.
        if ($expires !== null && $expires !== '') {
            $userMetadata['__expires'] = $expires;
        }
        if ($contentLanguage !== null) {
            $userMetadata['__content-language'] = $contentLanguage;
        }

        $localLease = null;
        $awsReserved = null;
        $awsStage = null;
        $awsDispatchToken = null;
        $bufferedWorkLock = null;
        $requestCancellation = null;
        $publicationCommitted = false;
        try {
            $declaredLength = self::declaredObjectLength($request);
            if ($encryptionMode !== null
                && $declaredLength !== null
                && $declaredLength > $this->maxEncryptedObjectSize) {
                throw new EntityTooLargeException(
                    'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                );
            }
            if ($declaredLength !== null) {
                $this->assertDeclaredLengthWithinQuota(
                    $request,
                    $bucketInfo,
                    $key,
                    $ownershipDecision,
                    $declaredLength,
                );
            }
            if ($encryptionMode !== null && $this->awsObjectWriter !== null) {
                // Bounded memory capacity is acquired before an AWS intent is
                // reserved because no request byte has been consumed yet.
                $bufferedWorkLock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
            }
            if ($this->awsObjectWriter !== null) {
                $awsReserved = $this->awsObjectWriter->reserve($bucketInfo, $key, $objectOwnerId);
            }
            $partLayouts = \OpsFour\S3Server\Metadata\ObjectPartLayoutCapability::require($this->metadata);
            // 3. SSE payloads are encrypted before the first object-storage write.
            // Other payloads retain the storage backend's streaming checksum path.
            $encMeta = [];
            if ($encryptionMode === null) {
                $result = $this->writeObject(
                    $bucketInfo,
                    $key,
                    $objectOwnerId,
                    MultipartPartSize::boundedBody($request->getBody()),
                    $localLease,
                    $awsReserved,
                    $awsStage,
                    $awsDispatchToken,
                );
            } else {
                $nonAwsBufferedWorkLock = $this->awsObjectWriter === null
                    ? \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire()
                    : null;
                $encryptionCancellation = null;
                try {
                    $body = $request->getBody();
                    if ($awsReserved !== null) {
                        $body = $this->awsObjectWriter->renewingStream($awsReserved, $body);
                        $this->awsObjectWriter->heartbeat($awsReserved);
                    }
                    [$plaintext, $plainChecksums] = BufferedSsePayload::read($body, $this->maxEncryptedObjectSize);
                    if ($awsReserved !== null) {
                        $this->awsObjectWriter->heartbeat($awsReserved);
                        [$encMeta, $ciphertext] = $this->awsObjectWriter->transform(
                            $awsReserved,
                            fn(): array => $this->encryptPayload($request, $plaintext, $encryptionMode),
                        );
                    } else {
                        [$encMeta, $ciphertext] = $this->encryptPayload($request, $plaintext, $encryptionMode);
                    }
                    $encryptedWrite = $this->writeObject($bucketInfo, $key, $objectOwnerId, new \Amp\ByteStream\ReadableBuffer($ciphertext), $localLease, $awsReserved, $awsStage, $awsDispatchToken);
                    if ($encryptedWrite->size !== strlen($ciphertext)
                        || ! hash_equals(md5($ciphertext), strtolower($encryptedWrite->md5Hex))) {
                        $this->deleteUncommittedPath($bucket, $encryptedWrite->path);
                        throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted object write does not match its payload.');
                    }
                    if ($awsReserved !== null) {
                        $bufferedWorkLock?->release();
                        $bufferedWorkLock = null;
                        unset($plaintext, $ciphertext);
                    }
                    $result = new StorageWriteResult(
                        path: $encryptedWrite->path,
                        size: $plainChecksums->size,
                        md5Hex: $plainChecksums->md5Hex,
                        crc32Base64: $plainChecksums->crc32Base64(),
                        crc32cBase64: $plainChecksums->crc32cBase64(),
                        sha1Base64: $plainChecksums->sha1Base64(),
                        sha256Base64: $plainChecksums->sha256Base64(),
                    );
                } catch (\Amp\CancelledException $error) {
                    $encryptionCancellation = $error;
                    throw $error;
                } finally {
                    \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                        $encryptionCancellation,
                        static function () use ($nonAwsBufferedWorkLock): void {
                            $nonAwsBufferedWorkLock?->release();
                        },
                    );
                }
            }

            // Trailer checksums are attached to the mutable request only after the
            // verified aws-chunked body reaches its signed trailer block.
            $clientChecksums = MultipartPartLogicalValidator::verifiedClientChecksums(
                $request,
                $expectedChecksumAlgorithm,
            );
            $checksumCrc32 = $clientChecksums['crc32'] ?? null;
            $checksumCrc32c = $clientChecksums['crc32c'] ?? null;
            $checksumSha1 = $clientChecksums['sha1'] ?? null;
            $checksumSha256 = $clientChecksums['sha256'] ?? null;

            // 3b. Verify client-sent checksum against computed value.
            foreach ($clientChecksums as $algo => $clientValue) {
                $computedValue = MultipartPartLogicalValidator::checksum($result, $algo);

                if ($computedValue === null) {
                    $this->deleteUncommittedPath($bucket, $result->path);
                    throw new \OpsFour\S3Server\Exception\InternalErrorException(
                        "Storage backend did not compute checksum for algorithm: {$algo}",
                    );
                }

                if (!hash_equals($computedValue, $clientValue)) {
                    $this->deleteUncommittedPath($bucket, $result->path);
                    throw new BadDigestException(
                        "Checksum mismatch: client sent {$clientValue}, computed {$computedValue}",
                    );
                }

                // Store the computed (verified) value, not the client-sent one.
                match ($algo) {
                    'crc32' => $checksumCrc32 = $computedValue,
                    'crc32c' => $checksumCrc32c = $computedValue,
                    'sha1' => $checksumSha1 = $computedValue,
                    'sha256' => $checksumSha256 = $computedValue,
                    default => throw new \OpsFour\S3Server\Exception\InternalErrorException(
                        "Unexpected validated checksum algorithm: {$algo}",
                    ),
                };
            }

            // 4. Content-Length mismatch detection; clean up the uncommitted write on failure.
            // For chunked SigV4 uploads, Content-Length reflects the encoded body size (with
            // chunk framing). The actual payload size is in x-amz-decoded-content-length.
            if ($result->size > MultipartPartSize::MAX_BYTES) {
                $this->deleteUncommittedPath($bucket, $result->path);
                throw new EntityTooLargeException('PutObject exceeds the 5 GiB maximum.');
            }

            if ($declaredLength !== null && $declaredLength !== $result->size) {
                $this->deleteUncommittedPath($bucket, $result->path);
                throw new \OpsFour\S3Server\Exception\IncompleteBodyException(
                    'Content-Length mismatch: declared ' . $declaredLength . ', received ' . $result->size,
                );
            }

            // The provider request is deliberately after every validation
            // that can be decided from the complete staged evidence.
            if ($awsStage !== null) {
                \assert($awsReserved !== null);
                $outcome = $this->awsObjectWriter?->dispatch($awsReserved, $key, $awsStage)
                    ?? throw new \LogicException('AWS object coordinator disappeared.');
                $awsDispatchToken = $outcome->dispatch;
                $result = $encryptionMode === null ? $outcome->result : $result;
            }

            // Merge encryption metadata into user metadata.
            if ($encMeta !== []) {
                foreach ($encMeta as $k => $v) {
                    $userMetadata['__' . $k] = $v;
                }
            }

            // 6. Build the quoted ETag (S3 ETags are always quoted MD5 hex).
            $etag = '"' . $result->md5Hex . '"';
            $eventName = $request->getAttribute('s3.operation') === \OpsFour\S3Server\Routing\S3Operation::PostObject
                ? 's3:ObjectCreated:Post'
                : 's3:ObjectCreated:Put';
            $event = $this->notifications?->createEvent($eventName, $bucket, $key, $result->size, $etag, $objectOwnerId, request: $request, bucketGeneration: $bucketInfo->generation)
                ?? new S3Event($eventName, $bucket, $key, $result->size, $etag, $objectOwnerId);

            // 5b. Write metadata (versioning-aware) and handle old-object cleanup.
            $versionId = null;
            $versioning = null;
            $oldObjectToClean = null;
            $oldPathsToClean = [];
            $publicationCallbackCompleted = false;
            $metadataWriteAttempted = false;
            $dispatchToken = $localLease?->stopForCommit() ?? $awsDispatchToken;

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
                    // Versioning is enabled: create a new version.
                    $this->metadata->transaction(function () use (
                        $bucket,
                        $bucketInfo,
                        $key,
                        $objectOwnerId,
                        $result,
                        $etag,
                        $contentType,
                        $storageClass,
                        $contentEncoding,
                        $contentDisposition,
                        $cacheControl,
                        $userMetadata,
                        $checksumCrc32,
                        $checksumCrc32c,
                        $checksumSha1,
                        $checksumSha256,
                        $ownershipDecision,
                        $tagging,
                        $request,
                        $encryptionMode,
                        $ifMatch,
                        $ifNoneMatch,
                        $dispatchToken,
                        &$event,
                        $versioning,
                        &$versionId,
                        &$publicationCallbackCompleted,
                        &$metadataWriteAttempted,
                        $partLayouts,
                    ) {
                        \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire(
                            $this->metadata,
                            $objectOwnerId,
                            $bucketInfo->ownerId,
                        );
                        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                        $bucketEncryption = $this->metadata->getBucketEncryption($bucket);
                        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
                        if ($encryptionMode === null && $bucketEncryption !== null) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'Bucket default encryption changed while the object write was being committed.',
                            );
                        }
                        if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'Bucket versioning changed while the object write was being committed.',
                            );
                        }
                        (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                        BucketOwnership::assertObjectWriteDecisionCurrent(
                            $this->metadata,
                            $bucket,
                            $request,
                            $objectOwnerId,
                            $bucketInfo->ownerId,
                            $ownershipDecision,
                        );
                        $this->assertPublicAclAllowed($bucket, $ownershipDecision->aclGrants);
                        $this->evaluatePutConditionals($bucket, $key, $ifMatch, $ifNoneMatch);
                        $this->quotas?->assertCanWriteObject($ownershipDecision->quotaPrincipalId, $bucket, null, $result->size, true);

                        $this->consumeLocalWriteIntent($dispatchToken);
                        $metadataWriteAttempted = true;
                        $versionId = $this->metadata->putObjectVersioned(
                            bucket: $bucket,
                            key: $key,
                            ownerId: $ownershipDecision->ownerId,
                            size: $result->size,
                            etag: $etag,
                            contentType: $contentType,
                            storagePath: $result->path,
                            storageClass: $storageClass,
                            contentEncoding: $contentEncoding,
                            contentDisposition: $contentDisposition,
                            cacheControl: $cacheControl,
                            userMetadata: $userMetadata,
                            checksumCrc32: $checksumCrc32,
                            checksumCrc32c: $checksumCrc32c,
                            checksumSha1: $checksumSha1,
                            checksumSha256: $checksumSha256,
                        );
                        $partLayouts->putObjectPartLayout($bucket, $key, $versionId, \OpsFour\S3Server\Metadata\ObjectPartLayout::knownSingle($result->size));
                        $this->metadata->updateObjectPlacement(
                            $bucket,
                            $key,
                            $versionId,
                            $storageClass,
                            $this->storageTiers->defaultTier()->name,
                            $result->path,
                        );
                        $this->metadata->putAcl('object', $bucket . '/' . $key, $ownershipDecision->ownerId, []);
                        $this->metadata->putAcl(
                            'object',
                            ObjectVersionResolver::aclResourceName($bucket, $key, $versionId),
                            $ownershipDecision->ownerId,
                            $ownershipDecision->aclGrants,
                        );
                        $this->metadata->putObjectTagging($bucket, $key, $tagging ?? [], $versionId);
                        (new ObjectLockRequestApplier($this->metadata))->apply(
                            $request,
                            $bucket,
                            $key,
                            $versionId,
                        );
                        $event = $event->withVersionId($versionId);
                        $this->notifications?->enqueueWebhooks($event);
                        $publicationCallbackCompleted = true;
                    });
                } else {
                    // Versioning suspended or never enabled: overwrite with version_id='null'.
                    // Wrap read-old + write-new in a transaction to prevent concurrent
                    // overwrites from orphaning storage files (SQLite single-writer
                    // serializes, Postgres/MySQL use row locks).
                    $this->metadata->transaction(function () use (
                        $bucket,
                        $bucketInfo,
                        $key,
                        $objectOwnerId,
                        $result,
                        $etag,
                        $contentType,
                        $storageClass,
                        $contentEncoding,
                        $contentDisposition,
                        $cacheControl,
                        $userMetadata,
                        $checksumCrc32,
                        $checksumCrc32c,
                        $checksumSha1,
                        $checksumSha256,
                        $ownershipDecision,
                        $tagging,
                        $request,
                        $encryptionMode,
                        $ifMatch,
                        $ifNoneMatch,
                        $dispatchToken,
                        $event,
                        $versioning,
                        $observedReplacementOwnerId,
                        &$oldObjectToClean,
                        &$oldPathsToClean,
                        &$publicationCallbackCompleted,
                        &$metadataWriteAttempted,
                        $partLayouts,
                    ) {
                        \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire(
                            $this->metadata,
                            $objectOwnerId,
                            $bucketInfo->ownerId,
                            $observedReplacementOwnerId ?? '',
                        );
                        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                        $bucketEncryption = $this->metadata->getBucketEncryption($bucket);
                        \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
                        if ($encryptionMode === null && $bucketEncryption !== null) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'Bucket default encryption changed while the object write was being committed.',
                            );
                        }
                        if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'Bucket versioning changed while the object write was being committed.',
                            );
                        }
                        (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                        BucketOwnership::assertObjectWriteDecisionCurrent(
                            $this->metadata,
                            $bucket,
                            $request,
                            $objectOwnerId,
                            $bucketInfo->ownerId,
                            $ownershipDecision,
                        );
                        $this->assertPublicAclAllowed($bucket, $ownershipDecision->aclGrants);
                        $this->evaluatePutConditionals($bucket, $key, $ifMatch, $ifNoneMatch);
                        $nullVersion = $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null');
                        $oldObjectToClean = $nullVersion?->isDeleteMarker === true ? null : $nullVersion;
                        if ($oldObjectToClean !== null && (
                            $oldObjectToClean->ownerId === ''
                            || ! in_array(
                                $oldObjectToClean->ownerId,
                                [$objectOwnerId, $bucketInfo->ownerId, $observedReplacementOwnerId],
                                true,
                            )
                        )) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'The destination owner changed while the object write was being committed.',
                            );
                        }
                        if ($oldObjectToClean !== null) {
                            (new \OpsFour\S3Server\ObjectLock\ObjectLockChecker($this->metadata))
                                ->checkProtection($bucket, $key, 'null');
                            if ($oldObjectToClean->restoredStoragePath !== null) {
                                \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($oldObjectToClean, $this->storageTiers);
                            }
                        }

                        $this->quotas?->assertCanWriteObject($ownershipDecision->quotaPrincipalId, $bucket, $oldObjectToClean, $result->size, false);

                        $this->consumeLocalWriteIntent($dispatchToken);
                        $metadataWriteAttempted = true;
                        $this->metadata->putObjectMetadata(
                            bucket: $bucket,
                            key: $key,
                            ownerId: $ownershipDecision->ownerId,
                            size: $result->size,
                            etag: $etag,
                            contentType: $contentType,
                            storagePath: $result->path,
                            storageClass: $storageClass,
                            contentEncoding: $contentEncoding,
                            contentDisposition: $contentDisposition,
                            cacheControl: $cacheControl,
                            userMetadata: $userMetadata,
                            checksumCrc32: $checksumCrc32,
                            checksumCrc32c: $checksumCrc32c,
                            checksumSha1: $checksumSha1,
                            checksumSha256: $checksumSha256,
                        );
                        $partLayouts->putObjectPartLayout($bucket, $key, 'null', \OpsFour\S3Server\Metadata\ObjectPartLayout::knownSingle($result->size));
                        $this->metadata->updateObjectPlacement(
                            $bucket,
                            $key,
                            null,
                            $storageClass,
                            $this->storageTiers->defaultTier()->name,
                            $result->path,
                        );
                        $this->metadata->putAcl('object', $bucket . '/' . $key, $ownershipDecision->ownerId, []);
                        $this->metadata->putAcl(
                            'object',
                            ObjectVersionResolver::aclResourceName($bucket, $key, null),
                            $ownershipDecision->ownerId,
                            $ownershipDecision->aclGrants,
                        );
                        $this->metadata->putObjectTagging($bucket, $key, $tagging ?? []);
                        (new ObjectLockRequestApplier($this->metadata))->apply(
                            $request,
                            $bucket,
                            $key,
                            null,
                        );
                        if ($oldObjectToClean !== null) {
                            $oldPathsToClean = $this->stageReplacedData($oldObjectToClean, $this->storageTiers->defaultTier()->name, $result->path);
                        }
                        $this->notifications?->enqueueWebhooks($event);
                        $publicationCallbackCompleted = true;
                    });
                }
            } catch (\Throwable $e) {
                // A failed COMMIT acknowledgment does not prove that the SQL
                // transaction rolled back. Preserve any path now referenced by
                // metadata; if that lookup fails, defer cleanup instead of
                // deleting a possibly published object.
                // The transaction can throw while committing after its callback
                // has set this by-reference safety flag.
                \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                    $e,
                    function () use ($publicationCallbackCompleted, $bucket, $key, $result, $versionId, $versioning, $metadataWriteAttempted): void {
                        if (! $publicationCallbackCompleted
                            && ! $this->mayBePublishedAfterCommitFailure($bucket, $key, $result->path, $versionId, $versioning, $metadataWriteAttempted)) {
                            $this->deleteUncommittedPath($bucket, $result->path);
                        }
                    },
                );
                throw $e;
            }
            $publicationCommitted = true;

            // Clean up old storage file on overwrite (non-versioned only).
            $this->deleteStagedData($bucket, $oldPathsToClean);

            // 7. Build response headers.
            $headers = ['ETag' => $etag];

            if ($versionId !== null || $versioning === 'Suspended') {
                $headers['x-amz-version-id'] = $versionId ?? 'null';
            }

            // Encryption response headers.
            $sseAlgo = $encMeta['sse-algorithm'] ?? null;
            if ($sseAlgo === 'SSE-C') {
                $headers['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
                $sseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                if ($sseCKeyMd5 !== null) {
                    $headers['x-amz-server-side-encryption-customer-key-MD5'] = $sseCKeyMd5;
                }
            } elseif ($sseAlgo === 'AES256') {
                $headers['x-amz-server-side-encryption'] = 'AES256';
            }

            // Echo back any checksum headers the client sent.
            if ($checksumCrc32 !== null) {
                $headers['x-amz-checksum-crc32'] = $checksumCrc32;
            }
            if ($checksumCrc32c !== null) {
                $headers['x-amz-checksum-crc32c'] = $checksumCrc32c;
            }
            if ($checksumSha1 !== null) {
                $headers['x-amz-checksum-sha1'] = $checksumSha1;
            }
            if ($checksumSha256 !== null) {
                $headers['x-amz-checksum-sha256'] = $checksumSha256;
            }
            if ($checksumCrc32 !== null || $checksumCrc32c !== null || $checksumSha1 !== null || $checksumSha256 !== null) {
                $headers['x-amz-checksum-type'] = 'FULL_OBJECT';
            }

            // 8. Dispatch event notification.
            $this->notifications?->dispatchInternalEvent($event);

            return new Response(
                status: 200,
                headers: $headers,
            );
        } catch (\Amp\CancelledException $error) {
            $requestCancellation = $error;
            throw $error;
        } finally {
            if ($awsReserved !== null) {
                $this->awsObjectWriter?->retireBeforeDispatch($awsReserved);
            }
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(
                $requestCancellation,
                function () use ($awsStage, $publicationCommitted): void {
                    if ($awsStage !== null) {
                        try {
                            $this->awsObjectWriter?->release($awsStage);
                        } catch (\Throwable $error) {
                            if (! $publicationCommitted) {
                                throw $error;
                            }
                        }
                    }
                },
                static function () use ($bufferedWorkLock): void {
                    $bufferedWorkLock?->release();
                },
                static function () use ($localLease): void {
                    $localLease?->close();
                },
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
        if ($this->awsObjectWriter !== null) {
            return;
        }
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
                // The exact cleanup job was committed with the replacement.
            }
        }
    }

    private function mayBePublishedAfterCommitFailure(
        string $bucket,
        string $key,
        string $path,
        ?string $versionId,
        ?string $versioning,
        bool $metadataWriteAttempted,
    ): bool {
        try {
            if ($versionId !== null) {
                $version = $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
                if (($version?->systemMetadata['storagePath'] ?? null) === $path) {
                    return true;
                }
            } elseif ($versioning === 'Enabled' && $metadataWriteAttempted) {
                // A version insert may have committed even if its ID was not
                // returned to this process. There is no bounded lookup by
                // physical path, so leave this attempt for reconciliation.
                return true;
            }

            $current = $this->metadata->getObjectMetadata($bucket, $key);

            return ($current?->systemMetadata['storagePath'] ?? null) === $path;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $grants
     */
    private function assertPublicAclAllowed(string $bucket, array $grants): void
    {
        $pab = $this->metadata->getPublicAccessBlock($bucket);
        if ($pab !== null && $pab['blockPublicAcls'] && AclGrantResolver::isPublic($grants)) {
            throw new \OpsFour\S3Server\Exception\AccessDeniedException(
                'Public ACLs are blocked by the bucket Public Access Block configuration.',
            );
        }
    }

    /**
     * @return list<array{key: string, value: string}>|null
     */
    private function parseObjectTagging(?string $tagging): ?array
    {
        if ($tagging === null) {
            return null;
        }

        return ObjectTagValidator::parseHeader($tagging);
    }

    /** @return array{array<string, string>, string} */
    private function encryptPayload(Request $request, string $plaintext, string $encryptionMode): array
    {
        EncryptionRequestResolver::requireEncryptionService($this->encryption, $encryptionMode);
        \assert($this->encryption !== null);

        if ($encryptionMode === EncryptionRequestResolver::SSE_C) {
            $algorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
            $encodedKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
            $keyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
            \assert($algorithm !== null && $encodedKey !== null && $keyMd5 !== null);
            $customerKey = EncryptionService::validateSseCHeaders($algorithm, $encodedKey, $keyMd5);
            $encrypted = $this->encryption->encryptSseC($plaintext, $customerKey);

            return [[
                'sse-algorithm' => 'SSE-C',
                'sse-iv' => $encrypted['iv'],
                'sse-tag' => $encrypted['tag'],
                'sse-customer-key-md5' => $keyMd5,
            ], $encrypted['ciphertext']];
        }

        $encrypted = $this->encryption->encryptSseS3($plaintext);

        return [[
            'sse-algorithm' => 'AES256',
            'sse-key' => $encrypted['encryptedDataKey'],
            'sse-iv' => $encrypted['iv'],
            'sse-tag' => $encrypted['tag'],
        ], $encrypted['ciphertext']];
    }

    private function deleteUncommittedPath(string $bucket, string $path): void
    {
        if ($this->localFencedWriter !== null || $this->awsObjectWriter !== null) {
            // An armed worker may still link this path after the request ends.
            // The intent collector seals it before any physical reclamation.
            return;
        }
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $this->storage,
            $bucket,
            $this->storageTiers->defaultTier()->name,
            $path,
            'put_object_uncommitted_path',
        );
    }

    private static function declaredObjectLength(Request $request): ?int
    {
        $decodedLength = AwsChunkedContentLength::fromRequest($request);
        if ($decodedLength !== null) {
            if ($decodedLength > MultipartPartSize::MAX_BYTES) {
                throw new EntityTooLargeException('PutObject exceeds the 5 GiB maximum.');
            }

            return $decodedLength;
        }

        $value = $request->getHeader('content-length');
        if ($value === null) {
            return null;
        }
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException('Content-Length must be a non-negative decimal integer.');
        }
        $normalized = ltrim($value, '0');
        $maximum = (string) MultipartPartSize::MAX_BYTES;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new EntityTooLargeException('PutObject exceeds the 5 GiB maximum.');
        }

        return (int) $value;
    }

    private function assertDeclaredLengthWithinQuota(
        Request $request,
        BucketInfo $bucketInfo,
        string $key,
        ObjectWriteOwnershipDecision $ownershipDecision,
        int $declaredLength,
    ): void {
        if ($this->quotas === null
            || (! $this->quotas->hasQuotaForOwner($ownershipDecision->quotaPrincipalId)
                && ! $this->quotas->hasQuotaForOwner($bucketInfo->ownerId))) {
            return;
        }

        $this->metadata->transaction(function () use ($request, $bucketInfo, $key, $ownershipDecision, $declaredLength): void {
            OwnerWriteLock::acquire($this->metadata, $ownershipDecision->quotaPrincipalId, $bucketInfo->ownerId);
            BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            BucketOwnership::assertObjectWriteDecisionCurrent(
                $this->metadata,
                $bucketInfo->name,
                $request,
                $request->getAttribute('ownerId') !== ''
                    ? (string) $request->getAttribute('ownerId')
                    : $bucketInfo->ownerId,
                $bucketInfo->ownerId,
                $ownershipDecision,
            );

            $versioning = $this->metadata->getBucketVersioning($bucketInfo->name);
            $existingObject = null;
            if ($versioning !== 'Enabled') {
                $nullVersion = $this->metadata->getObjectMetadataByVersion($bucketInfo->name, $key, 'null');
                $existingObject = $nullVersion?->isDeleteMarker === true ? null : $nullVersion;
            }

            $this->quotas->assertCanWriteObject(
                $ownershipDecision->quotaPrincipalId,
                $bucketInfo->name,
                $existingObject,
                $declaredLength,
                $versioning === 'Enabled',
            );
        });
    }

    private function writeObject(
        BucketInfo $bucket,
        string $key,
        string $ownerId,
        ReadableStream $body,
        ?LocalObjectWriteLeaseGuard &$localLease,
        ?AwsS3ForegroundObjectReservation &$awsReserved,
        ?AwsS3ForegroundObjectStage &$awsStage,
        ?LocalObjectWriteDispatchToken &$awsDispatchToken,
    ): StorageWriteResult {
        if ($this->awsObjectWriter !== null) {
            $awsReserved ??= $this->awsObjectWriter->reserve($bucket, $key, $ownerId);
            $awsStage = $this->awsObjectWriter->stage($awsReserved, $body);
            return $awsStage->result;
        }
        if ($this->localFencedWriter === null || $this->localLeaseFactory === null) {
            return $this->storage->putObject($bucket->name, $key, $body);
        }
        $tierName = $this->storageTiers->defaultTier()->name;
        if ($tierName !== $this->localFencedWriter->storageTierName()) {
            throw new \RuntimeException('Local object writer is no longer configured for the default tier.');
        }
        $attemptId = bin2hex(random_bytes(16));
        $planned = new ObjectWriteIntent(
            attemptId: $attemptId,
            bucket: $bucket->name,
            bucketGeneration: $bucket->generation,
            ownerId: $ownerId,
            storageTier: $tierName,
            storagePath: StoragePath::forPlannedObject(
                $this->localFencedWriter->storageRoot(),
                $bucket->name,
                $key,
                $bucket->generation,
                $attemptId,
            ),
            purpose: 'put_object',
        );
        $localLease = $this->localLeaseFactory->reserve($planned);
        $outcome = $this->localFencedWriter->publishWithLease($localLease->reservedIntent(), $key, $body, $localLease);
        if ($outcome->state !== LocalObjectWriteOutcome::COMPLETED || $outcome->result === null) {
            throw $outcome->error ?? new \RuntimeException('Local object publication did not return a definite result.');
        }

        return $outcome->result;
    }

    private function consumeLocalWriteIntent(?LocalObjectWriteDispatchToken $token): void
    {
        if ($token === null) {
            return;
        }
        if (! $this->metadata instanceof ObjectWriteIntentStore
            || ! $this->metadata->consumeArmedObjectWriteIntent($token->attemptId, $token->dispatchId)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Local object write lease was lost before metadata publication.',
            );
        }
    }

    private function evaluatePutConditionals(
        string $bucket,
        string $key,
        ?string $ifMatch,
        ?string $ifNoneMatch,
    ): void {
        if ($ifMatch === null && $ifNoneMatch === null) {
            return;
        }

        $existingObject = $this->metadata->getObjectMetadata($bucket, $key);
        if ($existingObject?->isDeleteMarker === true) {
            $existingObject = null;
        }
        if ($ifMatch !== null) {
            if ($existingObject === null) {
                throw new \OpsFour\S3Server\Exception\PreconditionFailedException();
            }
            if (! ConditionalHeaderEvaluator::etagMatches($ifMatch, $existingObject->etag)) {
                throw new \OpsFour\S3Server\Exception\PreconditionFailedException();
            }
        }
        if ($ifNoneMatch !== null) {
            if ($ifNoneMatch === '*' && $existingObject !== null) {
                throw new \OpsFour\S3Server\Exception\PreconditionFailedException();
            }
            if (
                $ifNoneMatch !== '*'
                && $existingObject !== null
                && ConditionalHeaderEvaluator::etagMatches($ifNoneMatch, $existingObject->etag, allowWeak: true)
            ) {
                throw new \OpsFour\S3Server\Exception\PreconditionFailedException();
            }
        }
    }
}
