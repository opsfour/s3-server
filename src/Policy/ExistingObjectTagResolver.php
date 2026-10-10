<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;

/** Resolves the object whose tags are used by this package's policy extension. */
final class ExistingObjectTagResolver
{
    /**
     * @return list<array{key: string, value: string}>
     */
    public static function forRequest(
        MetadataStore $metadata,
        string $bucket,
        string $key,
        S3Operation $operation,
        ?string $versionId = null,
    ): array {
        if ($versionId !== null) {
            $object = $metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
        } elseif ($metadata->getBucketVersioning($bucket) === 'Suspended'
            && in_array($operation, [
                S3Operation::PutObject,
                S3Operation::PostObject,
                S3Operation::CopyObject,
                S3Operation::CreateMultipartUpload,
                S3Operation::CompleteMultipartUpload,
                S3Operation::DeleteObject,
            ], true)) {
            // These writes target the null version (multipart initiation
            // prepares such a write), and DELETE removes it, even when a
            // numbered version is currently the latest object.
            $object = $metadata->getObjectMetadataByVersion($bucket, $key, 'null');
        } else {
            $object = $metadata->getObjectMetadata($bucket, $key);
        }

        if ($object === null || $object->isDeleteMarker) {
            return [];
        }

        return $metadata->getObjectTagging($bucket, $key, $object->versionId);
    }
}
