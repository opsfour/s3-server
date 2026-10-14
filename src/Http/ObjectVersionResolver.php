<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;

final class ObjectVersionResolver
{
    public static function resolve(
        MetadataStore $metadata,
        Request $request,
        string $bucket,
        string $key,
        bool $allowExplicitDeleteMarker = false,
    ): ObjectInfo {
        $versionId = QueryStringParser::parse($request->getUri()->getQuery())['versionId'] ?? null;
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        $useSnapshot = in_array($operation, [
            S3Operation::GetObjectAcl, S3Operation::GetObjectTagging,
            S3Operation::GetObjectRetention, S3Operation::GetObjectLegalHold,
            S3Operation::GetObjectAttributes,
        ], true) && $request->hasAttribute(AuthorizedObjectSnapshot::ATTRIBUTE);
        if ($versionId !== null) {
            $object = $useSnapshot
                ? AuthorizedObjectSnapshot::resolve($metadata, $request, $bucket, $key, $versionId)
                : $metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
            if ($object === null || ($object->isDeleteMarker && ! $allowExplicitDeleteMarker)) {
                throw new NoSuchVersionException();
            }

            return $object;
        }

        $object = $useSnapshot
            ? AuthorizedObjectSnapshot::resolve($metadata, $request, $bucket, $key, null)
            : $metadata->getObjectMetadata($bucket, $key);
        if ($object === null || $object->isDeleteMarker) {
            throw new NoSuchKeyException();
        }

        return $object;
    }

    public static function aclResourceName(string $bucket, string $key, ?string $versionId): string
    {
        return $bucket . '/' . $key . '?versionId=' . rawurlencode($versionId ?? 'null');
    }
}
