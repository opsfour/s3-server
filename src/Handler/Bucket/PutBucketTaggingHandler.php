<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutBucketTagging (PUT /{bucket}?tagging).
 *
 * Parses the Tagging XML body and stores up to 50 tags
 * for the bucket.
 */
final class PutBucketTaggingHandler implements RequestHandler
{
    private const int MAX_BUCKET_TAGS = 50;

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

        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $tags = XmlRequestParser::parseTagging($body);

        $tags = ObjectTagValidator::validate($tags, self::MAX_BUCKET_TAGS);

        $this->metadata->putBucketTagging($bucket, $tags);

        return new Response(status: 204);
    }
}
