<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\UserMetadataExtractor;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;

/**
 * Handles PutObject (PUT /{bucket}/{key}).
 *
 * Streams the request body into the storage backend, computes the
 * MD5 ETag, and writes object metadata. Supports all standard S3
 * headers including Content-Type, Content-Encoding, Content-Disposition,
 * Cache-Control, x-amz-storage-class, and x-amz-meta-* user metadata.
 *
 * The storage backend is responsible for atomic writes (temp file +
 * rename) and MD5 computation during streaming.
 */
final class PutObjectHandler implements RequestHandler
{
    /** @var list<string> Valid S3 storage class values. */
    private const array VALID_STORAGE_CLASSES = [
        'STANDARD',
        'REDUCED_REDUNDANCY',
        'STANDARD_IA',
        'ONEZONE_IA',
        'INTELLIGENT_TIERING',
        'GLACIER',
        'DEEP_ARCHIVE',
        'GLACIER_IR',
    ];

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly ?NotificationDispatcher $notifications = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        private readonly ?QuotaManager $quotas = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        // 2. Evaluate conditional PUT headers (If-Match, If-None-Match).
        $ifMatch = $request->getHeader('if-match');
        $ifNoneMatch = $request->getHeader('if-none-match');

        if ($ifMatch !== null || $ifNoneMatch !== null) {
            $existingObj = $this->metadata->getObjectMetadata($bucket, $key);

            if ($ifMatch !== null) {
                // If-Match: succeed only if existing etag matches.
                // If object doesn't exist, return 404 (not 412).
                if ($existingObj === null) {
                    throw new \OpsFour\S3Server\Exception\NoSuchKeyException;
                }
                if (!$this->etagMatches($existingObj->etag, $ifMatch)) {
                    throw new \OpsFour\S3Server\Exception\PreconditionFailedException;
                }
            }

            if ($ifNoneMatch !== null) {
                // If-None-Match: * means "only create if doesn't exist".
                if ($ifNoneMatch === '*' && $existingObj !== null) {
                    throw new \OpsFour\S3Server\Exception\PreconditionFailedException;
                }
                if ($ifNoneMatch !== '*' && $existingObj !== null && $this->etagMatches($existingObj->etag, $ifNoneMatch)) {
                    throw new \OpsFour\S3Server\Exception\PreconditionFailedException;
                }
            }
        }

        // 3. Extract metadata from request headers.
        $contentType = $request->getHeader('content-type') ?? 'application/octet-stream';
        $contentEncoding = $request->getHeader('content-encoding');
        $contentDisposition = $request->getHeader('content-disposition');
        $cacheControl = $request->getHeader('cache-control');
        $expires = $request->getHeader('expires');

        // Storage class (default STANDARD).
        $storageClass = $request->getHeader('x-amz-storage-class') ?? 'STANDARD';
        if (! in_array($storageClass, self::VALID_STORAGE_CLASSES, true)) {
            $storageClass = 'STANDARD';
        }

        // User metadata (x-amz-meta-* headers).
        $userMetadata = UserMetadataExtractor::extract($request);

        // Store Expires in system metadata if provided.
        if ($expires !== null && $expires !== '') {
            $userMetadata['__expires'] = $expires;
        }

        // Checksum headers (optional — at most one allowed per AWS spec).
        $checksumCrc32 = $request->getHeader('x-amz-checksum-crc32');
        $checksumCrc32c = $request->getHeader('x-amz-checksum-crc32c');
        $checksumSha1 = $request->getHeader('x-amz-checksum-sha1');
        $checksumSha256 = $request->getHeader('x-amz-checksum-sha256');

        // 3. Stream body to storage backend (computes all checksums during write).
        $result = $this->storage->putObject($bucket, $key, $request->getBody());

        // 3b. Verify client-sent checksum against computed value.
        $clientChecksums = array_filter([
            'crc32' => $checksumCrc32,
            'crc32c' => $checksumCrc32c,
            'sha1' => $checksumSha1,
            'sha256' => $checksumSha256,
        ]);

        if (count($clientChecksums) > 1) {
            try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
            throw new InvalidArgumentException('Only one x-amz-checksum-* header may be specified.');
        }

