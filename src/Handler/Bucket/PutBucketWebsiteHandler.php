<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

final class PutBucketWebsiteHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $config = XmlRequestParser::parseWebsiteConfiguration($body);

        $this->metadata->putBucketWebsite(
            $bucket,
            $config['indexDocument'],
            $config['errorDocument'],
            $config['redirectAllHost'],
            $config['redirectAllProtocol'],
            $config['routingRules'],
        );

        return new Response(status: 200);
    }
}
