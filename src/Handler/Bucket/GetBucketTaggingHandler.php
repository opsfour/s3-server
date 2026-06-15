<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchTagSetException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetBucketTagging (GET /{bucket}?tagging).
 *
 * Returns the tag set associated with the bucket. Throws
 * NoSuchTagSet if no tags have been set on the bucket.
 */
final class GetBucketTaggingHandler implements RequestHandler
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
            throw new NoSuchBucketException;
        }

        $tags = $this->metadata->getBucketTagging($bucket);

        // Real S3 throws NoSuchTagSet for bucket tagging when no tags exist.
        if ($tags === []) {
            throw new NoSuchTagSetException;
        }

        $xml = XmlResponseBuilder::taggingResult($tags);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
