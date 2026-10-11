<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Checksum\ChecksumResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Http\CopySourceParser;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\CopySourceConditionEvaluator;
use OpsFour\S3Server\Http\MissingObjectListVisibility;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Security\ObjectReadAuthorizer;
use OpsFour\S3Server\Policy\AdditionalActionAuthorizer;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\BoundedCopyStorageBackend;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteDispatchToken;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles CopyObject (PUT /{bucket}/{key} with x-amz-copy-source header).
 *
 * Copies an object from a source bucket/key to a destination bucket/key.
 * Supports:
 * - x-amz-metadata-directive: COPY (default) or REPLACE
 * - Conditional copy: x-amz-copy-source-if-match, x-amz-copy-source-if-none-match,
 *   x-amz-copy-source-if-modified-since, x-amz-copy-source-if-unmodified-since
 * - Cross-bucket copies with source read and destination write permission
 */
final class CopyObjectHandler implements RequestHandler
{
    private const int MAX_SINGLE_COPY_BYTES = 5_368_709_120;

    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
        private readonly ?LocalFencedObjectWriter $localFencedWriter = null,
        private readonly ?LocalObjectWriteLeaseGuardFactory $localLeaseFactory = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
        if (($this->localFencedWriter === null) !== ($this->localLeaseFactory === null)) {
            throw new \InvalidArgumentException('Local copy fencing needs a writer and a lease factory.');
        }
        $localBackend = $this->storageTiers->defaultBackend();
        if ($localBackend instanceof ObservedStorageBackend) {
            $localBackend = $localBackend->innerBackend();
        }
        if ($this->localFencedWriter !== null && (
            ! $this->metadata instanceof ObjectWriteIntentStore
            || ! $localBackend instanceof FilesystemBackend
            || $this->localFencedWriter->storageTierName() !== $this->storageTiers->defaultTier()->name
            || ($localBackend->hostEvidenceReader() !== null
                ? $localBackend->getBasePath()
                : realpath($localBackend->getBasePath())) !== $this->localFencedWriter->storageRoot()
        )) {
            throw new \InvalidArgumentException('Local copy fencing does not match the default filesystem tier and intent store.');
        }
    }

    /**
     * @return array{StorageWriteResult, ?string, int, array<string, string>, ?ChecksumResult, LocalObjectWriteDispatchToken}
     */
    private function writeFencedCopy(
        Request $request,
        ObjectInfo $source,
        StorageBackend $sourceStorage,
        string $sourcePath,
        ?string $sourceEncryptionMode,
        ?string $sourceCustomerKey,
        ?string $destinationEncryptionMode,
        string $destinationBucket,
        string $destinationKey,
        string $bucketGeneration,
        string $writeOwnerId,
    ): array {
        \assert($this->localFencedWriter !== null && $this->localLeaseFactory !== null);
        \assert($this->metadata instanceof ObjectWriteIntentStore);

        $plaintextChecksums = null;
        $etag = null;
        $encryptionMetadata = [];
        $expectedPhysicalPayload = null;
        $body = null;
        $bufferLock = null;
        try {
            if ($sourceEncryptionMode !== null || $destinationEncryptionMode !== null) {
                if ($source->size > $this->maxEncryptedObjectSize) {
                    throw new EntityTooLargeException(
                        'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                    );
                }
                $bufferLock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
                [$sourceBytes] = BufferedSsePayload::readExpected(
                    $sourceStorage->getObjectByPath($sourcePath),
                    $source->size,
                    $this->maxEncryptedObjectSize,
                );
                if ($sourceEncryptionMode === EncryptionRequestResolver::SSE_C) {
                    \assert($this->encryption !== null && $sourceCustomerKey !== null);
                    $plaintext = $this->encryption->decryptSseC(
                        $sourceBytes,
                        $sourceCustomerKey,
                        $source->userMetadata['__sse-iv'],
                        $source->userMetadata['__sse-tag'],
                    );
                } elseif ($sourceEncryptionMode === EncryptionRequestResolver::SSE_S3) {
                    \assert($this->encryption !== null);
                    $plaintext = $this->encryption->decryptSseS3(
                        $sourceBytes,
                        $source->userMetadata['__sse-key'],
                        $source->userMetadata['__sse-iv'],
                        $source->userMetadata['__sse-tag'],
                    );
                } else {
                    $plaintext = $sourceBytes;
                }
                $calculator = new ChecksumCalculator();
                $calculator->update($plaintext);
                $plaintextChecksums = $calculator->finalize();
                if ($plaintextChecksums->size !== $source->size) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source size does not match its metadata.');
                }
                $etag = '"' . $plaintextChecksums->md5Hex . '"';
                if ($destinationEncryptionMode === EncryptionRequestResolver::SSE_C) {
                    \assert($this->encryption !== null);
                    $algorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
                    $encodedKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
                    $keyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                    \assert($algorithm !== null && $encodedKey !== null && $keyMd5 !== null);
                    $customerKey = EncryptionService::validateSseCHeaders($algorithm, $encodedKey, $keyMd5);
                    $encrypted = $this->encryption->encryptSseC($plaintext, $customerKey);
                    $encryptionMetadata = [
                        'sse-algorithm' => 'SSE-C',
                        'sse-iv' => $encrypted['iv'],
                        'sse-tag' => $encrypted['tag'],
                        'sse-customer-key-md5' => $keyMd5,
                    ];
                    $expectedPhysicalPayload = $encrypted['ciphertext'];
                } elseif ($destinationEncryptionMode === EncryptionRequestResolver::SSE_S3) {
                    \assert($this->encryption !== null);
                    $encrypted = $this->encryption->encryptSseS3($plaintext);
                    $encryptionMetadata = [
                        'sse-algorithm' => 'AES256',
                        'sse-key' => $encrypted['encryptedDataKey'],
                        'sse-iv' => $encrypted['iv'],
                        'sse-tag' => $encrypted['tag'],
                    ];
                    $expectedPhysicalPayload = $encrypted['ciphertext'];
                } else {
                    $expectedPhysicalPayload = $plaintext;
                }
                $body = new \Amp\ByteStream\ReadableBuffer($expectedPhysicalPayload);
            } else {
                $readLimit = $source->size < PHP_INT_MAX ? $source->size + 1 : PHP_INT_MAX;
                $body = new LimitedReadableStream($sourceStorage->getObjectByPath($sourcePath), $readLimit);
            }

            $attemptId = bin2hex(random_bytes(16));
            $tierName = $this->storageTiers->defaultTier()->name;
            $planned = new ObjectWriteIntent(
                $attemptId,
                $destinationBucket,
                $bucketGeneration,
                $writeOwnerId,
                $tierName,
                StoragePath::forPlannedObject($this->localFencedWriter->storageRoot(), $destinationBucket, $destinationKey, $bucketGeneration, $attemptId),
                'copy_object',
            );
            $lease = $this->localLeaseFactory->reserve($planned);
            try {
                $outcome = $this->localFencedWriter->publishWithLease($lease->reservedIntent(), $destinationKey, $body, $lease);
                if ($outcome->result === null) {
                    throw $outcome->error ?? new \OpsFour\S3Server\Exception\InternalErrorException('Local copy publication did not complete.');
                }
                $write = $outcome->result;
                if ($expectedPhysicalPayload !== null && (
                    $write->size !== strlen($expectedPhysicalPayload)
                    || ! hash_equals(md5($expectedPhysicalPayload), strtolower($write->md5Hex))
                )) {
                    throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted copy write does not match its payload.');
                }
                $token = $lease->stopForCommit();

                return [$write, $etag, $plaintextChecksums !== null ? $plaintextChecksums->size : $write->size, $encryptionMetadata, $plaintextChecksums, $token];
            } finally {
                $lease->close();
            }
        } finally {
            $body?->close();
            $bufferLock?->release();
        }
    }

    private function consumeLocalWriteIntent(?LocalObjectWriteDispatchToken $token): void
    {
        if ($token === null) {
            return;
        }
        $this->localFencedWriter?->assertHostEvidenceFresh();
        if (! $this->metadata instanceof ObjectWriteIntentStore
            || ! $this->metadata->consumeArmedObjectWriteIntent($token->attemptId, $token->dispatchId)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('The local copy write lost its publication lease.');
        }
    }

    public function handleRequest(Request $request): Response
    {
        if ($request->getHeader('x-amz-checksum-algorithm') !== null) {
            throw new \OpsFour\S3Server\Exception\NotImplementedException(
                'CopyObject checksum selection is not supported.',
            );
        }
        $dstBucket = $request->getAttribute('s3.bucket');
        $dstKey = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify destination bucket exists. Middleware enforces write access.
        $dstBucketInfo = $this->metadata->getBucket($dstBucket);
        if ($dstBucketInfo === null) {
            throw new NoSuchBucketException();
        }
        $requestedStorageClass = $request->getHeader('x-amz-storage-class');
        if ($requestedStorageClass !== null) {
            $requestedStorageClass = \OpsFour\S3Server\Http\StorageClassValidator::requested($requestedStorageClass);
        }
        $aclGrants = AclGrantResolver::fromHeaders($request, $ownerId, 'object', $dstBucketInfo->ownerId)
            ?? AclGrantResolver::privateAcl($ownerId);
        $publicAccessBlock = $this->metadata->getPublicAccessBlock($dstBucket);
        if (
            $publicAccessBlock !== null
            && $publicAccessBlock['blockPublicAcls']
            && AclGrantResolver::isPublic($aclGrants)
        ) {
            throw new \OpsFour\S3Server\Exception\AccessDeniedException(
                'Public ACLs are blocked by the bucket Public Access Block configuration.',
            );
        }

        // 2. Parse the copy source header.
        $copySource = RequestHeaderValues::singleton($request, 'x-amz-copy-source');
        if ($copySource === null || $copySource === '') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Missing x-amz-copy-source header.');
        }

        [$srcBucket, $srcKey, $srcVersionId] = CopySourceParser::parse($copySource);

        // 3. Verify source bucket exists.
        $srcBucketInfo = $this->metadata->getBucket($srcBucket);
        if ($srcBucketInfo === null) {
            throw new NoSuchKeyException();
        }
        $srcVersioning = $this->metadata->getBucketVersioning($srcBucket);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $srcBucketInfo);
        // Bind the source access-log record to this bucket lifetime before a
        // later read or commit can race with deletion and name reuse.
        $request->setAttribute('s3.copySourceBucket', $srcBucket);
        $request->setAttribute('s3.copySourceKey', $srcKey);
        $request->setAttribute('s3.copySourceVersionId', $srcVersionId);
        $request->setAttribute('s3.copySourceBucketOwner', $srcBucketInfo->ownerId);
        $request->setAttribute('s3.copySourceBucketGeneration', $srcBucketInfo->generation);
        $expectedSourceOwner = RequestHeaderValues::singleton($request, 'x-amz-source-expected-bucket-owner');
        if ($expectedSourceOwner !== null && $expectedSourceOwner !== $srcBucketInfo->ownerId) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw new AccessDeniedException('Expected source bucket owner does not match.');
        }

        // 4. Get source object metadata (optionally a specific version).
        $srcObjectInfo = ($srcVersionId !== null)
            ? $this->metadata->getObjectMetadataByVersion($srcBucket, $srcKey, $srcVersionId)
            : $this->metadata->getObjectMetadata($srcBucket, $srcKey);
        if ($srcObjectInfo === null) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            // A missing current source must not disclose its absence to a
            // caller who cannot list the source bucket.
            if ($srcVersionId === null
                && $this->metadata->getCurrentObjectVersion($srcBucket, $srcKey) === null
                && ! MissingObjectListVisibility::canList($this->metadata, $request, $srcBucket, copySource: true)) {
                throw new AccessDeniedException();
            }
            throw new NoSuchKeyException();
        }
        if ($srcObjectInfo->isDeleteMarker) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            if ($srcVersionId !== null) {
                throw new InvalidRequestException(
                    'The source of a copy request may not specifically refer to a delete marker by version ID.',
                );
            }
            throw new NoSuchKeyException();
        }
        $request->setAttribute('s3.copySourceVersionId', $srcObjectInfo->versionId);
        $request->setAttribute('s3.copySourceObjectSize', $srcObjectInfo->size);
        try {
            (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObjectInfo, $srcVersionId !== null);
        } catch (\Throwable $e) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw $e;
        }
        if ($srcObjectInfo->size > self::MAX_SINGLE_COPY_BYTES) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw new EntityTooLargeException('CopyObject source exceeds the 5 GiB single-copy maximum.');
        }
        $destinationEncryptionMode = EncryptionRequestResolver::resolveDestination(
            $request,
            $this->metadata,
            $dstBucket,
            $this->encryption,
        );
        (new ObjectLockRequestApplier($this->metadata))->validate($request, $dstBucket);
        $sourceEncryptionMode = $srcObjectInfo->userMetadata['__sse-algorithm'] ?? null;
        if ($sourceEncryptionMode !== null) {
            EncryptionRequestResolver::requireEncryptionService($this->encryption);
            if (! in_array($sourceEncryptionMode, [EncryptionRequestResolver::SSE_C, EncryptionRequestResolver::SSE_S3], true)) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source has an unsupported stored encryption mode.');
            }
        }
        $sourceCustomerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $sourceEncryptionMode === 'SSE-C',
            $srcObjectInfo->userMetadata['__sse-customer-key-md5'] ?? null,
            copySource: true,
        );
        if ($sourceEncryptionMode !== 'SSE-C' && $sourceCustomerKey !== null) {
            throw new InvalidArgumentException('Copy-source SSE-C headers are not valid for this object.');
        }

        // 5. Evaluate conditional copy headers.
        try {
            CopySourceConditionEvaluator::assertSatisfied($request, $srcObjectInfo);
        } catch (\Throwable $e) {
            $request->setAttribute('s3.copySourceLogEligible', true);
            throw $e;
        }

        // 6. Determine metadata directive.
        $metadataDirective = strtoupper($request->getHeader('x-amz-metadata-directive') ?? 'COPY');
        if (!in_array($metadataDirective, ['COPY', 'REPLACE'], true)) {
            throw new InvalidArgumentException('Invalid x-amz-metadata-directive value.');
        }
        $taggingDirective = strtoupper($request->getHeader('x-amz-tagging-directive') ?? 'COPY');
        if (!in_array($taggingDirective, ['COPY', 'REPLACE'], true)) {
            throw new InvalidArgumentException('Invalid x-amz-tagging-directive value.');
        }
        $taggingHeader = $request->getHeader('x-amz-tagging');
        $tags = $taggingDirective === 'COPY'
            ? $this->metadata->getObjectTagging($srcBucket, $srcKey, $srcObjectInfo->versionId)
            : ($taggingHeader === null || $taggingHeader === '' ? [] : ObjectTagValidator::parseHeader($taggingHeader));
        // COPY applies source tags but supplies no RequestObjectTag values.
        $policyRequestTags = $taggingDirective === 'REPLACE' ? $tags : [];
        $requiresTagPermission = $tags !== [] || $taggingDirective === 'REPLACE';
        if ($requiresTagPermission) {
            (new AdditionalActionAuthorizer($this->metadata))->assertAllowed(
                $request,
                $dstBucket,
                $dstKey,
                's3:PutObjectTagging',
                $policyRequestTags,
            );
        }

        // Validate replacement metadata before the storage copy. Besides
        // failing fast, this prevents an invalid metadata request from
        // leaving an unreferenced physical destination object behind.
        $replacementUserMetadata = $metadataDirective === 'REPLACE'
            ? UserMetadataExtractor::extract($request)
            : null;
        $replacementExpires = $request->getHeader('expires');
        if ($replacementUserMetadata !== null && $replacementExpires !== null && $replacementExpires !== '') {
            $replacementUserMetadata['__expires'] = $replacementExpires;
        }

        // Reject self-copy unless this request changes a property that this
        // handler can persist with COPY. A different storage class is one such
        // change; merely repeating the current class is still a no-op.
        if ($srcBucket === $dstBucket && $srcKey === $dstKey
            && $metadataDirective === 'COPY' && $srcVersionId === null
            && ($requestedStorageClass === null || $requestedStorageClass === $srcObjectInfo->storageClass)) {
            throw new InvalidRequestException(
                'This copy request is illegal because it is trying to copy an object to itself '
                . 'without changing the object\'s storage class.',
            );
        }

        // 7. Perform the copy in storage.
        $srcPath = $srcObjectInfo->systemMetadata['storagePath'] ?? null;
        if ($srcPath === null || $srcPath === '') {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Source object storage path missing.');
        }

        $sourceTier = $this->storageTiers->tier($srcObjectInfo->storageTier);
        $sourceStorage = $sourceTier->backend;
        if ($sourceTier->restoreRequired) {
            if (
                $srcObjectInfo->restoreStatus !== 'restored'
                || $srcObjectInfo->restoredStoragePath === null
                || $srcObjectInfo->restoreExpiresAt === null
                || $srcObjectInfo->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                throw new InvalidObjectStateException('The source object must be restored before it can be copied.');
            }
            $sourceStorage = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($srcObjectInfo, $this->storageTiers)->backend;
            $srcPath = $srcObjectInfo->restoredStoragePath;
        }
        $destinationStorage = $this->storageTiers->defaultBackend();
        $priorDestinationReferences = [
            $this->metadata->getCurrentObjectVersion($dstBucket, $dstKey),
            $this->metadata->getObjectMetadataByVersion($dstBucket, $dstKey, 'null'),
        ];

        $preEncryptedDestination = $destinationEncryptionMode !== null;
        $preEncryptedEtag = null;
        $preEncryptedObjectSize = null;
        $preEncryptedMeta = [];
        $sourcePlaintextChecksums = null;
        $localDispatchToken = null;
        $readLimit = $srcObjectInfo->size < PHP_INT_MAX ? $srcObjectInfo->size + 1 : PHP_INT_MAX;
        $request->setAttribute('s3.copySourceLogEligible', true);
        if ($this->localFencedWriter !== null) {
            [$writeResult, $preEncryptedEtag, $preEncryptedObjectSize, $preEncryptedMeta, $sourcePlaintextChecksums, $localDispatchToken] = $this->writeFencedCopy(
                $request,
                $srcObjectInfo,
                $sourceStorage,
                $srcPath,
                $sourceEncryptionMode,
                $sourceCustomerKey,
                $destinationEncryptionMode,
                $dstBucket,
                $dstKey,
                $dstBucketInfo->generation,
                $ownerId,
            );
        } elseif ($preEncryptedDestination) {
            [$writeResult, $preEncryptedEtag, $preEncryptedObjectSize, $preEncryptedMeta, $sourcePlaintextChecksums] = $this->writeEncryptedCopy(
                $request,
                $srcObjectInfo,
                $sourceStorage,
                $srcPath,
                $sourceEncryptionMode,
                $sourceCustomerKey,
                $destinationStorage,
                $dstBucket,
                $dstKey,
                $destinationEncryptionMode,
            );
        } elseif ($sourceStorage === $destinationStorage && $destinationStorage instanceof BoundedCopyStorageBackend) {
            $writeResult = $destinationStorage->copyObjectBounded($srcPath, $dstBucket, $dstKey, $readLimit);
        } else {
            // Streaming fallback for cross-tier copies and custom backends
            // without a bounded same-backend copy capability.
            // One byte past the committed size is enough for the post-write
            // integrity check to reject an oversized physical source.
            $stream = new LimitedReadableStream($sourceStorage->getObjectByPath($srcPath), $readLimit);
            try {
                $writeResult = $destinationStorage->putObject($dstBucket, $dstKey, $stream);
            } finally {
                $stream->close();
            }
        }

        $etag = $preEncryptedEtag ?? '"' . $writeResult->md5Hex . '"';

        // A copy must have its own physical path. An adapter returning the
        // source path may already have touched source bytes; deleting that path
        // on any later failure would destroy the committed source as well.
        if ($sourceStorage === $destinationStorage && $writeResult->path === $srcPath) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy backend reused the source storage path.');
        }
        foreach ($priorDestinationReferences as $existing) {
            if ($existing !== null && $existing->storageTier === $this->storageTiers->defaultTier()->name
                && ($existing->systemMetadata['storagePath'] ?? null) === $writeResult->path) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy backend reused a committed destination storage path.');
            }
            if ($existing !== null && $existing->restoredStoragePath === $writeResult->path
                && \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($existing, $this->storageTiers)->name === $this->storageTiers->defaultTier()->name) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy backend reused a committed restored-copy storage path.');
            }
        }

        // 8. Handle encryption: decrypt source if encrypted, re-encrypt for destination if needed.
        $encMeta = $preEncryptedMeta;
        $objectSize = $preEncryptedObjectSize ?? $writeResult->size;
        $bufferedWorkLock = null;
        try {
            $bufferedWorkLock = ! $preEncryptedDestination && $sourceEncryptionMode !== null
                ? \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire()
                : null;
            if ($this->localFencedWriter === null && $this->encryption !== null && ! $preEncryptedDestination) {
                $srcSseAlgo = $sourceEncryptionMode;

                // If the source is encrypted, decrypt the copied file back to plaintext first.
                if ($srcSseAlgo !== null) {
                    if ($srcObjectInfo->size > $this->maxEncryptedObjectSize) {
                        throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                            'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                        );
                    }
                    [$copiedCiphertext] = BufferedSsePayload::readExpected(
                        $destinationStorage->getObjectByPath($writeResult->path),
                        $srcObjectInfo->size,
                        $this->maxEncryptedObjectSize,
                    );

                    if ($srcSseAlgo === 'SSE-C') {
                        \assert($sourceCustomerKey !== null);
                        $plaintext = $this->encryption->decryptSseC(
                            $copiedCiphertext,
                            $sourceCustomerKey,
                            $srcObjectInfo->userMetadata['__sse-iv'],
                            $srcObjectInfo->userMetadata['__sse-tag'],
                        );
                    } else {
                        // Source SSE-S3.
                        $plaintext = $this->encryption->decryptSseS3(
                            $copiedCiphertext,
                            $srcObjectInfo->userMetadata['__sse-key'],
                            $srcObjectInfo->userMetadata['__sse-iv'],
                            $srcObjectInfo->userMetadata['__sse-tag'],
                        );
                    }

                    // Recompute integrity values from decrypted plaintext.
                    $calculator = new ChecksumCalculator();
                    $calculator->update($plaintext);
                    $sourcePlaintextChecksums = $calculator->finalize();
                    $etag = '"' . $sourcePlaintextChecksums->md5Hex . '"';
                    $objectSize = $sourcePlaintextChecksums->size;

                    // Replace ciphertext through the backend, so remote paths remain opaque.
                    $writeResult = $this->replaceStoredPayload(
                        $destinationStorage,
                        $dstBucket,
                        $dstKey,
                        $writeResult,
                        $plaintext,
                        $sourceStorage === $destinationStorage ? $srcPath : null,
                    );
                }

            }

            // Storage reads can succeed even when the source payload changed
            // after its metadata was committed. Never publish those bytes as a
            // successful copy with a freshly computed, misleading ETag.
            if ($objectSize !== $srcObjectInfo->size) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source size does not match its metadata.');
            }
            if (
                preg_match('/\A"([0-9a-fA-F]{32})"\z/', $srcObjectInfo->etag, $sourceMd5) === 1
                && ! hash_equals(strtolower($sourceMd5[1]), strtolower(trim($etag, '"')))
            ) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source ETag does not match its payload.');
            }
            $this->assertSourceChecksums($srcObjectInfo, $destinationStorage, $writeResult, $sourcePlaintextChecksums, $readLimit);
        } catch (\Throwable $e) {
            if ($localDispatchToken === null) {
                $this->cleanupFailedCopy($destinationStorage, $dstBucket, $dstKey, $writeResult->path, protectedPath: $sourceStorage === $destinationStorage ? $srcPath : null);
            }
            throw $e;
        } finally {
            $bufferedWorkLock?->release();
        }
        // The source GET succeeded even if destination metadata publication
        // later fails. Keep its result independent of the destination record.
        $request->setAttribute('s3.copySourceReadComplete', true);

        // 9. Store destination metadata.
        if ($metadataDirective === 'REPLACE') {
            // Use new metadata from request headers.
            $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
            $contentEncoding = $request->getHeader('content-encoding');
            $contentDisposition = $request->getHeader('content-disposition');
            $cacheControl = $request->getHeader('cache-control');
            \assert($replacementUserMetadata !== null);
            $userMetadata = $replacementUserMetadata;
        } else {
            // COPY: preserve source metadata (but strip source encryption metadata).
            $contentType = $srcObjectInfo->contentType;
            $contentEncoding = $srcObjectInfo->systemMetadata['content-encoding'] ?? null;
            $contentDisposition = $srcObjectInfo->systemMetadata['content-disposition'] ?? null;
            $cacheControl = $srcObjectInfo->systemMetadata['cache-control'] ?? null;
            $userMetadata = [];
            foreach ($srcObjectInfo->userMetadata as $k => $v) {
                if (! str_starts_with($k, '__') || $k === '__expires') {
                    $userMetadata[$k] = $v;
                }
            }
        }

        // Merge destination encryption metadata into user metadata.
        foreach ($encMeta as $k => $v) {
            $userMetadata['__' . $k] = $v;
        }

        $storageClass = $requestedStorageClass ?? $srcObjectInfo->storageClass;

        // Checksums are object integrity metadata, so a copy preserves each
        // checksum that was verified against the copied source payload above.
        // MetadataDirective only controls user metadata.
        $dstChecksumCrc32 = $srcObjectInfo->systemMetadata['checksum-crc32'] ?? null;
        $dstChecksumCrc32c = $srcObjectInfo->systemMetadata['checksum-crc32c'] ?? null;
        $dstChecksumSha1 = $srcObjectInfo->systemMetadata['checksum-sha1'] ?? null;
        $dstChecksumSha256 = $srcObjectInfo->systemMetadata['checksum-sha256'] ?? null;

        // Write metadata (with transaction for non-versioned to prevent overwrite race).
        $versionId = null;
        $oldObjectToClean = null;
        $metadataCallbackCompleted = false;
        $event = $this->notifications?->createEvent(
            's3:ObjectCreated:Copy',
            $dstBucket,
            $dstKey,
            $objectSize,
            $etag,
            $ownerId,
            request: $request,
            bucketGeneration: $dstBucketInfo->generation,
        ) ?? new S3Event('s3:ObjectCreated:Copy', $dstBucket, $dstKey, $objectSize, $etag, $ownerId);

        try {
            $dstVersioning = $this->metadata->getBucketVersioning($dstBucket);
            if ($dstVersioning === 'Enabled') {
                $this->metadata->transaction(function () use ($dstBucketInfo, $srcBucketInfo, $srcObjectInfo, $srcVersionId, $dstBucket, $dstKey, $ownerId, $objectSize, $etag, $contentType, $writeResult, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $dstChecksumCrc32, $dstChecksumCrc32c, $dstChecksumSha1, $dstChecksumSha256, $aclGrants, $tags, $policyRequestTags, $requiresTagPermission, $request, $destinationEncryptionMode, &$event, $dstVersioning, &$versionId, &$metadataCallbackCompleted, $localDispatchToken) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $dstBucketInfo->ownerId, $srcBucketInfo->ownerId);
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $dstBucketInfo, $request);
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $srcBucketInfo);
                    $bucketEncryption = $this->metadata->getBucketEncryption($dstBucket);
                    \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
                    if ($destinationEncryptionMode === null && $bucketEncryption !== null) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket default encryption changed while the copy was being committed.',
                        );
                    }
                    if (BucketOwnership::isEnforced($this->metadata, $dstBucket)) {
                        BucketOwnership::assertWriteAclAllowed($request);
                    }
                    $storedOwnerId = BucketOwnership::isEnforced($this->metadata, $dstBucket)
                        ? $dstBucketInfo->ownerId : $ownerId;
                    $storedAclGrants = BucketOwnership::isEnforced($this->metadata, $dstBucket)
                        ? AclGrantResolver::privateAcl($storedOwnerId) : $aclGrants;
                    if ($this->metadata->getBucketVersioning($dstBucket) !== $dstVersioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the copy was being committed.',
                        );
                    }
                    (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                    (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObjectInfo, $srcVersionId !== null);
                    if ($requiresTagPermission) {
                        (new AdditionalActionAuthorizer($this->metadata))->assertAllowed(
                            $request,
                            $dstBucket,
                            $dstKey,
                            's3:PutObjectTagging',
                            $policyRequestTags,
                        );
                    }
                    $publicAccessBlock = $this->metadata->getPublicAccessBlock($dstBucket);
                    if (
                        $publicAccessBlock !== null
                        && $publicAccessBlock['blockPublicAcls']
                        && AclGrantResolver::isPublic($aclGrants)
                    ) {
                        throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
                    }
                    $this->quotas?->assertCanWriteObject($storedOwnerId, $dstBucket, null, $objectSize, true);
                    $this->consumeLocalWriteIntent($localDispatchToken);

                    $versionId = $this->metadata->putObjectVersioned(
                        bucket: $dstBucket,
                        key: $dstKey,
                        ownerId: $storedOwnerId,
                        size: $objectSize,
                        etag: $etag,
                        contentType: $contentType,
                        storagePath: $writeResult->path,
                        storageClass: $storageClass,
                        contentEncoding: $contentEncoding,
                        contentDisposition: $contentDisposition,
                        cacheControl: $cacheControl,
                        userMetadata: $userMetadata,
                        checksumCrc32: $dstChecksumCrc32,
                        checksumCrc32c: $dstChecksumCrc32c,
                        checksumSha1: $dstChecksumSha1,
                        checksumSha256: $dstChecksumSha256,
                    );
                    $this->metadata->updateObjectPlacement(
                        $dstBucket,
                        $dstKey,
                        $versionId,
                        $storageClass,
                        $this->storageTiers->defaultTier()->name,
                        $writeResult->path,
                    );
                    $this->metadata->putAcl('object', $dstBucket . '/' . $dstKey, $storedOwnerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($dstBucket, $dstKey, $versionId),
                        $storedOwnerId,
                        $storedAclGrants,
                    );
                    $this->metadata->putObjectTagging($dstBucket, $dstKey, $tags, $versionId);
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $dstBucket,
                        $dstKey,
                        $versionId,
                    );
                    $event = $event->withVersionId($versionId);
                    $this->notifications?->enqueueWebhooks($event);
                    $metadataCallbackCompleted = true;
                });
            } else {
                $this->metadata->transaction(function () use ($dstBucketInfo, $srcBucketInfo, $srcObjectInfo, $srcVersionId, $dstBucket, $dstKey, $ownerId, $objectSize, $etag, $contentType, $writeResult, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, &$oldObjectToClean, $dstChecksumCrc32, $dstChecksumCrc32c, $dstChecksumSha1, $dstChecksumSha256, $aclGrants, $tags, $policyRequestTags, $requiresTagPermission, $request, $destinationEncryptionMode, $event, $dstVersioning, &$metadataCallbackCompleted, $localDispatchToken) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $dstBucketInfo->ownerId, $srcBucketInfo->ownerId);
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $dstBucketInfo, $request);
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $srcBucketInfo);
                    $bucketEncryption = $this->metadata->getBucketEncryption($dstBucket);
                    \OpsFour\S3Server\Encryption\StoredBucketEncryptionConfiguration::assertSupported($bucketEncryption);
                    if ($destinationEncryptionMode === null && $bucketEncryption !== null) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket default encryption changed while the copy was being committed.',
                        );
                    }
                    if (BucketOwnership::isEnforced($this->metadata, $dstBucket)) {
                        BucketOwnership::assertWriteAclAllowed($request);
                    }
                    $storedOwnerId = BucketOwnership::isEnforced($this->metadata, $dstBucket)
                        ? $dstBucketInfo->ownerId : $ownerId;
                    $storedAclGrants = BucketOwnership::isEnforced($this->metadata, $dstBucket)
                        ? AclGrantResolver::privateAcl($storedOwnerId) : $aclGrants;
                    if ($this->metadata->getBucketVersioning($dstBucket) !== $dstVersioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the copy was being committed.',
                        );
                    }
                    (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                    (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObjectInfo, $srcVersionId !== null);
                    if ($requiresTagPermission) {
                        (new AdditionalActionAuthorizer($this->metadata))->assertAllowed(
                            $request,
                            $dstBucket,
                            $dstKey,
                            's3:PutObjectTagging',
                            $policyRequestTags,
                        );
                    }
                    $publicAccessBlock = $this->metadata->getPublicAccessBlock($dstBucket);
                    if (
                        $publicAccessBlock !== null
                        && $publicAccessBlock['blockPublicAcls']
                        && AclGrantResolver::isPublic($aclGrants)
                    ) {
                        throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
                    }
                    $nullVersion = $this->metadata->getObjectMetadataByVersion($dstBucket, $dstKey, 'null');
                    $oldObjectToClean = $nullVersion?->isDeleteMarker === true ? null : $nullVersion;
                    if ($oldObjectToClean !== null) {
                        (new \OpsFour\S3Server\ObjectLock\ObjectLockChecker($this->metadata))
                            ->checkProtection($dstBucket, $dstKey, 'null');
                    }

                    $this->quotas?->assertCanWriteObject($storedOwnerId, $dstBucket, $oldObjectToClean, $objectSize, false);
                    $this->consumeLocalWriteIntent($localDispatchToken);

                    $this->metadata->putObjectMetadata(
                        bucket: $dstBucket,
                        key: $dstKey,
                        ownerId: $storedOwnerId,
                        size: $objectSize,
                        etag: $etag,
                        contentType: $contentType,
                        storagePath: $writeResult->path,
                        storageClass: $storageClass,
                        contentEncoding: $contentEncoding,
                        contentDisposition: $contentDisposition,
                        cacheControl: $cacheControl,
                        userMetadata: $userMetadata,
                        checksumCrc32: $dstChecksumCrc32,
                        checksumCrc32c: $dstChecksumCrc32c,
                        checksumSha1: $dstChecksumSha1,
                        checksumSha256: $dstChecksumSha256,
                    );
                    $this->metadata->updateObjectPlacement(
                        $dstBucket,
                        $dstKey,
                        null,
                        $storageClass,
                        $this->storageTiers->defaultTier()->name,
                        $writeResult->path,
                    );
                    $this->metadata->putAcl('object', $dstBucket . '/' . $dstKey, $storedOwnerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($dstBucket, $dstKey, null),
                        $storedOwnerId,
                        $storedAclGrants,
                    );
                    $this->metadata->putObjectTagging($dstBucket, $dstKey, $tags);
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $dstBucket,
                        $dstKey,
                        null,
                    );
                    if ($oldObjectToClean !== null) {
                        $this->queueOldObjectCleanup($oldObjectToClean, $writeResult->path);
                    }
                    $this->notifications?->enqueueWebhooks($event);
                    $metadataCallbackCompleted = true;
                });
            }
        } catch (\Throwable $e) {
            // A transaction can commit and then lose its acknowledgement.
            // Never delete bytes that the committed destination now points to.
            // An empty immediate lookup cannot rule out a COMMIT still in
            // flight. Once the callback returned, retain the path regardless
            // of whether an immediate read can already see publication.
            // The transaction can throw while committing after its callback
            // has set this by-reference safety flag.
            if (! $metadataCallbackCompleted && $localDispatchToken === null) {
                $this->cleanupFailedCopy($destinationStorage, $dstBucket, $dstKey, $writeResult->path, $versionId);
            }
            throw $e;
        }

        // Clean up old storage file on overwrite (non-versioned only).
        if ($oldObjectToClean !== null) {
            $this->deleteStoredData($oldObjectToClean, $writeResult->path);
        }

        // 10. Build CopyObjectResult XML.
        $lastModified = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.000\Z');

        $xml = XmlResponseBuilder::copyObjectResult(
            $etag,
            $lastModified,
            $dstChecksumCrc32,
            $dstChecksumCrc32c,
            $dstChecksumSha1,
            $dstChecksumSha256,
        );

        // 11. Dispatch event notification.
        $this->notifications?->dispatchInternalEvent($event);

        $responseHeaders = ['Content-Type' => 'application/xml'];

        if ($versionId !== null || $dstVersioning === 'Suspended') {
            $responseHeaders['x-amz-version-id'] = $versionId ?? 'null';
        }
        if ($srcObjectInfo->versionId !== null || $srcVersioning !== '') {
            $responseHeaders['x-amz-copy-source-version-id'] = $srcObjectInfo->versionId ?? 'null';
        }

        // Encryption response headers.
        $dstSseAlgo = $encMeta['sse-algorithm'] ?? null;
        if ($dstSseAlgo === 'SSE-C') {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $responseHeaders['x-amz-server-side-encryption-customer-key-MD5']
                = $encMeta['sse-customer-key-md5'];
        } elseif ($dstSseAlgo === 'AES256') {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
            body: $xml,
        );
    }

    /**
     * @return array{StorageWriteResult, string, int, array<string, string>, ChecksumResult}
     */
    private function writeEncryptedCopy(
        Request $request,
        ObjectInfo $source,
        StorageBackend $sourceStorage,
        string $sourcePath,
        ?string $sourceEncryptionMode,
        ?string $sourceCustomerKey,
        StorageBackend $destinationStorage,
        string $destinationBucket,
        string $destinationKey,
        string $destinationEncryptionMode,
    ): array {
        EncryptionRequestResolver::requireEncryptionService($this->encryption);
        \assert($this->encryption !== null);
        if ($source->size > $this->maxEncryptedObjectSize) {
            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
            );
        }

        $lock = \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire();
        try {
            [$physicalPayload, $checksums] = BufferedSsePayload::readExpected(
                $sourceStorage->getObjectByPath($sourcePath),
                $source->size,
                $this->maxEncryptedObjectSize,
            );
            if ($sourceEncryptionMode === EncryptionRequestResolver::SSE_C) {
                \assert($sourceCustomerKey !== null);
                $plaintext = $this->encryption->decryptSseC(
                    $physicalPayload,
                    $sourceCustomerKey,
                    $source->userMetadata['__sse-iv'],
                    $source->userMetadata['__sse-tag'],
                );
            } elseif ($sourceEncryptionMode === EncryptionRequestResolver::SSE_S3) {
                $plaintext = $this->encryption->decryptSseS3(
                    $physicalPayload,
                    $source->userMetadata['__sse-key'],
                    $source->userMetadata['__sse-iv'],
                    $source->userMetadata['__sse-tag'],
                );
            } else {
                $plaintext = $physicalPayload;
            }

            if ($sourceEncryptionMode !== null) {
                $calculator = new ChecksumCalculator();
                $calculator->update($plaintext);
                $checksums = $calculator->finalize();
            }
            if ($checksums->size !== $source->size) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source size does not match its metadata.');
            }
            if (preg_match('/\A"([0-9a-fA-F]{32})"\z/', $source->etag, $sourceMd5) === 1
                && !hash_equals(strtolower($sourceMd5[1]), strtolower($checksums->md5Hex))) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source ETag does not match its payload.');
            }

            if ($destinationEncryptionMode === EncryptionRequestResolver::SSE_C) {
                $algorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
                $encodedKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
                $keyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                \assert($algorithm !== null && $encodedKey !== null && $keyMd5 !== null);
                $customerKey = EncryptionService::validateSseCHeaders($algorithm, $encodedKey, $keyMd5);
                $encrypted = $this->encryption->encryptSseC($plaintext, $customerKey);
                $encryptionMetadata = [
                    'sse-algorithm' => 'SSE-C',
                    'sse-iv' => $encrypted['iv'],
                    'sse-tag' => $encrypted['tag'],
                    'sse-customer-key-md5' => $keyMd5,
                ];
            } else {
                $encrypted = $this->encryption->encryptSseS3($plaintext);
                $encryptionMetadata = [
                    'sse-algorithm' => 'AES256',
                    'sse-key' => $encrypted['encryptedDataKey'],
                    'sse-iv' => $encrypted['iv'],
                    'sse-tag' => $encrypted['tag'],
                ];
            }

            $write = $destinationStorage->putObject(
                $destinationBucket,
                $destinationKey,
                new \Amp\ByteStream\ReadableBuffer($encrypted['ciphertext']),
            );
            if ($write->size !== strlen($encrypted['ciphertext'])
                || ! hash_equals(md5($encrypted['ciphertext']), strtolower($write->md5Hex))) {
                if ($sourceStorage !== $destinationStorage || $write->path !== $sourcePath) {
                    $this->cleanupFailedCopy($destinationStorage, $destinationBucket, $destinationKey, $write->path);
                }
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Encrypted copy write does not match its payload.');
            }

            return [$write, '"' . $checksums->md5Hex . '"', $checksums->size, $encryptionMetadata, $checksums];
        } finally {
            $lock->release();
        }
    }

    private function assertSourceChecksums(
        ObjectInfo $source,
        StorageBackend $destinationStorage,
        StorageWriteResult $write,
        ?ChecksumResult $plaintextChecksums,
        int $readLimit,
    ): void {
        $expected = [
            'crc32' => $source->systemMetadata['checksum-crc32'] ?? null,
            'crc32c' => $source->systemMetadata['checksum-crc32c'] ?? null,
            'sha1' => $source->systemMetadata['checksum-sha1'] ?? null,
            'sha256' => $source->systemMetadata['checksum-sha256'] ?? null,
        ];
        if ($expected === ['crc32' => null, 'crc32c' => null, 'sha1' => null, 'sha256' => null]) {
            return;
        }

        $actual = $plaintextChecksums !== null
            ? [
                'crc32' => $plaintextChecksums->crc32Base64(),
                'crc32c' => $plaintextChecksums->crc32cBase64(),
                'sha1' => $plaintextChecksums->sha1Base64(),
                'sha256' => $plaintextChecksums->sha256Base64(),
            ]
            : [
                'crc32' => $write->crc32Base64,
                'crc32c' => $write->crc32cBase64,
                'sha1' => $write->sha1Base64,
                'sha256' => $write->sha256Base64,
            ];

        // Custom storage backends may report only size and MD5. In that case,
        // hash the bounded destination copy before preserving source metadata.
        foreach ($expected as $algorithm => $value) {
            if ($value === null || $actual[$algorithm] !== null) {
                continue;
            }
            $calculator = new ChecksumCalculator();
            $stream = new LimitedReadableStream($destinationStorage->getObjectByPath($write->path), $readLimit);
            try {
                while (($chunk = $stream->read()) !== null) {
                    $calculator->update($chunk);
                }
            } finally {
                $stream->close();
            }
            $checksums = $calculator->finalize();
            if ($checksums->size !== $source->size) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source size does not match its metadata.');
            }
            $actual = [
                'crc32' => $checksums->crc32Base64(),
                'crc32c' => $checksums->crc32cBase64(),
                'sha1' => $checksums->sha1Base64(),
                'sha256' => $checksums->sha256Base64(),
            ];
            break;
        }

        foreach ($expected as $algorithm => $value) {
            if ($value !== null && ($actual[$algorithm] === null || ! hash_equals($value, $actual[$algorithm]))) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy source checksum does not match its payload.');
            }
        }
    }

    private function queueOldObjectCleanup(ObjectInfo $object, string $preservePath): void
    {
        foreach ($this->oldObjectPathsToClean($object, $preservePath) as [$tier, $path]) {
            $this->metadata->enqueueStorageGarbage($object->bucket, $tier, $path);
        }
    }

    private function deleteStoredData(ObjectInfo $object, string $preservePath): void
    {
        foreach ($this->oldObjectPathsToClean($object, $preservePath) as [$tier, $path]) {
            try {
                \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                    $this->metadata,
                    $this->storageTiers->tier($tier)->backend,
                    $object->bucket,
                    $tier,
                    $path,
                );
            } catch (\Throwable) {
                // The transaction already queued this exact path for the collector.
            }
        }
    }

    /** @return list<array{string, string}> */
    private function oldObjectPathsToClean(ObjectInfo $object, string $preservePath): array
    {
        $destinationTier = $this->storageTiers->defaultTier()->name;
        $paths = [];
        $path = $object->systemMetadata['storagePath'] ?? null;
        if ($path !== null && $path !== '' && ($path !== $preservePath || $object->storageTier !== $destinationTier)) {
            $paths[] = [$object->storageTier, $path];
        }
        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            $restoreTier = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($object, $this->storageTiers);
            if ($object->restoredStoragePath !== $preservePath || $restoreTier->name !== $destinationTier) {
                $paths[] = [$restoreTier->name, $object->restoredStoragePath];
            }
        }

        return $paths;
    }

    private function replaceStoredPayload(
        StorageBackend $storage,
        string $bucket,
        string $key,
        \OpsFour\S3Server\Storage\StorageWriteResult $original,
        string $payload,
        ?string $protectedSourcePath,
    ): \OpsFour\S3Server\Storage\StorageWriteResult {
        $replacement = $storage->putObject(
            $bucket,
            $key,
            new \Amp\ByteStream\ReadableBuffer($payload),
        );

        if ($replacement->path === $protectedSourcePath) {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Copy backend reused the source storage path.');
        }

        try {
            if ($replacement->path !== $original->path) {
                \OpsFour\S3Server\Storage\DurableStorageDelete::runRequired(
                    $this->metadata,
                    $storage,
                    $bucket,
                    $this->storageTiers->defaultTier()->name,
                    $original->path,
                );
            }
        } catch (\Throwable $e) {
            $this->cleanupFailedCopy($storage, $bucket, $key, $replacement->path, protectedPath: $protectedSourcePath);
            throw $e;
        }

        return $replacement;
    }

    /**
     * A failed acknowledgement is not proof that metadata rolled back. If a
     * reference check fails, retaining an orphan is safer than erasing a live
     * object. Garbage collection can address such orphans separately.
     */
    private function cleanupFailedCopy(
        StorageBackend $storage,
        string $bucket,
        string $key,
        string $path,
        ?string $versionId = null,
        ?string $protectedPath = null,
    ): void {
        if ($path === $protectedPath) {
            return;
        }
        try {
            $references = [
                $this->metadata->getCurrentObjectVersion($bucket, $key),
                $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null'),
            ];
            if ($versionId !== null) {
                $references[] = $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
            }
            foreach ($references as $object) {
                if ($object !== null && $object->storageTier === $this->storageTiers->defaultTier()->name && ($object->systemMetadata['storagePath'] ?? null) === $path) {
                    return;
                }
                if ($object !== null && $object->restoredStoragePath === $path
                    && \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($object, $this->storageTiers)->name === $this->storageTiers->defaultTier()->name) {
                    return;
                }
            }
        } catch (\Throwable) {
            return;
        }

        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $storage,
            $bucket,
            $this->storageTiers->defaultTier()->name,
            $path,
        );
    }

}
