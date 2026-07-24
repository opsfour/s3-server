<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BucketNotEmptyException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

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
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Get bucket metadata and verify ownership.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Consume every multipart upload and the bucket under the same owner
        // lock. Concurrent part/object writes then either commit first or fail
        // cleanly after the bucket disappears.
        $cleanupUploads = $this->metadata->transaction(function () use ($ownerId, $bucket): array {
            $this->metadata->lockOwnerForUpdate($ownerId);
            $cleanupUploads = [];
            $keyMarker = null;
            $uploadIdMarker = null;
            do {
                $page = $this->metadata->listMultipartUploads(
                    $bucket,
                    maxUploads: 1000,
                    keyMarker: $keyMarker,
                    uploadIdMarker: $uploadIdMarker,
                );

                foreach ($page['uploads'] as $upload) {
                    $parts = MultipartCleanup::stage(
                        $this->metadata,
                        $bucket,
                        $upload['key_name'],
                        $upload['upload_id'],
                        $upload['owner_id'],
                        $this->storageTiers->defaultTier()->name,
                    );
                    $cleanupUploads[] = ['upload' => $upload, 'parts' => $parts];
                }

                $keyMarker = $page['nextKeyMarker'];
                $uploadIdMarker = $page['nextUploadIdMarker'];
            } while ($page['isTruncated']);

            $this->metadata->deleteBucket($ownerId, $bucket);

            return $cleanupUploads;
        });

        foreach ($cleanupUploads as $cleanup) {
            MultipartCleanup::clean(
                $this->metadata,
                $this->storage,
                $bucket,
                $cleanup['upload']['key_name'],
                $cleanup['upload']['upload_id'],
                $this->storageTiers->defaultTier()->name,
                $cleanup['parts'],
            );
        }

        // Clean up storage backend namespace. Individual multipart paths remain
        // in the durable garbage queue if this backend operation fails.
        try {
            $this->storage->deleteBucket($bucket);
        } catch (\Throwable) {
            // Storage cleanup failure is non-fatal; the bucket namespace
            // will be re-created if a bucket with the same name is created later.
        }

        return new Response(status: 204);
    }
}
