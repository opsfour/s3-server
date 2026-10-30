<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;

/** Operational capability for isolated, evidence-bound AWS payload reads. */
interface AwsS3ReadExecutor
{
    public function readCommittedObject(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        ObjectInfo $object,
        string $key,
        string $path,
        string $storageTier,
        ?int $offset,
        ?int $length,
    ): ReadableStream;

    public function readMultipartPart(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        string $logicalScope,
        string $path,
        string $storageTier,
        string $expectedOwnerId,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream;
}
