<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchLifecycleConfigurationException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class GetBucketLifecycleHandler implements RequestHandler
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
            throw new NoSuchBucketException;
        }

        $rules = $this->metadata->getBucketLifecycle($bucket);
        if (empty($rules)) {
            throw new NoSuchLifecycleConfigurationException;
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: XmlResponseBuilder::lifecycleConfiguration($rules),
        );
    }
}
