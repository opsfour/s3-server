<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Dto;

/**
 * Data transfer object representing S3 object metadata.
 */
final readonly class ObjectInfo
{
    /**
     * @param  string  $bucket  The bucket name.
     * @param  string  $key  The object key.
     * @param  int  $size  The object size in bytes.
     * @param  string  $etag  The ETag (typically quoted MD5 hex digest).
     * @param  string  $contentType  The Content-Type of the object.
     * @param  string  $storageClass  The storage class (STANDARD, etc.).
     * @param  string  $storageTier  Physical storage tier currently holding the object data.
     * @param  string  $transitionStatus  Current tier transition state.
     * @param  string|null  $transitionTargetTier  Target tier while a transition is running.
     * @param  string|null  $transitionError  Last transition error, if any.
     * @param  string|null  $restoreStatus  Current restore state for cold-tier objects.
     * @param  string|null  $restoredStoragePath  Temporary restored hot-copy path.
     * @param  \DateTimeImmutable|null  $restoreExpiresAt  Expiry timestamp for the restored hot copy.
     * @param  string  $ownerId  The owner identity.
     * @param  string|null  $versionId  The version ID if versioning is enabled.
     * @param  bool  $isDeleteMarker  Whether this is a delete marker.
     * @param  array<string, string>  $userMetadata  User-defined x-amz-meta-* headers.
     * @param  array<string, string>  $systemMetadata  System metadata (checksums, encryption, etc.).
     * @param  \DateTimeImmutable  $lastModified  The last modified timestamp.
     */
    public function __construct(
        public string $bucket,
        public string $key,
        public int $size,
        public string $etag,
        public string $contentType = 'application/octet-stream',
        public string $storageClass = 'STANDARD',
        public string $storageTier = 'STANDARD',
        public string $transitionStatus = 'available',
        public ?string $transitionTargetTier = null,
        public ?string $transitionError = null,
        public ?string $restoreStatus = null,
        public ?string $restoredStoragePath = null,
        public ?\DateTimeImmutable $restoreExpiresAt = null,
        public string $ownerId = '',
        public ?string $versionId = null,
        public bool $isDeleteMarker = false,
        public array $userMetadata = [],
        public array $systemMetadata = [],
        public \DateTimeImmutable $lastModified = new \DateTimeImmutable,
    ) {}
}
