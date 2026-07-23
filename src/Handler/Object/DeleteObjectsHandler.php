<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\ByteStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\ObjectLock\ObjectLockChecker;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Xml\XmlRequestParser;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles DeleteObjects (POST /{bucket}?delete).
 *
 * Parses an XML request body containing up to 1000 object keys,
 * deletes each one, and returns an XML response listing deleted
 * objects and any errors.
 *
 * Supports both verbose and quiet mode. In quiet mode, only errors
 * are included in the response.
 *
 * Supports versioning:
 * - With versionId: permanently deletes that specific version (subject to Object Lock)
 * - Without versionId + versioning enabled: creates a delete marker
 * - Without versioning: deletes the object
 */
final class DeleteObjectsHandler implements RequestHandler
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
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Read and parse the XML body.
        $body = ByteStream\buffer($request->getBody());
        $parsed = XmlRequestParser::parseDeleteObjects($body);

        if (count($parsed['objects']) > 1000) {
            throw new MalformedXmlException('The batch delete request may contain a maximum of 1000 keys.');
        }

        $quiet = $parsed['quiet'];
        $deleted = [];
        $errors = [];

        // Check versioning status once for the bucket.
        $versioning = $this->metadata->getBucketVersioning($bucket);

        // Collect storage paths and notifications to process after commit.
        /** @var list<ObjectInfo> $objectsToClean */
        $objectsToClean = [];
        $notificationKeys = [];

        // Wrap all metadata deletions in a transaction for atomicity.
        $this->metadata->transaction(function () use ($parsed, $bucket, $ownerId, $versioning, $quiet, $request, &$deleted, &$errors, &$objectsToClean, &$notificationKeys): void {
            foreach ($parsed['objects'] as $obj) {
                $key = $obj['key'];
                $versionId = $obj['versionId'] ?? null;

                try {
                    if ($versionId !== null) {
                        // Permanently delete a specific version.
                        // Check Object Lock before allowing deletion.
                        $this->lockChecker->check($bucket, $key, $versionId, $request);

                        $deletedInfo = $this->metadata->deleteObjectVersion($bucket, $key, $versionId);

                        if ($deletedInfo !== null) {
                            // Collect storage path for deferred deletion (after commit).
                            if (! $deletedInfo->isDeleteMarker) {
                                $objectsToClean[] = $deletedInfo;
                            }

                            $notificationKeys[] = $key;

                            if (! $quiet) {
                                $entry = ['key' => $key, 'versionId' => $versionId];
                                if ($deletedInfo->isDeleteMarker) {
                                    $entry['deleteMarker'] = true;
                                    $entry['deleteMarkerVersionId'] = $versionId;
                                }
                                $deleted[] = $entry;
                            }
                        } else {
                            // Version not found — S3 still returns success.
                            if (! $quiet) {
                                $deleted[] = ['key' => $key, 'versionId' => $versionId];
                            }
                        }
                    } elseif ($versioning === 'Enabled' || $versioning === 'Suspended') {
                        $isSuspended = ($versioning === 'Suspended');

                        // Suspended: fetch existing null version for cleanup after metadata write.
                        $oldSuspendedObject = null;
                        if ($isSuspended) {
                            $existingObj = $this->metadata->getObjectMetadata($bucket, $key);
                            if ($existingObj !== null && !$existingObj->isDeleteMarker) {
                                $oldSuspendedObject = $existingObj;
                            }
                        }

                        // Create a delete marker.
                        $deleteMarkerVersionId = $this->metadata->deleteObjectVersioned($bucket, $key, $ownerId, $isSuspended);

                        // Only queue path for deletion after successful metadata write.
                        if ($oldSuspendedObject !== null) {
                            $objectsToClean[] = $oldSuspendedObject;
                        }
                        $notificationKeys[] = $key;

                        if (! $quiet) {
                            $deleted[] = [
                                'key' => $key,
                                'deleteMarker' => true,
                                'deleteMarkerVersionId' => $deleteMarkerVersionId,
                            ];
                        }
                    } else {
                        // Non-versioned delete.
                        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);

                        if ($objectInfo !== null) {
                            $this->metadata->deleteObjectMetadata($bucket, $key);

                            // Collect path AFTER metadata delete succeeds to avoid
                            // deleting storage for objects whose metadata wasn't removed.
                            $objectsToClean[] = $objectInfo;
                            $notificationKeys[] = $key;
                        }

                        // S3 returns success even if the object didn't exist.
                        if (! $quiet) {
                            $deleted[] = ['key' => $key];
                        }
                    }
                } catch (\OpsFour\S3Server\Exception\ObjectLockedException) {
                    $errors[] = [
                        'key' => $key,
                        'versionId' => $versionId,
                        'code' => 'AccessDenied',
                        'message' => 'Access Denied',
                    ];
                } catch (\Throwable $e) {
                    $errors[] = [
                        'key' => $key,
                        'code' => 'InternalError',
                        'message' => 'Failed to delete object.',
                    ];
                }
            }
        });

        // Delete storage files AFTER successful metadata commit.
        foreach ($objectsToClean as $object) {
            $this->deleteStoredData($object);
        }

        // Dispatch notifications after commit.
        foreach ($notificationKeys as $key) {
            $this->notifications?->dispatch('s3:ObjectRemoved:Delete', $bucket, $key, 0, '', $ownerId);
        }

        $xml = XmlResponseBuilder::deleteResult($deleted, $errors);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }

    private function deleteStoredData(ObjectInfo $object): void
    {
        $path = $object->systemMetadata['storagePath'] ?? null;
        if ($path !== null && $path !== '') {
            try {
                $this->storageTiers->tier($object->storageTier)->backend
                    ->deleteObjectByPath($path, $object->bucket);
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
