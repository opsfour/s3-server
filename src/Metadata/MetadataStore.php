<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Quota\QuotaConfig;

/**
 * Abstraction over S3 metadata persistence.
 *
 * Stores bucket and object metadata (not the object data itself).
 * Implementations must be safe for concurrent access via PHP Fibers.
 */
interface MetadataStore
{
    /**
     * Initialize the metadata store (create tables, run migrations, etc.).
     */
    public function initialize(): void;

    // ---------------------------------------------------------------
    // Bucket operations
    // ---------------------------------------------------------------

    /**
     * Create a bucket metadata record.
     *
     * @param  string  $ownerId  The owner identity.
     * @param  string  $bucket  The bucket name.
     * @param  string  $region  The bucket region.
     *
     * @throws \OpsFour\S3Server\Exception\BucketAlreadyExistsException If the bucket already exists.
     */
    public function createBucket(string $ownerId, string $bucket, string $region): void;

    /**
     * Delete a bucket metadata record.
     *
     * @param  string  $ownerId  The owner identity (must match the bucket owner).
     * @param  string  $bucket  The bucket name.
     *
     * @throws \OpsFour\S3Server\Exception\NoSuchBucketException If the bucket does not exist.
     * @throws \OpsFour\S3Server\Exception\AccessDeniedException If the ownerId does not match.
     * @throws \OpsFour\S3Server\Exception\BucketNotEmptyException If the bucket still contains objects.
     */
    public function deleteBucket(string $ownerId, string $bucket): void;

    /**
     * Get bucket statistics (object count and total bytes used).
     *
     * @return array{objectCount: int, bytesUsed: int}
     */
    public function getBucketStats(string $bucket): array;

    /**
     * Get bucket storage statistics across all stored object versions.
     *
     * Delete markers do not consume object bytes and are excluded. This is used
     * for quotas and storage accounting; getBucketStats() intentionally reports
     * only currently visible objects.
     *
     * @return array{objectCount: int, bytesUsed: int}
     */
    public function getBucketStorageStats(string $bucket): array;

    /**
     * Get account-specific quota limits for an owner.
     */
    public function getAccountQuota(string $ownerId): ?QuotaConfig;

    /**
     * List all account-specific quota overrides.
     *
     * @return array<string, QuotaConfig> Quotas keyed by owner ID.
     */
    public function listAccountQuotas(): array;

    /**
     * Store account-specific quota limits for an owner.
     */
    public function putAccountQuota(string $ownerId, QuotaConfig $quota): void;

    /**
     * Remove account-specific quota limits for an owner.
     */
    public function deleteAccountQuota(string $ownerId): void;

    /**
     * Get an account-level IAM policy JSON string for an owner.
     */
    public function getAccountPolicy(string $ownerId): ?string;

    /**
     * Store an account-level IAM policy JSON string for an owner.
     */
    public function putAccountPolicy(string $ownerId, string $policyJson): void;

    /**
     * Remove an account-level IAM policy for an owner.
     */
    public function deleteAccountPolicy(string $ownerId): void;

    /**
     * Get a reusable named IAM policy JSON string.
     */
    public function getNamedPolicy(string $policyName): ?string;

    /**
     * Store a reusable named IAM policy JSON string.
     */
    public function putNamedPolicy(string $policyName, string $policyJson): void;

    /**
     * Remove a reusable named IAM policy.
     */
    public function deleteNamedPolicy(string $policyName): void;

    /**
     * Get bucket metadata.
     *
     * @param  string  $bucket  The bucket name.
     * @return BucketInfo|null The bucket info or null if not found.
     */
    public function getBucket(string $bucket): ?BucketInfo;

    /**
     * List all buckets owned by the given owner.
     *
     * @param  string  $ownerId  The owner identity.
     * @return list<BucketInfo> The list of buckets.
     */
    public function listBuckets(string $ownerId): array;

    /**
     * List all buckets across all owners.
     *
     * Used by server-side processes (lifecycle, logging) that need to
     * iterate every bucket regardless of ownership.
     *
     * @return list<BucketInfo> The list of all buckets.
     */
    public function listAllBuckets(): array;

