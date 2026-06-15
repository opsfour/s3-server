<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectTagging (PUT /{bucket}/{key}?tagging).
 *
 * Parses the Tagging XML body and stores up to 10 tags
 * for the object.
 */
final class PutObjectTaggingHandler implements RequestHandler
{
    private const int MAX_OBJECT_TAGS = 10;

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
            throw new NoSuchBucketException();
        }

        // Verify the object exists.
        if (! $this->metadata->objectExists($bucket, $key)) {
            throw new NoSuchKeyException();
        }

        // Parse the XML body.
        $body = $request->getBody()->buffer();
        $tags = XmlRequestParser::parseTagging($body);

        if (count($tags) > self::MAX_OBJECT_TAGS) {
            throw new InvalidArgumentException(
                'Object tags cannot be greater than ' . self::MAX_OBJECT_TAGS,
            );
        }

        $this->metadata->putObjectTagging($bucket, $key, $tags);

        return new Response(status: 200);
    }
}
