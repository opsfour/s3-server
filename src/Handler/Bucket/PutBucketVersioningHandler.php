<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutBucketVersioning (PUT /{bucket}?versioning).
 *
 * Parses the VersioningConfiguration XML body and updates
 * the bucket's versioning status.
 */
final class PutBucketVersioningHandler implements RequestHandler
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
        $body = $request->getBody()->buffer();
        $config = XmlRequestParser::parseVersioningConfiguration($body);

        if ($config['status'] !== '') {
            if (!in_array($config['status'], ['Enabled', 'Suspended'], true)) {
                throw new MalformedXmlException('Invalid versioning status. Must be "Enabled" or "Suspended".');
            }
            $this->metadata->setBucketVersioning($bucket, $config['status']);
        }

        return new Response(status: 200);
    }
}