    /**
     * Check if a bucket exists.
     *
     * @param  string  $bucket  The bucket name.
     * @return bool True if the bucket exists.
     */
    public function bucketExists(string $bucket): bool;

    /**
     * Get the owner of a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return string|null The owner ID, or null if the bucket does not exist.
     */
    public function getBucketOwner(string $bucket): ?string;

    // ---------------------------------------------------------------
    // Object operations
    // ---------------------------------------------------------------

    /**
     * Store object metadata after a successful write.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  string  $ownerId  The owner identity.
     * @param  int  $size  The object size in bytes.
     * @param  string  $etag  The ETag (typically MD5 hex digest, quoted).
     * @param  string  $contentType  The Content-Type of the object.
     * @param  string  $storagePath  The path to the object data on the storage backend.
     * @param  string  $storageClass  The storage class (STANDARD, etc.).
     * @param  string|null  $contentEncoding  The Content-Encoding header value.
     * @param  string|null  $contentDisposition  The Content-Disposition header value.
     * @param  string|null  $cacheControl  The Cache-Control header value.
     * @param  array<string, string>  $userMetadata  User-defined x-amz-meta-* headers.
     * @param  string|null  $checksumCrc32  CRC32 checksum (base64).
     * @param  string|null  $checksumCrc32c  CRC32C checksum (base64).
     * @param  string|null  $checksumSha1  SHA-1 checksum (base64).
     * @param  string|null  $checksumSha256  SHA-256 checksum (base64).
     */
    public function putObjectMetadata(
        string $bucket,
        string $key,
        string $ownerId,
        int $size,
        string $etag,
        string $contentType,
        string $storagePath,
        string $storageClass = 'STANDARD',
        ?string $contentEncoding = null,
        ?string $contentDisposition = null,
        ?string $cacheControl = null,
        array $userMetadata = [],
        ?string $checksumCrc32 = null,
        ?string $checksumCrc32c = null,
        ?string $checksumSha1 = null,
        ?string $checksumSha256 = null,
    ): void;

    /**
     * Retrieve object metadata for the latest non-delete-marker version.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @return ObjectInfo|null The object metadata or null if not found.
     */
    public function getObjectMetadata(string $bucket, string $key): ?ObjectInfo;

    /**
     * Delete object metadata.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     */
    public function deleteObjectMetadata(string $bucket, string $key): void;

    /**
     * Check whether an object exists (latest version, non-delete-marker).
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @return bool True if the object exists.
     */
    public function objectExists(string $bucket, string $key): bool;

    /**
     * Update the physical placement metadata for an object or object version.
     *
     * This is the atomic metadata switch used by physical tier transitions after
     * the target object copy has been verified. Pass null as $versionId to target
     * the latest non-delete-marker object.
     */
    public function updateObjectPlacement(
        string $bucket,
        string $key,
        ?string $versionId,
        string $storageClass,
        string $storageTier,
        string $storagePath,
        string $transitionStatus = 'available',
        ?string $transitionTargetTier = null,
        ?string $transitionError = null,
    ): void;

    /**
     * Update restore metadata for an object or object version.
     *
     * A null $restoreStatus clears restore state. Pass null as $versionId to
     * target the latest non-delete-marker object.
     */
    public function updateObjectRestoreState(
        string $bucket,
        string $key,
        ?string $versionId,
        ?string $restoreStatus,
        ?string $restoredStoragePath = null,
        ?\DateTimeImmutable $restoreExpiresAt = null,
    ): void;

    /**
     * Check if a bucket contains any objects.
     *
     * Returns a positive integer if objects exist, 0 if empty.
     * The exact count is not guaranteed — callers should only test > 0.
     *
     * @param  string  $bucket  The bucket name.
     * @return int Positive if objects exist, 0 if empty.
     */
    public function countObjects(string $bucket): int;

    // ---------------------------------------------------------------
    // Listing
    // ---------------------------------------------------------------

