<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/** Route identity-aware AWS reads without opening the generic path-only API. */
final class CommittedObjectReader
{
    public static function read(
        StorageBackend $storage,
        BucketInfo $bucket,
        ObjectInfo $object,
        string $path,
        string $storageTier,
        ?int $offset = null,
        ?int $length = null,
    ): ReadableStream {
        $backend = $storage;
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        if ($backend instanceof AwsS3StorageBackend) {
            if ($offset === null) {
                $length = null;
            }
            return $backend->readCommittedObject(
                $bucket,
                $object,
                $path,
                $storageTier,
                $offset,
                $length,
            );
        }

        return $storage->getObjectByPath($path, $offset, $length);
    }
}
