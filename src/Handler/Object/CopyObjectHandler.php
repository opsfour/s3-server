<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\PreconditionFailedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Security\ObjectReadAuthorizer;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles CopyObject (PUT /{bucket}/{key} with x-amz-copy-source header).
 *
 * Copies an object from a source bucket/key to a destination bucket/key.
 * Supports:
 * - x-amz-metadata-directive: COPY (default) or REPLACE
 * - Conditional copy: x-amz-copy-source-if-match, x-amz-copy-source-if-none-match,
 *   x-amz-copy-source-if-modified-since, x-amz-copy-source-if-unmodified-since
 * - Cross-bucket copies (within the same owner)
 */
final class CopyObjectHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
        $dstBucket = $request->getAttribute('s3.bucket');
        $dstKey = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify destination bucket exists and owner matches.
        $dstBucketInfo = $this->metadata->getBucket($dstBucket);
        if ($dstBucketInfo === null || $dstBucketInfo->ownerId !== $ownerId) {
            throw new NoSuchBucketException();
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
        $copySource = $request->getHeader('x-amz-copy-source');
        if ($copySource === null || $copySource === '') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException('Missing x-amz-copy-source header.');
        }

        [$srcBucket, $srcKey, $srcVersionId] = self::parseCopySource($copySource);

        // 3. Verify source bucket exists.
        $srcBucketInfo = $this->metadata->getBucket($srcBucket);
        if ($srcBucketInfo === null) {
            throw new NoSuchKeyException();
        }

        // 4. Get source object metadata (optionally a specific version).
        $srcObjectInfo = ($srcVersionId !== null)
            ? $this->metadata->getObjectMetadataByVersion($srcBucket, $srcKey, $srcVersionId)
            : $this->metadata->getObjectMetadata($srcBucket, $srcKey);
        if ($srcObjectInfo === null || $srcObjectInfo->isDeleteMarker) {
            throw new NoSuchKeyException();
        }
        (new ObjectReadAuthorizer($this->metadata))->assertAllowed($request, $srcObjectInfo);
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
        self::evaluateCopyConditionals($request, $srcObjectInfo);

        // 6. Determine metadata directive.
        $metadataDirective = strtoupper($request->getHeader('x-amz-metadata-directive') ?? 'COPY');

        // Reject self-copy without metadata change (AWS returns InvalidRequest).
        if ($srcBucket === $dstBucket && $srcKey === $dstKey
            && $metadataDirective === 'COPY' && $srcVersionId === null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'This copy request is illegal because it is trying to copy an object to itself '
                . 'without changing the object\'s metadata, storage class, website redirect location, '
                . 'or encryption attributes.',
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
            $sourceStorage = $this->storageTiers->defaultBackend();
            $srcPath = $srcObjectInfo->restoredStoragePath;
        }
        $destinationStorage = $this->storageTiers->defaultBackend();

        if ($sourceStorage === $destinationStorage) {
            $writeResult = $destinationStorage->copyObject($srcPath, $dstBucket, $dstKey);
        } else {
            // Fallback: read source and write to destination.
            $stream = $sourceStorage->getObjectByPath($srcPath);
            $writeResult = $destinationStorage->putObject($dstBucket, $dstKey, $stream);
        }

        $etag = '"' . $writeResult->md5Hex . '"';

        // 8. Handle encryption: decrypt source if encrypted, re-encrypt for destination if needed.
        $encMeta = [];
        $objectSize = $writeResult->size;
        $bufferedWorkLock = ($sourceEncryptionMode !== null || $destinationEncryptionMode !== null)
            ? \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire()
            : null;

        try {
            if ($this->encryption !== null) {
                $srcSseAlgo = $sourceEncryptionMode;

                // If the source is encrypted, decrypt the copied file back to plaintext first.
                if ($srcSseAlgo !== null) {
                    if ($srcObjectInfo->size > $this->maxEncryptedObjectSize) {
                        throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                            'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                        );
                    }
                    $copiedCiphertext = \Amp\ByteStream\buffer(
                        $destinationStorage->getObjectByPath($writeResult->path),
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

                    // Recompute ETag and size from decrypted plaintext.
                    $etag = '"' . md5($plaintext) . '"';
                    $objectSize = strlen($plaintext);

                    // Replace ciphertext through the backend, so remote paths remain opaque.
                    $writeResult = $this->replaceStoredPayload(
                        $destinationStorage,
                        $dstBucket,
                        $dstKey,
                        $writeResult,
                        $plaintext,
                    );
                }

                // Now apply destination encryption (SSE-C or SSE-S3).
                if ($destinationEncryptionMode === EncryptionRequestResolver::SSE_C) {
                    $dstSseCAlgo = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
                    $dstSseCKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
                    $dstSseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                    \assert($dstSseCAlgo !== null && $dstSseCKey !== null && $dstSseCKeyMd5 !== null);
                    // Destination SSE-C.
                    if ($objectSize > $this->maxEncryptedObjectSize) {
                        throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                            'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                        );
                    }
                    $customerKey = EncryptionService::validateSseCHeaders($dstSseCAlgo, $dstSseCKey, $dstSseCKeyMd5);
                    $plain = \Amp\ByteStream\buffer($destinationStorage->getObjectByPath($writeResult->path));
                    $enc = $this->encryption->encryptSseC($plain, $customerKey);
                    $writeResult = $this->replaceStoredPayload(
                        $destinationStorage,
                        $dstBucket,
                        $dstKey,
                        $writeResult,
                        $enc['ciphertext'],
                    );

                    $encMeta = [
                        'sse-algorithm' => 'SSE-C',
                        'sse-iv' => $enc['iv'],
                        'sse-tag' => $enc['tag'],
                        'sse-customer-key-md5' => $dstSseCKeyMd5,
                    ];
                } else {
                    if ($destinationEncryptionMode === EncryptionRequestResolver::SSE_S3) {
                        if ($objectSize > $this->maxEncryptedObjectSize) {
                            throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                                'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                            );
                        }
                        $plain = \Amp\ByteStream\buffer($destinationStorage->getObjectByPath($writeResult->path));
                        $enc = $this->encryption->encryptSseS3($plain);
                        $writeResult = $this->replaceStoredPayload(
                            $destinationStorage,
                            $dstBucket,
                            $dstKey,
                            $writeResult,
                            $enc['ciphertext'],
                        );

                        $encMeta = [
                            'sse-algorithm' => 'AES256',
                            'sse-key' => $enc['encryptedDataKey'],
                            'sse-iv' => $enc['iv'],
                            'sse-tag' => $enc['tag'],
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run(
                $this->metadata,
                $destinationStorage,
                $dstBucket,
                $this->storageTiers->defaultTier()->name,
                $writeResult->path,
            );
            throw $e;
        } finally {
            $bufferedWorkLock?->release();
        }

        // 9. Store destination metadata.
        if ($metadataDirective === 'REPLACE') {
            // Use new metadata from request headers.
            $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
            $contentEncoding = $request->getHeader('content-encoding');
            $contentDisposition = $request->getHeader('content-disposition');
            $cacheControl = $request->getHeader('cache-control');
            $userMetadata = UserMetadataExtractor::extract($request);
        } else {
            // COPY: preserve source metadata (but strip source encryption metadata).
            $contentType = $srcObjectInfo->contentType;
            $contentEncoding = $srcObjectInfo->systemMetadata['content-encoding'] ?? null;
            $contentDisposition = $srcObjectInfo->systemMetadata['content-disposition'] ?? null;
            $cacheControl = $srcObjectInfo->systemMetadata['cache-control'] ?? null;
            $userMetadata = [];
            foreach ($srcObjectInfo->userMetadata as $k => $v) {
                if (! str_starts_with($k, '__')) {
                    $userMetadata[$k] = $v;
                }
            }
        }

        // Merge destination encryption metadata into user metadata.
        foreach ($encMeta as $k => $v) {
            $userMetadata['__' . $k] = $v;
        }

        $storageClass = $request->getHeader('x-amz-storage-class') ?? $srcObjectInfo->storageClass;

        // Determine destination checksums.
        // For COPY directive: preserve source checksums from metadata.
        // For REPLACE directive: use freshly computed checksums from the copy write.
        if ($metadataDirective === 'COPY') {
            $dstChecksumCrc32 = $srcObjectInfo->systemMetadata['checksum-crc32'] ?? null;
            $dstChecksumCrc32c = $srcObjectInfo->systemMetadata['checksum-crc32c'] ?? null;
            $dstChecksumSha1 = $srcObjectInfo->systemMetadata['checksum-sha1'] ?? null;
            $dstChecksumSha256 = $srcObjectInfo->systemMetadata['checksum-sha256'] ?? null;
        } else {
            // REPLACE: no checksums unless client sends them (not supported on CopyObject).
            $dstChecksumCrc32 = null;
            $dstChecksumCrc32c = null;
            $dstChecksumSha1 = null;
            $dstChecksumSha256 = null;
        }

        // Write metadata (with transaction for non-versioned to prevent overwrite race).
        $dstVersioning = $this->metadata->getBucketVersioning($dstBucket);
        $versionId = null;
        $oldObjectToClean = null;
        $event = $this->notifications?->createEvent(
            's3:ObjectCreated:Copy',
            $dstBucket,
            $dstKey,
            $objectSize,
            $etag,
            $ownerId,
        ) ?? new S3Event('s3:ObjectCreated:Copy', $dstBucket, $dstKey, $objectSize, $etag, $ownerId);

        try {
            if ($dstVersioning === 'Enabled') {
                $this->metadata->transaction(function () use ($dstBucketInfo, $dstBucket, $dstKey, $ownerId, $objectSize, $etag, $contentType, $writeResult, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, $dstChecksumCrc32, $dstChecksumCrc32c, $dstChecksumSha1, $dstChecksumSha256, $aclGrants, $request, $event, $dstVersioning, &$versionId) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $dstBucketInfo->ownerId);
                    if ($this->metadata->getBucketVersioning($dstBucket) !== $dstVersioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the copy was being committed.',
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
                    $this->quotas?->assertCanWriteObject($ownerId, $dstBucket, null, $objectSize, true);

                    $versionId = $this->metadata->putObjectVersioned(
                        bucket: $dstBucket,
                        key: $dstKey,
                        ownerId: $ownerId,
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
                    $this->metadata->putAcl('object', $dstBucket . '/' . $dstKey, $ownerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($dstBucket, $dstKey, $versionId),
                        $ownerId,
                        $aclGrants,
                    );
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $dstBucket,
                        $dstKey,
                        $versionId,
                    );
                    $this->notifications?->enqueueWebhooks($event);
                });
            } else {
                $this->metadata->transaction(function () use ($dstBucketInfo, $dstBucket, $dstKey, $ownerId, $objectSize, $etag, $contentType, $writeResult, $storageClass, $contentEncoding, $contentDisposition, $cacheControl, $userMetadata, &$oldObjectToClean, $dstChecksumCrc32, $dstChecksumCrc32c, $dstChecksumSha1, $dstChecksumSha256, $aclGrants, $request, $event, $dstVersioning) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $dstBucketInfo->ownerId);
                    if ($this->metadata->getBucketVersioning($dstBucket) !== $dstVersioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the copy was being committed.',
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
                    $existingObj = $this->metadata->getObjectMetadata($dstBucket, $dstKey);
                    $oldObjectToClean = $existingObj;

                    $this->quotas?->assertCanWriteObject($ownerId, $dstBucket, $existingObj, $objectSize, false);

                    $this->metadata->putObjectMetadata(
                        bucket: $dstBucket,
                        key: $dstKey,
                        ownerId: $ownerId,
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
                    $this->metadata->putAcl('object', $dstBucket . '/' . $dstKey, $ownerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($dstBucket, $dstKey, null),
                        $ownerId,
                        $aclGrants,
                    );
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $dstBucket,
                        $dstKey,
                        null,
                    );
                    $this->notifications?->enqueueWebhooks($event);
                });
            }
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run(
                $this->metadata,
                $destinationStorage,
                $dstBucket,
                $this->storageTiers->defaultTier()->name,
                $writeResult->path,
            );
            throw $e;
        }

        // Clean up old storage file on overwrite (non-versioned only).
        if ($oldObjectToClean !== null) {
            $this->deleteStoredData($oldObjectToClean, $writeResult->path);
        }

        // 10. Build CopyObjectResult XML.
        $lastModified = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.000\Z');

        $xml = XmlResponseBuilder::copyObjectResult($etag, $lastModified);

        // 11. Dispatch event notification.
        $this->notifications?->dispatchInternalEvent($event);

        $responseHeaders = ['Content-Type' => 'application/xml'];

        if ($versionId !== null) {
            $responseHeaders['x-amz-version-id'] = $versionId;
        }
        if ($srcVersionId !== null) {
            $responseHeaders['x-amz-copy-source-version-id'] = $srcVersionId;
        }

        // Encryption response headers.
        $dstSseAlgo = $encMeta['sse-algorithm'] ?? null;
        if ($dstSseAlgo === 'SSE-C') {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
        } elseif ($dstSseAlgo === 'AES256') {
            $responseHeaders['x-amz-server-side-encryption'] = 'AES256';
        }

        return new Response(
            status: 200,
            headers: $responseHeaders,
            body: $xml,
        );
    }

    private function deleteStoredData(ObjectInfo $object, string $preservePath): void
    {
        $path = $object->systemMetadata['storagePath'] ?? null;
        if ($path !== null && $path !== '' && $path !== $preservePath) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run($this->metadata, $this->storageTiers->tier($object->storageTier)->backend, $object->bucket, $object->storageTier, $path);
        }

        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '' && $object->restoredStoragePath !== $preservePath) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run($this->metadata, $this->storageTiers->defaultBackend(), $object->bucket, $this->storageTiers->defaultTier()->name, $object->restoredStoragePath);
        }
    }

    private function replaceStoredPayload(
        StorageBackend $storage,
        string $bucket,
        string $key,
        \OpsFour\S3Server\Storage\StorageWriteResult $original,
        string $payload,
    ): \OpsFour\S3Server\Storage\StorageWriteResult {
        $replacement = $storage->putObject(
            $bucket,
            $key,
            new \Amp\ByteStream\ReadableBuffer($payload),
        );

        try {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run(
                $this->metadata,
                $storage,
                $bucket,
                $this->storageTiers->defaultTier()->name,
                $original->path,
            );
        } catch (\Throwable $e) {
            \OpsFour\S3Server\Storage\DurableStorageDelete::run(
                $this->metadata,
                $storage,
                $bucket,
                $this->storageTiers->defaultTier()->name,
                $replacement->path,
            );
            throw $e;
        }

        return $replacement;
    }

    /**
     * Parse the x-amz-copy-source header into bucket and key.
     *
     * Format: /bucket/key or /bucket/key?versionId=xxx
     * Also handles URL-encoded paths.
     *
     * @return array{0: string, 1: string, 2: string|null} [bucket, key, versionId]
     */
    private static function parseCopySource(string $copySource): array
    {
        // Parse and preserve ?versionId query parameter.
        $versionId = null;
        $qPos = strpos($copySource, '?');
        if ($qPos !== false) {
            parse_str(substr($copySource, $qPos + 1), $queryParams);
            $versionId = isset($queryParams['versionId']) && is_string($queryParams['versionId'])
                ? $queryParams['versionId']
                : null;
            $copySource = substr($copySource, 0, $qPos);
        }

        // URL-decode the source path.
        $copySource = rawurldecode($copySource);

        // Strip leading slash.
        $copySource = ltrim($copySource, '/');

        // Split on the first slash: bucket/key
        $slashPos = strpos($copySource, '/');
        if ($slashPos === false) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid x-amz-copy-source: must be in the format /bucket/key.',
            );
        }

        $bucket = substr($copySource, 0, $slashPos);
        $key = substr($copySource, $slashPos + 1);

        if ($bucket === '' || $key === '') {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid x-amz-copy-source: bucket and key must not be empty.',
            );
        }

        return [$bucket, $key, $versionId];
    }

    /**
     * Evaluate conditional copy headers against source object metadata.
     */
    private static function evaluateCopyConditionals(Request $request, ObjectInfo $srcObject): void
    {
        $ifMatch = $request->getHeader('x-amz-copy-source-if-match');
        $ifNoneMatch = $request->getHeader('x-amz-copy-source-if-none-match');
        $ifModifiedSince = $request->getHeader('x-amz-copy-source-if-modified-since');
        $ifUnmodifiedSince = $request->getHeader('x-amz-copy-source-if-unmodified-since');

        if ($ifMatch !== null) {
            $normalizedEtag = trim($srcObject->etag, '"');
            $candidate = trim($ifMatch, '"');
            if ($candidate !== '*' && $candidate !== $normalizedEtag) {
                throw new PreconditionFailedException();
            }
        }

        if ($ifUnmodifiedSince !== null && $ifMatch === null) {
            $sinceTime = strtotime($ifUnmodifiedSince);
            if ($sinceTime !== false && $srcObject->lastModified->getTimestamp() > $sinceTime) {
                throw new PreconditionFailedException();
            }
        }

        if ($ifNoneMatch !== null) {
            $normalizedEtag = trim($srcObject->etag, '"');
            $candidate = trim($ifNoneMatch, '"');
            if ($candidate === '*' || $candidate === $normalizedEtag) {
                throw new PreconditionFailedException(
                    'At least one of the pre-conditions you specified did not hold.',
                );
            }
        }

        if ($ifModifiedSince !== null && $ifNoneMatch === null) {
            $sinceTime = strtotime($ifModifiedSince);
            if ($sinceTime !== false && $srcObject->lastModified->getTimestamp() <= $sinceTime) {
                throw new PreconditionFailedException(
                    'At least one of the pre-conditions you specified did not hold.',
                );
            }
        }
    }

}