    /**
     * List objects in a bucket with optional prefix, delimiter, and pagination.
     *
     * Implements both ListObjectsV2 and ListObjects (v1) semantics.
     * The delimiter handling groups keys by common prefix up to the first
     * occurrence of the delimiter after the prefix.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string|null  $prefix  Filter keys by this prefix.
     * @param  string|null  $delimiter  Group keys by this delimiter.
     * @param  int  $maxKeys  Maximum number of keys to return (1-1000).
     * @param  string|null  $startAfter  Start listing after this key (V2 semantics).
     * @param  string|null  $continuationToken  Continuation token from a previous request.
     * @return ListObjectsResult The listing result.
     */
    public function listObjects(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $startAfter = null,
        ?string $continuationToken = null,
    ): ListObjectsResult;

    // ---------------------------------------------------------------
    // Multipart upload operations
    // ---------------------------------------------------------------

    /**
     * Create a multipart upload record.
     *
     * @param  string  $uploadId  The unique upload ID.
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  string  $ownerId  The owner identity.
     * @param  string|null  $contentType  The Content-Type for the final object.
     * @param  array<string, string>  $userMetadata  User-defined metadata.
     */
    public function createMultipartUpload(
        string $uploadId,
        string $bucket,
        string $key,
        string $ownerId,
        ?string $contentType = null,
        array $userMetadata = [],
    ): void;

    /**
     * Get a multipart upload record.
     *
     * @param  string  $uploadId  The upload ID.
     * @return array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata: array<string, string>, created_at: string}|null
     */
    public function getMultipartUpload(string $uploadId): ?array;

    /**
     * Delete a multipart upload record (abort/complete).
     *
     * @param  string  $uploadId  The upload ID.
     */
    public function deleteMultipartUpload(string $uploadId): void;

