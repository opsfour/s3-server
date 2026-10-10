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
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\RestoredCopyTierResolver;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Policy\ObjectDeletePolicyGuard;

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

    private readonly ObjectDeletePolicyGuard $policyGuard;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $storageTiers,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
        $this->lockChecker = new ObjectLockChecker($metadata);
        $this->policyGuard = new ObjectDeletePolicyGuard($metadata);
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = (string) $request->getAttribute('ownerId');

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

            // A delete marker is created even when the key had no current object.
            // Suspended versioning replaces only the null version, even when
            // a numbered version is currently latest.
            $deleteMarkerVersionId = '';
            $event = $this->removalEvent($request, $bucket, $key, $ownerId, 's3:ObjectRemoved:DeleteMarkerCreated', bucketGeneration: $bucketInfo->generation);
            $this->metadata->transaction(function () use (
                $bucket,
                $key,
                $ownerId,
                $isSuspended,
                &$event,
                $versioning,
                $bucketInfo,
                $request,
                &$oldObjectToClean,
                &$deleteMarkerVersionId,
            ): void {
                OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                $request->setAttribute(
                    's3.canBypassGovernanceRetention',
                    $this->policyGuard->check($request, $bucket, $key, null, $ownerId, $bucketInfo->ownerId),
                );
                if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                    throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                        'Bucket versioning changed while the delete was being committed.',
                    );
                }
                if ($isSuspended) {
                    $nullVersion = $this->metadata->getObjectMetadataByVersion($bucket, $key, 'null');
                    if ($nullVersion !== null && ! $nullVersion->isDeleteMarker) {
                        $this->lockChecker->check($bucket, $key, 'null', $request);
                        if ($nullVersion->restoredStoragePath !== null) {
                            RestoredCopyTierResolver::resolve($nullVersion, $this->storageTiers);
                        }
                        $oldObjectToClean = $nullVersion;
                    }
                }
                $deleteMarkerVersionId = $this->metadata->deleteObjectVersioned($bucket, $key, $ownerId, $isSuspended);
                if ($oldObjectToClean !== null) {
                    $this->queueStoredData($oldObjectToClean);
                }
                if ($deleteMarkerVersionId !== 'null') {
                    $event = $event->withVersionId($deleteMarkerVersionId);
                }
                $this->notifications?->enqueueWebhooks($event);
            });

            // Clean up old storage AFTER successful metadata write.
            if ($oldObjectToClean !== null) {
                $this->deleteStoredData($oldObjectToClean);
            }

            $this->notifications?->dispatchInternalEvent($event);

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
            $deletedInfo = null;
            $event = $this->removalEvent($request, $bucket, $key, $ownerId, versionId: $versionId, bucketGeneration: $bucketInfo->generation);
            $this->metadata->transaction(function () use ($bucketInfo, $bucket, $key, $ownerId, $versionId, $request, $event, &$deletedInfo): void {
                OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                $request->setAttribute(
                    's3.canBypassGovernanceRetention',
                    $this->policyGuard->check($request, $bucket, $key, $versionId, $ownerId, $bucketInfo->ownerId),
                );
                $this->lockChecker->check($bucket, $key, $versionId, $request);
                $existing = $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
                if ($existing !== null && $existing->restoredStoragePath !== null) {
                    RestoredCopyTierResolver::resolve($existing, $this->storageTiers);
                }
                $deletedInfo = $this->metadata->deleteObjectVersion($bucket, $key, $versionId);
                if ($deletedInfo !== null) {
                    if (! $deletedInfo->isDeleteMarker) {
                        $this->queueStoredData($deletedInfo);
                    }
                    $this->notifications?->enqueueWebhooks($event);
                }
            });

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

                $this->notifications?->dispatchInternalEvent($event);
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
        $event = $this->removalEvent($request, $bucket, $key, $ownerId, bucketGeneration: $bucketInfo->generation);
        $objectInfo = $this->metadata->transaction(function () use ($bucketInfo, $bucket, $key, $ownerId, $versioning, $event, $request): ?ObjectInfo {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            $this->policyGuard->check($request, $bucket, $key, null, $ownerId, $bucketInfo->ownerId);
            if ($this->metadata->getBucketVersioning($bucket) !== $versioning) {
                throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                    'Bucket versioning changed while the delete was being committed.',
                );
            }
            $object = $this->metadata->getObjectMetadata($bucket, $key);
            if ($object !== null) {
                if ($object->restoredStoragePath !== null) {
                    RestoredCopyTierResolver::resolve($object, $this->storageTiers);
                }
                $this->metadata->deleteObjectMetadata($bucket, $key);
                $this->queueStoredData($object);
                $this->notifications?->enqueueWebhooks($event);
            }

            return $object;
        });

        if ($objectInfo !== null) {
            $this->deleteStoredData($objectInfo);
            $this->notifications?->dispatchInternalEvent($event);
        }

        return new Response(status: 204);
    }

    private function deleteStoredData(ObjectInfo $object): void
    {
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        if ($storagePath !== null && $storagePath !== '') {
            $this->deleteQueuedPath($object->bucket, $object->storageTier, $storagePath);
        }

        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            $restoredTier = RestoredCopyTierResolver::resolve($object, $this->storageTiers);
            $this->deleteQueuedPath($object->bucket, $restoredTier->name, $object->restoredStoragePath);
        }
    }

    /** Must run inside the metadata transaction that removes the object reference. */
    private function queueStoredData(ObjectInfo $object): void
    {
        $path = $object->systemMetadata['storagePath'] ?? null;
        if ($path !== null && $path !== '') {
            $this->metadata->enqueueStorageGarbage($object->bucket, $object->storageTier, $path);
        }
        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== '') {
            $restoreTier = RestoredCopyTierResolver::resolve($object, $this->storageTiers);
            $this->metadata->enqueueStorageGarbage($object->bucket, $restoreTier->name, $object->restoredStoragePath);
        }
    }

    private function deleteQueuedPath(string $bucket, string $tier, string $path): void
    {
        try {
            \OpsFour\S3Server\Storage\StorageGarbageDiscard::afterDelete(
                $this->metadata,
                $this->storageTiers->tier($tier)->backend,
                $bucket,
                $tier,
                $path,
            );
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // The exact path was durably queued before its metadata reference
            // disappeared. The collector can retry a failed physical delete.
        }
    }

    private function removalEvent(Request $request, string $bucket, string $key, string $ownerId, string $eventName = 's3:ObjectRemoved:Delete', ?string $versionId = null, string $bucketGeneration = ''): S3Event
    {
        $event = $this->notifications?->createEvent($eventName, $bucket, $key, ownerId: $ownerId, request: $request, bucketGeneration: $bucketGeneration)
            ?? new S3Event($eventName, $bucket, $key, ownerId: $ownerId);

        return $versionId !== null && $versionId !== 'null' ? $event->withVersionId($versionId) : $event;
    }

}
