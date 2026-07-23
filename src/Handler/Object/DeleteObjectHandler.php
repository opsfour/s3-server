<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Handles DeleteObject (DELETE /{bucket}/{key}).
 *
 * Deletes an object from both the metadata store and the storage backend.
 *
 * Per S3 semantics, deleting a non-existent object is NOT an error --
 * the operation is idempotent and always returns 204 No Content. This
 * matches the behavior of the real S3 API.
 *
 * With versioning enabled:
 * - Without ?versionId: inserts a delete marker (soft delete)
 * - With ?versionId: permanently deletes that specific version
 *   (subject to Object Lock checks)
 */
final class DeleteObjectHandler implements RequestHandler
{
    private readonly ObjectLockChecker $lockChecker;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $storageTiers,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
        $this->lockChecker = new ObjectLockChecker($metadata);
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // 2. Parse ?versionId from query params.
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $versionId = $queryParams['versionId'] ?? null;

        // 3. Check versioning status.
        $versioning = $this->metadata->getBucketVersioning($bucket);

        // 4. Handle versioning-aware delete.
        if (($versioning === 'Enabled' || $versioning === 'Suspended') && $versionId === null) {
            $isSuspended = ($versioning === 'Suspended');
            $oldObjectToClean = null;

            // Check if a real (non-delete-marker) object exists before creating the delete marker.
            // Used to decide whether to fire a notification (no notification for phantom deletes).
            $existingObj = $this->metadata->getObjectMetadata($bucket, $key);
            $hadRealObject = ($existingObj !== null && !$existingObj->isDeleteMarker);

            // Suspended versioning: collect storage path for existing null version before overwriting.
            if ($isSuspended && $hadRealObject) {
                $oldObjectToClean = $existingObj;
            }

            // Insert a delete marker (soft delete).
            $deleteMarkerVersionId = $this->metadata->deleteObjectVersioned($bucket, $key, $ownerId, $isSuspended);

            // Clean up old storage AFTER successful metadata write.
            if ($oldObjectToClean !== null) {
                $this->deleteStoredData($oldObjectToClean);
            }

            // Only fire notification if a real object was superseded by the delete marker.
            if ($hadRealObject) {
                $this->notifications?->dispatch('s3:ObjectRemoved:Delete', $bucket, $key, 0, '', $ownerId);
            }

            return new Response(
                status: 204,
                headers: [
                    'x-amz-version-id' => $deleteMarkerVersionId,
                    'x-amz-delete-marker' => 'true',
                ],
            );
        }

        if ($versionId !== null) {
            // Permanently delete a specific version.
            // Check Object Lock before allowing deletion.
            $this->lockChecker->check($bucket, $key, $versionId, $request);

            $deletedInfo = $this->metadata->deleteObjectVersion($bucket, $key, $versionId);

            $headers = [];

            if ($deletedInfo !== null) {
                // Delete the storage file if it's not a delete marker.
                if (! $deletedInfo->isDeleteMarker) {
                    $this->deleteStoredData($deletedInfo);
                }

                $headers['x-amz-version-id'] = $versionId;
                if ($deletedInfo->isDeleteMarker) {
                    $headers['x-amz-delete-marker'] = 'true';
                }

                $this->notifications?->dispatch('s3:ObjectRemoved:Delete', $bucket, $key, 0, '', $ownerId);
            }

            return new Response(
                status: 204,
                headers: $headers,
            );
        }

        // 5. Non-versioned delete.
        //    Delete metadata first so the object disappears from the API immediately.
        //    Then best-effort storage cleanup (orphaned file is preferable to an object
        //    visible in ListObjects but unreadable due to missing storage).
        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);

        if ($objectInfo !== null) {
            $this->metadata->deleteObjectMetadata($bucket, $key);
            $this->deleteStoredData($objectInfo);
            $this->notifications?->dispatch('s3:ObjectRemoved:Delete', $bucket, $key, 0, '', $ownerId);
        }

        return new Response(status: 204);
    }

    private function deleteStoredData(ObjectInfo $object): void
    {
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        if ($storagePath !== null && $storagePath !== '') {
            try {
                $this->storageTiers->tier($object->storageTier)->backend
                    ->deleteObjectByPath($storagePath, $object->bucket);
            } catch (\Throwable) {
            }
        }

        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            try {
                $this->storageTiers->defaultBackend()
                    ->deleteObjectByPath($object->restoredStoragePath, $object->bucket);
            } catch (\Throwable) {
            }
        }
    }

}