    /**
     * List active multipart uploads for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string|null  $prefix  Filter by key prefix.
     * @param  string|null  $delimiter  Group keys by delimiter.
     * @param  int  $maxUploads  Maximum number of uploads to return.
     * @param  string|null  $keyMarker  Start listing after this key.
     * @param  string|null  $uploadIdMarker  Start listing after this upload ID (used with keyMarker).
     * @return array{uploads: list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, created_at: string}>, isTruncated: bool, nextKeyMarker: ?string, nextUploadIdMarker: ?string, commonPrefixes: list<string>}
     */
    public function listMultipartUploads(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array;

    /**
     * Store a part record for a multipart upload.
     *
     * @param  string  $uploadId  The upload ID.
     * @param  int  $partNumber  The part number (1-10000).
     * @param  string  $etag  The ETag of the part.
     * @param  int  $size  The part size in bytes.
     * @param  string  $storagePath  The path to the part data on the storage backend.
     */
    public function putPart(string $uploadId, int $partNumber, string $etag, int $size, string $storagePath): void;

    /**
     * Get all parts for a multipart upload, ordered by part number.
     *
     * @param  string  $uploadId  The upload ID.
     * @return list<array{part_number: int, etag: string, size: int, storage_path: string, created_at: string}>
     */
    public function getParts(string $uploadId): array;

    /**
     * Delete all parts for a multipart upload.
     *
     * @param  string  $uploadId  The upload ID.
     */
    public function deleteParts(string $uploadId): void;

    // ---------------------------------------------------------------
    // Versioning
    // ---------------------------------------------------------------

    /**
     * Get the versioning status of a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return string One of: '' (never enabled), 'Enabled', 'Suspended'.
     */
    public function getBucketVersioning(string $bucket): string;

    /**
     * Set the versioning status of a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $status  One of: 'Enabled', 'Suspended'.
     */
    public function setBucketVersioning(string $bucket, string $status): void;

    // ---------------------------------------------------------------
    // Versioning-aware object operations
    // ---------------------------------------------------------------

    /**
     * Store a new version of an object (versioning enabled).
     *
     * Generates a unique version ID, marks all previous versions as
     * non-latest, and inserts the new version as the latest.
     *
     * @param  array<string, string>  $userMetadata
     * @return string The generated version ID.
     */
    public function putObjectVersioned(
        string $bucket,
        string $key,
        string $ownerId,
        int $size,
        string $etag,
        string $contentType,
        string $storagePath,
        string $storageClass = 'STANDARD',
        ?string $contentEncoding = null,
        ?string $contentDisposition = null,
        ?string $cacheControl = null,
        array $userMetadata = [],
        ?string $checksumCrc32 = null,
        ?string $checksumCrc32c = null,
        ?string $checksumSha1 = null,
        ?string $checksumSha256 = null,
    ): string;

    /**
     * Insert a delete marker for an object (versioning enabled or suspended).
     *
     * When $suspended is true (versioning is Suspended), the delete marker
     * uses version_id='null' and replaces any existing null version.
     * When $suspended is false (versioning is Enabled), a new random version
     * ID is generated.
     *
     * @return string The delete marker version ID.
     */
    public function deleteObjectVersioned(string $bucket, string $key, string $ownerId, bool $suspended = false): string;

    /**
     * Retrieve metadata for a specific object version.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  string  $versionId  The version ID to retrieve.
     * @return ObjectInfo|null The object metadata or null if not found.
     */
    public function getObjectMetadataByVersion(string $bucket, string $key, string $versionId): ?ObjectInfo;

    /**
     * Permanently delete a specific object version.
     *
     * If the deleted version was the latest, promotes the next most recent
     * version to is_latest.
     *
     * @return ObjectInfo|null The deleted version info (for response headers), or null if not found.
     */
    public function deleteObjectVersion(string $bucket, string $key, string $versionId): ?ObjectInfo;

    /**
     * List all object versions (including delete markers) in a bucket.
     *
     * @return array{versions: list<ObjectInfo>, deleteMarkers: list<ObjectInfo>, isTruncated: bool, nextKeyMarker: ?string, nextVersionIdMarker: ?string, commonPrefixes: list<string>}
     */
    public function listObjectVersions(
        string $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxKeys = 1000,
        ?string $keyMarker = null,
        ?string $versionIdMarker = null,
    ): array;

    // ---------------------------------------------------------------
    // Object Lock, Retention, Legal Hold
    // ---------------------------------------------------------------

    /**
     * Get the Object Lock configuration for a bucket.
     *
     * @return array{objectLockEnabled: string, rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}}|null
     */
    public function getObjectLockConfig(string $bucket): ?array;

    /**
     * Set the Object Lock configuration for a bucket.
     *
     * @param  array{objectLockEnabled: string, rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}}  $config
     */
    public function putObjectLockConfig(string $bucket, array $config): void;

    /**
     * Get the retention settings for a specific object version.
     *
     * @return array{mode: string, retainUntilDate: string}|null
     */
    public function getObjectRetention(string $bucket, string $key, ?string $versionId = null): ?array;

    /**
     * Set the retention settings for a specific object version.
     */
    public function putObjectRetention(string $bucket, string $key, string $mode, string $retainUntilDate, ?string $versionId = null): void;

    /**
     * Get the legal hold status for a specific object version.
     *
     * @return string|null The legal hold status ('ON' or 'OFF'), or null if not set.
     */
    public function getObjectLegalHold(string $bucket, string $key, ?string $versionId = null): ?string;

    /**
     * Set the legal hold status for a specific object version.
     */
    public function putObjectLegalHold(string $bucket, string $key, string $status, ?string $versionId = null): void;

    // ---------------------------------------------------------------
    // ACLs
    // ---------------------------------------------------------------

    /**
     * Get the ACL grants for a resource.
     *
     * @param  string  $resourceType  'bucket' or 'object'.
     * @param  string  $resourceName  Bucket name or 'bucket/key'.
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    public function getAcl(string $resourceType, string $resourceName): array;

    /**
     * Replace all ACL grants for a resource.
     *
     * @param  string  $resourceType  'bucket' or 'object'.
     * @param  string  $resourceName  Bucket name or 'bucket/key'.
     * @param  string  $ownerId  The resource owner ID.
     * @param  list<array{granteeType: string, granteeId: string, permission: string}>  $grants
     */
    public function putAcl(string $resourceType, string $resourceName, string $ownerId, array $grants): void;

    // ---------------------------------------------------------------
    // Tagging
    // ---------------------------------------------------------------

    /**
     * Get tags for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return list<array{key: string, value: string}>
     */
    public function getBucketTagging(string $bucket): array;

    /**
     * Replace all tags for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  list<array{key: string, value: string}>  $tags
     */
    public function putBucketTagging(string $bucket, array $tags): void;

    /**
     * Delete all tags for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     */
    public function deleteBucketTagging(string $bucket): void;

    /**
     * Get tags for an object.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @return list<array{key: string, value: string}>
     */
    public function getObjectTagging(string $bucket, string $key): array;

    /**
     * Replace all tags for an object.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  list<array{key: string, value: string}>  $tags
     */
    public function putObjectTagging(string $bucket, string $key, array $tags): void;

    /**
     * Delete all tags for an object.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     */
    public function deleteObjectTagging(string $bucket, string $key): void;

    // ---------------------------------------------------------------
    // Bucket Policies
    // ---------------------------------------------------------------

    /**
     * Get the bucket policy JSON string.
     *
     * @param  string  $bucket  The bucket name.
     * @return string|null The JSON policy string, or null if not set.
     */
    public function getBucketPolicy(string $bucket): ?string;

    /**
     * Set the bucket policy.
     *
     * @param  string  $bucket  The bucket name.
     * @param  string  $policyJson  The raw JSON policy string.
     */
    public function putBucketPolicy(string $bucket, string $policyJson): void;

    /**
     * Delete the bucket policy.
     *
     * @param  string  $bucket  The bucket name.
     */
    public function deleteBucketPolicy(string $bucket): void;

    // ---------------------------------------------------------------
    // CORS
    // ---------------------------------------------------------------

    /**
     * Get the CORS rules for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return list<array{allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null}>
     */
    public function getBucketCors(string $bucket): array;

    /**
     * Replace all CORS rules for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  list<array{allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null}>  $rules
     */
    public function putBucketCors(string $bucket, array $rules): void;

    /**
     * Delete all CORS rules for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     */
    public function deleteBucketCors(string $bucket): void;

    // ---------------------------------------------------------------
    // Encryption Config
    // ---------------------------------------------------------------

    /**
     * Get the default encryption configuration for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return array{sseAlgorithm: string, kmsMasterKeyId: ?string, bucketKeyEnabled: bool}|null
     */
    public function getBucketEncryption(string $bucket): ?array;

    /**
     * Set the default encryption configuration for a bucket.
     */
    public function putBucketEncryption(string $bucket, string $sseAlgorithm, ?string $kmsMasterKeyId = null, bool $bucketKeyEnabled = false): void;

    /**
     * Delete the default encryption configuration for a bucket.
     */
    public function deleteBucketEncryption(string $bucket): void;

    // ---------------------------------------------------------------
    // Lifecycle Configuration
    // ---------------------------------------------------------------

    /**
     * Get the lifecycle rules for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>
     */
    public function getBucketLifecycle(string $bucket): array;

    /**
     * Replace all lifecycle rules for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>  $rules
     */
    public function putBucketLifecycle(string $bucket, array $rules): void;

    /**
     * Delete all lifecycle rules for a bucket.
     */
    public function deleteBucketLifecycle(string $bucket): void;

    // ---------------------------------------------------------------
    // Physical Tier Transition Jobs
    // ---------------------------------------------------------------

    public function enqueueTierTransitionJob(
        string $bucket,
        string $key,
        ?string $versionId,
        string $sourceTier,
        string $targetTier,
        string $targetStorageClass,
        string $sourceStoragePath,
        int $maxAttempts = 10,
    ): int;

    /**
     * @return list<array<string, mixed>>
     */
    public function dequeueTierTransitionJobs(int $limit): array;

    public function updateTierTransitionJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $targetStoragePath = null,
    ): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getTierTransitionJob(int $id): ?array;

