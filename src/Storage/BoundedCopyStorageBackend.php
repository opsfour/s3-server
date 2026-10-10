<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/**
 * Optional same-backend copy capability with a strict physical byte ceiling.
 *
 * The backend may return a copy shorter than the source. The caller compares
 * the returned size and checksum with committed object metadata before publish.
 */
interface BoundedCopyStorageBackend
{
    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult;
}
