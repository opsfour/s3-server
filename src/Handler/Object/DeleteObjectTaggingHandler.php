<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles DeleteObjectTagging (DELETE /{bucket}/{key}?tagging).
 *
 * Removes all tags from the specified object. Returns 204.
 */
final class DeleteObjectTaggingHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        // Verify the object exists.
        if (! $this->metadata->objectExists($bucket, $key)) {
            throw new NoSuchKeyException;
        }

        $this->metadata->deleteObjectTagging($bucket, $key);

        return new Response(status: 204);
    }
}