        foreach ($clientChecksums as $algo => $clientValue) {
            $computedValue = match ($algo) {
                'crc32' => $result->crc32Base64,
                'crc32c' => $result->crc32cBase64,
                'sha1' => $result->sha1Base64,
                'sha256' => $result->sha256Base64,
            };

            if ($computedValue === null) {
                try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
                throw new \OpsFour\S3Server\Exception\InternalErrorException(
                    "Storage backend did not compute checksum for algorithm: {$algo}",
                );
            }

            if (!hash_equals($computedValue, $clientValue)) {
                try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
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
            };
        }

        // 4. Content-Length mismatch detection (before encryption to avoid wasted work).
        // For chunked SigV4 uploads, Content-Length reflects the encoded body size (with
        // chunk framing). The actual payload size is in x-amz-decoded-content-length.
        $contentSha = $request->getHeader('x-amz-content-sha256');
        $isChunkedSigV4 = ($contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD'
            || $contentSha === 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER');

        $declaredLength = $isChunkedSigV4
            ? $request->getHeader('x-amz-decoded-content-length')
            : $request->getHeader('content-length');

        if ($declaredLength !== null && (int) $declaredLength !== $result->size) {
            try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
            throw new \OpsFour\S3Server\Exception\IncompleteBodyException(
                'Content-Length mismatch: declared ' . $declaredLength . ', received ' . $result->size,
            );
        }

        // 5. Encryption: encrypt data at rest if SSE-C or SSE-S3 is requested.
        try {
            $encMeta = self::applyEncryption(
                $request,
                $result->path,
                $bucket,
                $this->metadata,
                $this->encryption,
                $this->storage,
                $result->size,
                $this->maxEncryptedObjectSize,
            );
        } catch (\Throwable $e) {
            // Clean up plaintext file on encryption failure (e.g. EntityTooLargeException).
            try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
            throw $e;
        }

        // Merge encryption metadata into user metadata.
        if ($encMeta !== []) {
            foreach ($encMeta as $k => $v) {
                $userMetadata['__'.$k] = $v;
            }
        }

        // 6. Build the quoted ETag (S3 ETags are always quoted MD5 hex).
        $etag = '"'.$result->md5Hex.'"';

        // 5b. Write metadata (versioning-aware) and handle old-object cleanup.
        $versioning = $this->metadata->getBucketVersioning($bucket);
        $versionId = null;
        $oldStoragePath = null;

        try {
            if ($versioning === 'Enabled') {
                // Versioning is enabled: create a new version.
                $this->metadata->transaction(function () use (
                    $bucket, $key, $ownerId, $result, $etag, $contentType,
                    $storageClass, $contentEncoding, $contentDisposition, $cacheControl,
                    $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256,
                    &$versionId,
                ) {
                    $this->quotas?->assertCanWriteObject($ownerId, $bucket, null, $result->size, true);

                    $versionId = $this->metadata->putObjectVersioned(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $ownerId,
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
                });
            } else {
                // Versioning suspended or never enabled: overwrite with version_id='null'.
                // Wrap read-old + write-new in a transaction to prevent concurrent
                // overwrites from orphaning storage files (SQLite single-writer
                // serializes, Postgres/MySQL use row locks).
                $this->metadata->transaction(function () use (
                    $bucket, $key, $ownerId, $result, $etag, $contentType,
                    $storageClass, $contentEncoding, $contentDisposition, $cacheControl,
                    $userMetadata, $checksumCrc32, $checksumCrc32c, $checksumSha1, $checksumSha256,
                    &$oldStoragePath,
                ) {
                    $existingObj = $this->metadata->getObjectMetadata($bucket, $key);
                    $oldStoragePath = $existingObj?->systemMetadata['storagePath'] ?? null;

                    $this->quotas?->assertCanWriteObject($ownerId, $bucket, $existingObj, $result->size, false);

                    $this->metadata->putObjectMetadata(
                        bucket: $bucket,
                        key: $key,
                        ownerId: $ownerId,
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
                });
            }
        } catch (\Throwable $e) {
            // Clean up storage on metadata failure.
            try { $this->storage->deleteObjectByPath($result->path, $bucket); } catch (\Throwable) {}
            throw $e;
        }

        // Clean up old storage file on overwrite (non-versioned only).
        if ($oldStoragePath !== null && $oldStoragePath !== $result->path) {
            try { $this->storage->deleteObjectByPath($oldStoragePath, $bucket); } catch (\Throwable) {}
        }

        // 7. Build response headers.
        $headers = ['ETag' => $etag];

        if ($versionId !== null) {
            $headers['x-amz-version-id'] = $versionId;
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

        // 8. Apply ACL from headers (canned x-amz-acl or x-amz-grant-* headers).
        $cannedAcl = $request->getHeader('x-amz-acl');
        $hasGrantHeaders = $request->hasHeader('x-amz-grant-read') || $request->hasHeader('x-amz-grant-write')
            || $request->hasHeader('x-amz-grant-read-acp') || $request->hasHeader('x-amz-grant-write-acp')
            || $request->hasHeader('x-amz-grant-full-control');

        if ($cannedAcl !== null && $cannedAcl !== '') {
            $resourceName = $bucket . '/' . $key;
            $allUsersUri = 'http://acs.amazonaws.com/groups/global/AllUsers';
            $authUsersUri = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';
            $ownerGrant = ['granteeType' => 'CanonicalUser', 'granteeId' => $ownerId, 'permission' => 'FULL_CONTROL'];

            $grants = match ($cannedAcl) {
                'public-read' => [$ownerGrant, ['granteeType' => 'Group', 'granteeId' => $allUsersUri, 'permission' => 'READ']],
                'public-read-write' => [$ownerGrant, ['granteeType' => 'Group', 'granteeId' => $allUsersUri, 'permission' => 'READ'], ['granteeType' => 'Group', 'granteeId' => $allUsersUri, 'permission' => 'WRITE']],
                'authenticated-read' => [$ownerGrant, ['granteeType' => 'Group', 'granteeId' => $authUsersUri, 'permission' => 'READ']],
                'bucket-owner-read' => [$ownerGrant, ['granteeType' => 'CanonicalUser', 'granteeId' => $bucketInfo->ownerId, 'permission' => 'READ']],
                'bucket-owner-full-control' => [$ownerGrant, ['granteeType' => 'CanonicalUser', 'granteeId' => $bucketInfo->ownerId, 'permission' => 'FULL_CONTROL']],
                default => [$ownerGrant],
            };

            $this->metadata->putAcl('object', $resourceName, $ownerId, $grants);
        } elseif ($hasGrantHeaders) {
            $resourceName = $bucket . '/' . $key;
            $grants = [['granteeType' => 'CanonicalUser', 'granteeId' => $ownerId, 'permission' => 'FULL_CONTROL']];
            $headerMap = [
                'x-amz-grant-read' => 'READ', 'x-amz-grant-write' => 'WRITE',
                'x-amz-grant-read-acp' => 'READ_ACP', 'x-amz-grant-write-acp' => 'WRITE_ACP',
                'x-amz-grant-full-control' => 'FULL_CONTROL',
            ];
            foreach ($headerMap as $header => $permission) {
                $value = $request->getHeader($header);
                if ($value === null || $value === '') continue;
                foreach (explode(',', $value) as $grantee) {
                    $grantee = trim($grantee);
                    if (preg_match('/^id\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                        $grants[] = ['granteeType' => 'CanonicalUser', 'granteeId' => $m[1], 'permission' => $permission];
                    } elseif (preg_match('/^uri\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                        $grants[] = ['granteeType' => 'Group', 'granteeId' => $m[1], 'permission' => $permission];
                    }
                }
            }
            $this->metadata->putAcl('object', $resourceName, $ownerId, $grants);
        }

        // 9. Dispatch event notification.
        $this->notifications?->dispatch('s3:ObjectCreated:Put', $bucket, $key, $result->size, $etag, $ownerId);

        return new Response(
            status: 200,
            headers: $headers,
        );
    }

    /**
     * Apply server-side encryption if requested.
     *
     * Reads the stored plaintext from storage, encrypts it in-place,
     * and returns encryption metadata to be stored with the object.
     *
     * @return array<string, string> Encryption metadata (empty if no encryption).
     */
    private static function applyEncryption(
        Request $request,
        string $storagePath,
        string $bucket,
        MetadataStore $metadata,
        ?EncryptionServiceInterface $encryption,
        StorageBackend $storage,
        int $objectSize = 0,
        int $maxEncryptedObjectSize = 268_435_456,
    ): array {
        if ($encryption === null) {
            return [];
        }

        // Check for SSE-C headers.
        $sseCAlgorithm = $request->getHeader('x-amz-server-side-encryption-customer-algorithm');
        $sseCKey = $request->getHeader('x-amz-server-side-encryption-customer-key');
        $sseCKeyMd5 = $request->getHeader('x-amz-server-side-encryption-customer-key-MD5');

        if ($sseCAlgorithm !== null && $sseCKey !== null && $sseCKeyMd5 !== null) {
            // SSE-C: customer-provided key.
            if ($objectSize > $maxEncryptedObjectSize) {
                throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                    'Object exceeds max size for server-side encryption (' . $maxEncryptedObjectSize . ' bytes).',
                );
            }
            $customerKey = EncryptionService::validateSseCHeaders($sseCAlgorithm, $sseCKey, $sseCKeyMd5);
            $plaintext = \Amp\ByteStream\buffer($storage->getObjectByPath($storagePath));
            $enc = $encryption->encryptSseC($plaintext, $customerKey);
            $tempPath = $storagePath . '.enc.tmp';
            try {
                \Amp\File\write($tempPath, $enc['ciphertext']);
                \Amp\File\move($tempPath, $storagePath);
            } catch (\Throwable $e) {
                try { \Amp\File\deleteFile($tempPath); } catch (\Throwable) {}
                throw $e;
            }

            return [
                'sse-algorithm' => 'SSE-C',
                'sse-iv' => $enc['iv'],
                'sse-tag' => $enc['tag'],
                'sse-customer-key-md5' => $sseCKeyMd5,
            ];
        }

        // Check for explicit SSE-S3 header or bucket default encryption.
        $sseHeader = $request->getHeader('x-amz-server-side-encryption');
        $applySSE = ($sseHeader === 'AES256');

        if (! $applySSE) {
            $bucketEnc = $metadata->getBucketEncryption($bucket);
            if ($bucketEnc !== null && ($bucketEnc['sseAlgorithm'] === 'AES256' || $bucketEnc['sseAlgorithm'] === 'aws:kms')) {
                $applySSE = true;
            }
        }

        if ($applySSE) {
            if ($objectSize > $maxEncryptedObjectSize) {
                throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                    'Object exceeds max size for server-side encryption (' . $maxEncryptedObjectSize . ' bytes).',
                );
            }
            $plaintext = \Amp\ByteStream\buffer($storage->getObjectByPath($storagePath));
            $enc = $encryption->encryptSseS3($plaintext);
            $tempPath = $storagePath . '.enc.tmp';
            try {
                \Amp\File\write($tempPath, $enc['ciphertext']);
                \Amp\File\move($tempPath, $storagePath);
            } catch (\Throwable $e) {
                try { \Amp\File\deleteFile($tempPath); } catch (\Throwable) {}
                throw $e;
            }

            return [
                'sse-algorithm' => 'AES256',
                'sse-key' => $enc['encryptedDataKey'],
                'sse-iv' => $enc['iv'],
                'sse-tag' => $enc['tag'],
            ];
        }

        return [];
    }

    /**
     * Check if an object's ETag matches the given header value.
     * Handles both quoted and unquoted ETags, and comma-separated lists.
     */
    private function etagMatches(string $objectEtag, string $headerValue): bool
    {
        $objectEtag = trim($objectEtag, '"');
        $candidates = array_map('trim', explode(',', $headerValue));

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate, '"');
            if ($candidate === '*' || $candidate === $objectEtag) {
                return true;
            }
        }

        return false;
    }
}