    // ---------------------------------------------------------------
    // Physical Restore Jobs
    // ---------------------------------------------------------------

    public function enqueueRestoreJob(
        string $bucket,
        string $key,
        ?string $versionId,
        string $sourceTier,
        string $sourceStoragePath,
        int $restoreDays,
        int $maxAttempts = 10,
    ): int;

    /**
     * @return list<array<string, mixed>>
     */
    public function dequeueRestoreJobs(int $limit): array;

    public function updateRestoreJobStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?string $restoredStoragePath = null,
    ): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getRestoreJob(int $id): ?array;

    // ---------------------------------------------------------------
    // Notification Configuration
    // ---------------------------------------------------------------

    /**
     * Get the notification configuration for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>}>
     */
    public function getBucketNotification(string $bucket): array;

    /**
     * Replace the notification configuration for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @param  list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>}>  $configs
     */
    public function putBucketNotification(string $bucket, array $configs): void;

    // ---------------------------------------------------------------
    // Public Access Block
    // ---------------------------------------------------------------

    /**
     * Get the public access block configuration for a bucket.
     *
     * @return array{blockPublicAcls: bool, ignorePublicAcls: bool, blockPublicPolicy: bool, restrictPublicBuckets: bool}|null
     */
    public function getPublicAccessBlock(string $bucket): ?array;

    /**
     * Set the public access block configuration for a bucket.
     */
    public function putPublicAccessBlock(string $bucket, bool $blockPublicAcls, bool $ignorePublicAcls, bool $blockPublicPolicy, bool $restrictPublicBuckets): void;

    /**
     * Delete the public access block configuration for a bucket.
     */
    public function deletePublicAccessBlock(string $bucket): void;

    // ---------------------------------------------------------------
    // Bucket Logging
    // ---------------------------------------------------------------

    /**
     * Get the logging configuration for a bucket.
     *
     * @return array{targetBucket: string, targetPrefix: string}|null
     */
    public function getBucketLogging(string $bucket): ?array;

    /**
     * Set the logging configuration for a bucket.
     */
    public function putBucketLogging(string $bucket, string $targetBucket, string $targetPrefix): void;

    /**
     * Delete the logging configuration for a bucket.
     */
    public function deleteBucketLogging(string $bucket): void;

    // ---------------------------------------------------------------
    // Distributed Locks
    // ---------------------------------------------------------------

    /**
     * Acquire or renew a metadata-backed lease.
     *
     * Returns true if the lock was acquired by this owner, or renewed by the
     * same owner. Expired locks may be taken over by a different owner.
     */
    public function acquireLock(string $lockName, string $ownerId, int $ttlSeconds): bool;

    /**
     * Release a metadata-backed lease if it is still owned by this owner.
     */
    public function releaseLock(string $lockName, string $ownerId): void;

    // ---------------------------------------------------------------
    // Lifecycle checkpoints
    // ---------------------------------------------------------------

    /**
     * Get the stored lifecycle checkpoint for a bucket/rule/action.
     *
     * @return array{cursorKey: string|null, cursorVersionId: string|null, cursorUploadId: string|null}|null
     */
    public function getLifecycleCheckpoint(string $bucket, string $ruleId, string $action): ?array;

    public function putLifecycleCheckpoint(
        string $bucket,
        string $ruleId,
        string $action,
        ?string $cursorKey,
        ?string $cursorVersionId = null,
        ?string $cursorUploadId = null,
    ): void;

    public function deleteLifecycleCheckpoint(string $bucket, string $ruleId, string $action): void;

    // ---------------------------------------------------------------
    // Lifecycle query methods
    // ---------------------------------------------------------------

    /**
     * List objects that have expired based on their last modified date.
     *
     * @param  array<string, string>  $tags
     * @return list<ObjectInfo>
     */
    public function listExpiredObjects(string $bucket, ?string $prefix, \DateTimeImmutable $olderThan, int $limit = 1000, array $tags = [], ?string $afterKey = null): array;

    /**
     * List non-current object versions older than the given number of days.
     *
     * @param  array<string, string>  $tags
     * @return list<ObjectInfo>
     */
    public function listExpiredNoncurrentVersions(string $bucket, ?string $prefix, int $noncurrentDays, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array;

    /**
     * List multipart uploads older than the given number of days.
     *
     * @return list<array{upload_id: string, bucket: string, key_name: string}>
     */
    public function listExpiredMultipartUploads(string $bucket, int $daysAfterInitiation, int $limit = 1000, ?string $prefix = null, ?string $afterKey = null, ?string $afterUploadId = null): array;

    /**
     * List delete markers that are the only remaining version of their key.
     *
     * @param  array<string, string>  $tags
     * @return list<ObjectInfo>
     */
    public function listOrphanedDeleteMarkers(string $bucket, ?string $prefix, int $limit = 1000, array $tags = [], ?string $afterKey = null, ?string $afterVersionId = null): array;

    /**
     * List objects whose temporary restored hot copies have expired.
     *
     * @return list<ObjectInfo>
     */
    public function listExpiredRestoredObjects(\DateTimeImmutable $now, int $limit = 1000): array;

    // ---------------------------------------------------------------
    // Website Configuration
    // ---------------------------------------------------------------

    /**
     * Get the website configuration for a bucket.
     *
     * @param  string  $bucket  The bucket name.
     * @return array{indexDocument: string, errorDocument: ?string, redirectAllHost: ?string, redirectAllProtocol: ?string, routingRules: ?list<array<string, mixed>>}|null
     */
    public function getBucketWebsite(string $bucket): ?array;

    /**
     * Set the website configuration for a bucket.
     *
     * @param  list<array<string, mixed>>|null  $routingRules
     */
    public function putBucketWebsite(string $bucket, string $indexDocument, ?string $errorDocument = null, ?string $redirectAllHost = null, ?string $redirectAllProtocol = null, ?array $routingRules = null): void;

    /**
     * Delete the website configuration for a bucket.
     */
    public function deleteBucketWebsite(string $bucket): void;

    // ---------------------------------------------------------------
    // Rate Limiting
    // ---------------------------------------------------------------

    /**
     * Atomically check and decrement a rate limit token for the given IP.
     * Refills tokens based on elapsed time since last check.
     *
     * @return bool True if request is allowed, false if rate-limited.
     */
    public function rateLimitCheck(string $ip, float $maxTokens, float $refillRate): bool;

    /**
     * Remove rate limit entries not accessed within maxAgeSeconds.
     */
    public function rateLimitCleanup(int $maxAgeSeconds): void;

    // ---------------------------------------------------------------
    // Notification Queue
    // ---------------------------------------------------------------

    /**
     * Enqueue a notification for asynchronous delivery.
     */
    public function enqueueNotification(
        string $bucket,
        string $key,
        string $eventName,
        string $destinationUrl,
        string $payloadJson,
        int $maxAttempts = 10,
    ): void;

    /**
     * Dequeue pending notifications ready for delivery.
     *
     * @return list<array{id: int, bucket: string, key_name: string, event_name: string, destination_url: string, payload_json: string, attempts: int, max_attempts: int}>
     */
    public function dequeueNotifications(int $limit): array;

    /**
     * Return notification queue counts by status.
     *
     * @return array<string, int>
     */
    public function getNotificationQueueStats(): array;

    /**
     * Update the status of a notification queue item.
     *
     * @param bool $incrementAttempts Whether to increment the attempts counter. False for non-delivery deferrals (e.g., circuit breaker).
     */
    public function updateNotificationStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
    ): void;

    /**
     * Remove old completed/dead-letter notifications.
     */
    public function cleanupOldNotifications(int $maxAgeSeconds): void;

    // ---------------------------------------------------------------
    // Transaction support
    // ---------------------------------------------------------------

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction.
     */
    public function commit(): void;

    /**
     * Roll back the current transaction.
     */
    public function rollback(): void;

    /**
     * Serialize quota-sensitive writes for one owner within the current transaction.
     */
    public function lockOwnerForUpdate(string $ownerId): void;

    /**
     * Execute a callback within a database transaction.
     *
     * Automatically commits on success or rolls back on exception.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
