<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutBucketCors (PUT /{bucket}?cors).
 *
 * Parses the CORSConfiguration XML body and stores the rules.
 */
final class PutBucketCorsHandler implements RequestHandler
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

        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $rules = XmlRequestParser::parseCorsConfiguration($body);

        $this->metadata->putBucketCors($bucket, $rules);

        return new Response(status: 200);
    }
}
