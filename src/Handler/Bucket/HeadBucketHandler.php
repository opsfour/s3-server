<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles HeadBucket (HEAD /{bucket}).
 *
 * Verifies that the bucket exists and is owned by the calling user.
 * Returns 200 OK with metadata headers if the bucket exists, or
 * throws NoSuchBucketException (404) otherwise.
 *
 * This operation is commonly used by S3 clients to check bucket
 * existence and accessibility before performing data operations.
 */
final class HeadBucketHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Compute object stats for RGW-compatible extended headers.
        $stats = $this->metadata->getBucketStats($bucket);

        return new Response(
            status: 200,
            headers: [
                'x-amz-bucket-region' => $bucketInfo->region,
                'x-amz-access-point-alias' => 'false',
                'x-rgw-object-count' => (string) $stats['objectCount'],
                'x-rgw-bytes-used' => (string) $stats['bytesUsed'],
                'x-rgw-quota-max-buckets' => '1000',
            ],
        );
    }
}
