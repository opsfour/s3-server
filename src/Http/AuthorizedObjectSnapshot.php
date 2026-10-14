<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;

final class AuthorizedObjectSnapshot
{
    public const string ATTRIBUTE = 's3.authorizedObjectSnapshot';

    public static function lookupForRead(
        MetadataStore $metadata,
        S3Operation $operation,
        string $bucket,
        string $key,
        ?string $versionId,
    ): ?ObjectInfo {
        if ($versionId !== null) {
            return $metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
        }

        return match ($operation) {
            S3Operation::GetObject, S3Operation::HeadObject, S3Operation::GetObjectAttributes
                => $metadata->getCurrentObjectVersion($bucket, $key),
            default => $metadata->getObjectMetadata($bucket, $key),
        };
    }

    public static function resolve(
        MetadataStore $metadata,
        Request $request,
        string $bucket,
        string $key,
        ?string $versionId,
    ): ?ObjectInfo {
        if ($request->hasAttribute(self::ATTRIBUTE)) {
            $object = $request->getAttribute(self::ATTRIBUTE);

            return $object instanceof ObjectInfo ? $object : null;
        }

        return $versionId === null
            ? $metadata->getCurrentObjectVersion($bucket, $key)
            : $metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
    }

    /** @return array{string, ?string, bool, ?string}|null */
    public static function identity(?ObjectInfo $object): ?array
    {
        if ($object === null) {
            return null;
        }

        return [
            $object->ownerId,
            $object->versionId,
            $object->isDeleteMarker,
            $object->systemMetadata['storagePath'] ?? null,
        ];
    }
}
