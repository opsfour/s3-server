<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\ByteStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\EntityTooSmallException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Exception\InvalidPartException;
use OpsFour\S3Server\Exception\InvalidPartOrderException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Xml\XmlRequestParser;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class CompleteMultipartUploadHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly bool $enforceMinPartSize = false,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
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
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }
        $uploadEncryptionMode = $upload['user_metadata']['__sse-algorithm'] ?? null;
        if ($uploadEncryptionMode !== null) {
            EncryptionRequestResolver::requireEncryptionService($this->encryption);
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
            if (trim($etag, '"') !== trim($storedEtag, '"')) {
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

        // Assemble parts in storage.
        try {
            $writeResult = $this->storage->assembleMultipartUpload($bucket, $key, $uploadId, $validatedParts);
        } catch (InternalErrorException $e) {
            // If assembly fails, check whether a concurrent AbortMultipartUpload
            // deleted the upload. Give the client a clear 404 instead of a 500.
            if ($this->metadata->getMultipartUpload($uploadId) === null) {
                throw new NoSuchUploadException();
            }
            throw $e;
        }

        // Composite ETag: md5-of-concatenated-part-md5s + "-" + partCount (AWS S3 format).
        $partMd5s = '';
        foreach ($validatedParts as $vp) {
            $hexEtag = trim($vp['etag'], '"');
            $bin = ctype_xdigit($hexEtag) ? hex2bin($hexEtag) : false;
            if ($bin === false) {
                throw new InternalErrorException(
                    'Part ETag is not a valid hex string.',
                );
            }
            $partMd5s .= $bin;
        }
        $etag = '"' . md5($partMd5s) . '-' . count($validatedParts) . '"';

        // Encrypt the assembled object if encryption was specified in the upload metadata.
        $userMetadata = $upload['user_metadata'];
        $encMeta = [];
        $bufferedWorkLock = $uploadEncryptionMode !== null
            ? \OpsFour\S3Server\Runtime\BufferedWorkLimiter::acquire()
            : null;

        try {
            if ($this->encryption !== null) {
                $uploadSseAlgo = $uploadEncryptionMode;

                if ($uploadSseAlgo === 'SSE-C') {
                    $sseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');
                    \assert($completionCustomerKey !== null && $sseCKeyMd5 !== null);

                    if ($writeResult->size > $this->maxEncryptedObjectSize) {
                        throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                            'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                        );
                    }
                    $plaintext = \Amp\ByteStream\buffer($this->storage->getObjectByPath($writeResult->path));
                    $enc = $this->encryption->encryptSseC($plaintext, $completionCustomerKey);
                    $writeResult = $this->replaceStoredPayload($bucket, $key, $writeResult, $enc['ciphertext']);

                    $encMeta = [
                        'sse-algorithm' => 'SSE-C',
                        'sse-iv' => $enc['iv'],
                        'sse-tag' => $enc['tag'],
                        'sse-customer-key-md5' => $sseCKeyMd5,
                    ];
                } elseif ($uploadSseAlgo === 'AES256') {
                    // SSE-S3.
                    if ($writeResult->size > $this->maxEncryptedObjectSize) {
                        throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                            'Object exceeds max size for server-side encryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                        );
                    }
                    $plaintext = \Amp\ByteStream\buffer($this->storage->getObjectByPath($writeResult->path));
                    $enc = $this->encryption->encryptSseS3($plaintext);
                    $writeResult = $this->replaceStoredPayload($bucket, $key, $writeResult, $enc['ciphertext']);

                    $encMeta = [
                        'sse-algorithm' => 'AES256',
                        'sse-key' => $enc['encryptedDataKey'],
                        'sse-iv' => $enc['iv'],
                        'sse-tag' => $enc['tag'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->deleteUncommittedPath($bucket, $writeResult->path);
            throw $e;
        } finally {
            $bufferedWorkLock?->release();
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
        $contentDisposition = $userMetadata['__mpu-content-disposition'] ?? null;
        $cacheControl = $userMetadata['__mpu-cache-control'] ?? null;
        $storageClass = $userMetadata['__mpu-storage-class'] ?? 'STANDARD';
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
            $this->deleteUncommittedPath($bucket, $writeResult->path);
            throw new InternalErrorException('Multipart upload metadata is invalid.', $e);
        }

        // Remove internal __mpu-* keys from persisted user metadata.
        unset(
            $userMetadata['__mpu-content-encoding'],
            $userMetadata['__mpu-content-disposition'],
            $userMetadata['__mpu-cache-control'],
            $userMetadata['__mpu-storage-class'],
            $userMetadata['__mpu-acl-grants'],
            $userMetadata['__mpu-tags'],
            $userMetadata['__object-lock-mode'],
            $userMetadata['__object-lock-retain-until-date'],
            $userMetadata['__object-lock-legal-hold'],
        );

        // Store final object metadata (versioning-aware).
        $versioning = $this->metadata->getBucketVersioning($bucket);
        $versionId = null;
        $oldObjectToClean = null;
        $cleanupParts = [];
        $event = $this->notifications?->createEvent(
            's3:ObjectCreated:CompleteMultipartUpload',
            $bucket,
            $key,
            $writeResult->size,
            $etag,
            $ownerId,
        ) ?? new S3Event(
            's3:ObjectCreated:CompleteMultipartUpload',
            $bucket,
            $key,
            $writeResult->size,
            $etag,
            $ownerId,
        );

        try {
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
                    $request,
                    $tags,
                    $objectLockHeaders,
                    $uploadId,
                    $event,
                    $versioning,
                    &$versionId,
                    &$cleanupParts,
                ) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                    if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the multipart upload was being committed.',
                        );
                    }
                    $this->assertUploadIsActive($uploadId, $bucket, $key, $ownerId);
                    $this->quotas?->assertCanWriteObject(
                        $ownerId,
                        $bucket,
                        null,
                        $writeResult->size,
                        true,
                        $upload['upload_id'],
                    );

                    $versionId = $this->metadata->putObjectVersioned(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $ownerId,
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
                    $this->metadata->putAcl('object', $bucket . '/' . $key, $ownerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($bucket, $key, $versionId),
                        $ownerId,
                        $aclGrants,
                    );
                    $this->metadata->putObjectTagging($bucket, $key, $tags, $versionId);
                    (new ObjectLockRequestApplier($this->metadata))->apply(
                        $request,
                        $bucket,
                        $key,
                        $versionId,
                        $objectLockHeaders,
                    );
                    $this->notifications?->enqueueWebhooks($event);
                    $cleanupParts = MultipartCleanup::stage(
                        $this->metadata,
                        $bucket,
                        $key,
                        $uploadId,
                        $ownerId,
                        $this->storageTiers->defaultTier()->name,
                    );
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
                    $request,
                    $tags,
                    $uploadId,
                    $event,
                    $versioning,
                    &$oldObjectToClean,
                    &$cleanupParts,
                ) {
                    \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                    if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                        throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                            'Bucket versioning changed while the multipart upload was being committed.',
                        );
                    }
                    $this->assertUploadIsActive($uploadId, $bucket, $key, $ownerId);
                    $existingObj = $this->metadata->getObjectMetadata($bucket, $key);
                    $oldObjectToClean = $existingObj;

                    $this->quotas?->assertCanWriteObject(
                        $ownerId,
                        $bucket,
                        $existingObj,
                        $writeResult->size,
                        false,
                        $upload['upload_id'],
                    );

                    $this->metadata->putObjectMetadata(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $ownerId,
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
                    $this->metadata->putAcl('object', $bucket . '/' . $key, $ownerId, []);
                    $this->metadata->putAcl(
                        'object',
                        ObjectVersionResolver::aclResourceName($bucket, $key, null),
                        $ownerId,
                        $aclGrants,
                    );
                    $this->metadata->putObjectTagging($bucket, $key, $tags);
                    (new ObjectLockRequestApplier($this->metadata))->apply($request, $bucket, $key, null);
                    $this->notifications?->enqueueWebhooks($event);
                    $cleanupParts = MultipartCleanup::stage(
                        $this->metadata,
                        $bucket,
                        $key,
                        $uploadId,
                        $ownerId,
                        $this->storageTiers->defaultTier()->name,
                    );
                });
            }
        } catch (\Throwable $e) {
            $this->deleteUncommittedPath($bucket, $writeResult->path);
            throw $e;
        }

        // Clean up old storage file on overwrite (non-versioned only).
        if ($oldObjectToClean !== null) {
            $this->deleteStoredData($oldObjectToClean, $writeResult->path);
        }

        MultipartCleanup::clean(
            $this->metadata,
            $this->storage,
            $bucket,
            $key,
            $uploadId,
            $this->storageTiers->defaultTier()->name,
            $cleanupParts,
        );

        // Dispatch event notification.
        $this->notifications?->dispatchInternalEvent($event);

        // Build response.
        $location = sprintf('/%s/%s', $bucket, $key);

        $xml = XmlResponseBuilder::completeMultipartUploadResult($location, $bucket, $key, $etag);

        $responseHeaders = ['Content-Type' => 'application/xml'];

        if ($versionId !== null) {
            $responseHeaders['x-amz-version-id'] = $versionId;
        }

        // Encryption response headers.
        $sseAlgo = $encMeta['sse-algorithm'] ?? null;
        if ($sseAlgo === 'SSE-C') {
            $responseHeaders['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
        } elseif ($sseAlgo === 'AES256') {
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
        string $bucket,
        string $key,
        StorageWriteResult $original,
        string $payload,
    ): StorageWriteResult {
        $replacement = $this->storage->putObject(
            $bucket,
            $key,
            new \Amp\ByteStream\ReadableBuffer($payload),
        );

        try {
            $this->deleteUncommittedPath($bucket, $original->path);
        } catch (\Throwable $e) {
            $this->deleteUncommittedPath($bucket, $replacement->path);
            throw $e;
        }

        return new StorageWriteResult(
            path: $replacement->path,
            size: $original->size,
            md5Hex: $original->md5Hex,
            crc32Base64: $original->crc32Base64,
            crc32cBase64: $original->crc32cBase64,
            sha1Base64: $original->sha1Base64,
            sha256Base64: $original->sha256Base64,
        );
    }

    private function deleteUncommittedPath(string $bucket, string $path): void
    {
        \OpsFour\S3Server\Storage\DurableStorageDelete::run(
            $this->metadata,
            $this->storage,
            $bucket,
            $this->storageTiers->defaultTier()->name,
            $path,
        );
    }

    private function assertUploadIsActive(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
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
    }

}
