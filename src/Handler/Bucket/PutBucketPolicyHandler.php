<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;

/**
 * Handles PutBucketPolicy (PUT /{bucket}?policy).
 *
 * Reads the raw JSON body and stores it as the bucket policy.
 */
final class PutBucketPolicyHandler implements RequestHandler
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

        // Read raw JSON body.
        $body = $request->getBody()->buffer();

        if (trim($body) === '') {
            throw new MalformedXmlException('Request body is empty.');
        }

        // Validate that the body is valid JSON.
        try {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedXmlException('The policy is not valid JSON.');
        }

        // Check Public Access Block — reject public policies if blockPublicPolicy is set.
        $pab = $this->metadata->getPublicAccessBlock($bucket);
        if ($pab !== null && $pab['blockPublicPolicy'] && PolicyEvaluator::isPublicPolicy($body)) {
            throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
        }

        $this->metadata->putBucketPolicy($bucket, $body);

        return new Response(status: 204);
    }
}
