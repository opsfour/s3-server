<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class ObjectVersionResolver
{
    public static function resolve(
        MetadataStore $metadata,
        Request $request,
        string $bucket,
        string $key,
    ): ObjectInfo {
        $versionId = QueryStringParser::parse($request->getUri()->getQuery())['versionId'] ?? null;
        if ($versionId !== null) {
            $object = $metadata->getObjectMetadataByVersion($bucket, $key, $versionId);
            if ($object === null || $object->isDeleteMarker) {
                throw new NoSuchVersionException();
            }

            return $object;
        }

        $object = $metadata->getObjectMetadata($bucket, $key);
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
