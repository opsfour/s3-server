<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\BucketInfo;

/**
 * Lists uploads only from the observed bucket incarnation before pagination.
 *
 * Implementations must exclude stale uploads and common prefixes from earlier
 * incarnations, including when a bucket name was reused by the same owner.
 * A pre-generation upload may be included only if bucket deletion removes all
 * of its uploads atomically before the name can be reused.
 */
interface GenerationBoundMultipartListingStore
{
    /**
     * @return array{uploads: list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata?: string, created_at: string}>, isTruncated: bool, nextKeyMarker: ?string, nextUploadIdMarker: ?string, commonPrefixes: list<string>}
     */
    public function listMultipartUploadsForBucket(
        BucketInfo $bucket,
        ?string $prefix = null,
        ?string $delimiter = null,
        int $maxUploads = 1000,
        ?string $keyMarker = null,
        ?string $uploadIdMarker = null,
    ): array;
}
