<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Handler\Multipart\MultipartUploadTierResolver;
use OpsFour\S3Server\Metadata\GenerationBoundBucketMultipartCleanupStore;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Handles DeleteBucket (DELETE /{bucket}).
 *
 * Verifies the bucket exists and is owned by the requesting user,
 * ensures it is empty, then removes the metadata record and stages
 * multipart data for physical cleanup.
 *
 * A missing bucket returns NoSuchBucket. Ownership is checked inside the
 * deletion transaction before incomplete uploads are examined.
 */
final class DeleteBucketHandler implements RequestHandler
{
    private const int MAX_INLINE_CLEANUP_UPLOADS = 256;

    private const int MAX_INLINE_CLEANUP_PARTS = 10_000;

    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
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
        try {
            $cleanupUploads = $this->metadata->transaction(function () use ($request, $ownerId, $bucket, $bucketInfo): array {
                $this->metadata->lockOwnerForUpdate($ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                $currentBucket = $this->metadata->getBucket($bucket);
                if ($currentBucket === null) {
                    throw new NoSuchBucketException();
                }
                if ($currentBucket->ownerId !== $ownerId) {
                    throw new AccessDeniedException('Access Denied');
                }
                $cleanupUploads = [];
                $stagedPartCount = 0;
                $keyMarker = null;
                $uploadIdMarker = null;
                if (! $this->metadata instanceof GenerationBoundMultipartListingStore) {
                    throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart listing is unavailable for this metadata backend.');
                }
                do {
                    $page = $this->metadata->listMultipartUploadsForBucket(
                        $currentBucket,
                        maxUploads: self::MAX_INLINE_CLEANUP_UPLOADS,
                        keyMarker: $keyMarker,
                        uploadIdMarker: $uploadIdMarker,
                    );

                    if ($page['isTruncated']
                        && count($cleanupUploads) + count($page['uploads']) >= self::MAX_INLINE_CLEANUP_UPLOADS) {
                        throw new InlineMultipartCleanupLimitException('Inline multipart cleanup limit exceeded.');
                    }

                    foreach ($page['uploads'] as $upload) {
                        if (count($cleanupUploads) >= self::MAX_INLINE_CLEANUP_UPLOADS) {
                            throw new InlineMultipartCleanupLimitException('Inline multipart cleanup limit exceeded.');
                        }
                        $currentUpload = $this->metadata->getMultipartUpload($upload['upload_id']);
                        if ($currentUpload === null) {
                            throw new NoSuchUploadException();
                        }
                        $uploadGeneration = $currentUpload['bucket_generation'] ?? '';
                        if ($uploadGeneration !== $currentBucket->generation
                            && ! ($uploadGeneration === '' && $currentUpload['owner_id'] === $ownerId)) {
                            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                                'Multipart upload belongs to another bucket generation.',
                            );
                        }
                        $tier = MultipartUploadTierResolver::resolve($currentUpload, $this->storageTiers);
                        $parts = MultipartCleanup::stage(
                            $this->metadata,
                            $bucket,
                            $upload['key_name'],
                            $upload['upload_id'],
                            $upload['owner_id'],
                            $tier->name,
                            $uploadGeneration,
                        );
                        $stagedPartCount += count($parts);
                        if ($stagedPartCount > self::MAX_INLINE_CLEANUP_PARTS) {
                            throw new InlineMultipartCleanupLimitException('Inline multipart cleanup limit exceeded.');
                        }
                        $cleanupUploads[] = ['upload' => $upload, 'parts' => $parts, 'tier' => $tier];
                    }

                    $keyMarker = $page['nextKeyMarker'];
                    $uploadIdMarker = $page['nextUploadIdMarker'];
                } while ($page['isTruncated']);

                if (! $this->metadata instanceof GenerationBoundBucketMultipartCleanupStore) {
                    throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart cleanup is unavailable for this metadata backend.');
                }
                // The listing may omit a stale upload from an older bucket
                // incarnation. Check the remaining rows before deleting all
                // upload metadata by bucket name.
                $this->metadata->stageBucketMultipartCleanupForBucket($currentBucket, $this->storageTiers->defaultTier()->name);
                $this->metadata->deleteBucket($ownerId, $bucket);

                return $cleanupUploads;
            });
        } catch (InlineMultipartCleanupLimitException) {
            $this->metadata->transaction(function () use ($request, $ownerId, $bucket, $bucketInfo): void {
                $this->metadata->lockOwnerForUpdate($ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                $currentBucket = $this->metadata->getBucket($bucket);
                if ($currentBucket === null) {
                    throw new NoSuchBucketException();
                }
                if ($currentBucket->ownerId !== $ownerId) {
                    throw new AccessDeniedException('Access Denied');
                }
                if (! $this->metadata instanceof GenerationBoundBucketMultipartCleanupStore) {
                    throw new \OpsFour\S3Server\Exception\NotImplementedException('Generation-bound multipart cleanup is unavailable for this metadata backend.');
                }
                $this->metadata->stageBucketMultipartCleanupForBucket($currentBucket, $this->storageTiers->defaultTier()->name);
                $this->metadata->deleteBucket($ownerId, $bucket);
            });

            // The durable garbage collector aborts the captured upload IDs
            // after commit in bounded batches, including after a process crash.
            return new Response(status: 204);
        }

        foreach ($cleanupUploads as $cleanup) {
            MultipartCleanup::clean(
                $this->metadata,
                $cleanup['tier']->backend,
                $bucket,
                $cleanup['upload']['key_name'],
                $cleanup['upload']['upload_id'],
                $cleanup['tier']->name,
                $cleanup['parts'],
            );
        }

        // Do not delete the bucket namespace here. The metadata transaction
        // has committed, so another owner may already have created a bucket
        // with this name. A recursive backend deletion would remove their
        // newly written objects. Multipart paths have durable cleanup entries;
        // empty namespace markers await generation-aware cleanup.

        return new Response(status: 204);
    }
}
