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
 * Returns 200 OK with S3 bucket headers if the bucket exists, or
 * throws NoSuchBucketException (404) otherwise.
 *
 * This operation is commonly used by S3 clients to check bucket
 * existence and accessibility before performing data operations. Permission
 * to list the bucket is enforced by the policy and ACL middleware.
 */
final class HeadBucketHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        return new Response(
            status: 200,
            headers: [
                'x-amz-bucket-region' => $bucketInfo->region,
                'x-amz-access-point-alias' => 'false',
            ],
        );
    }
}
