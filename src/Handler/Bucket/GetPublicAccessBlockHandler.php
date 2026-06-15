<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchPublicAccessBlockConfigurationException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class GetPublicAccessBlockHandler implements RequestHandler
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

        $config = $this->metadata->getPublicAccessBlock($bucket);
        if ($config === null) {
            throw new NoSuchPublicAccessBlockConfigurationException();
        }

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNs(null, 'PublicAccessBlockConfiguration', 'http://s3.amazonaws.com/doc/2006-03-01/');
        $writer->writeElement('BlockPublicAcls', $config['blockPublicAcls'] ? 'true' : 'false');
        $writer->writeElement('IgnorePublicAcls', $config['ignorePublicAcls'] ? 'true' : 'false');
        $writer->writeElement('BlockPublicPolicy', $config['blockPublicPolicy'] ? 'true' : 'false');
        $writer->writeElement('RestrictPublicBuckets', $config['restrictPublicBuckets'] ? 'true' : 'false');
        $writer->endElement();
        $writer->endDocument();

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $writer->outputMemory(),
        );
    }
}
