<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles GetBucketLocation (GET /{bucket}?location).
 *
 * Returns the LocationConstraint for the specified bucket.
 * Per S3 behavior, us-east-1 returns an empty LocationConstraint
 * element (null region), while all other regions return their name.
 */
final class GetBucketLocationHandler implements RequestHandler
{
    private const string S3_NAMESPACE = 'http://s3.amazonaws.com/doc/2006-03-01/';

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

        // Build the LocationConstraint XML response.
        // Per S3 spec: us-east-1 is represented as an empty LocationConstraint,
        // all other regions return their name as the element text.
        $region = $bucketInfo->region;
        $locationValue = ($region === 'us-east-1') ? '' : $region;

        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElementNs(null, 'LocationConstraint', self::S3_NAMESPACE);

        if ($locationValue !== '') {
            $writer->text($locationValue);
        }

        $writer->endElement();
        $writer->endDocument();

        $xml = $writer->outputMemory();

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
