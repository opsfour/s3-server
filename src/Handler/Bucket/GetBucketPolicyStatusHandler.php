<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchBucketPolicyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;

final class GetBucketPolicyStatusHandler implements RequestHandler
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

        $policyJson = $this->metadata->getBucketPolicy($bucket);
        if ($policyJson === null) {
            throw new NoSuchBucketPolicyException();
        }

        $isPublic = PolicyEvaluator::isPublicPolicy($policyJson);

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNs(null, 'PolicyStatus', 'http://s3.amazonaws.com/doc/2006-03-01/');
        $writer->writeElement('IsPublic', $isPublic ? 'true' : 'false');
        $writer->endElement();
        $writer->endDocument();

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $writer->outputMemory(),
        );
    }
}
