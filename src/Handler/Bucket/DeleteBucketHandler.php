<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

/**
 * Handles DeleteBucket (DELETE /{bucket}).
 *
 * Verifies the bucket exists and is owned by the requesting user,
 * ensures it is empty, then removes both the metadata record and
 * the storage backend namespace.
 *
 * S3 security: when the bucket does not exist or the owner does not
 * match, we always throw NoSuchBucketException (never revealing
 * existence to unauthorized callers).
 */
final class DeleteBucketHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Get bucket metadata and verify ownership.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        // 2. Delete from metadata store first — it performs the authoritative
        // empty check (objects + active multipart uploads). If it throws
        // BucketNotEmptyException, storage is untouched and the client can retry.
        $this->metadata->deleteBucket($ownerId, $bucket);

        // 3. Clean up storage backend (best-effort — bucket is already gone from metadata).
        try {
            $this->storage->deleteBucket($bucket);
        } catch (\Throwable) {
            // Storage cleanup failure is non-fatal; the bucket namespace
            // will be re-created if a bucket with the same name is created later.
        }

        // 4. Return 204 No Content.
        return new Response(status: 204);
    }
}
