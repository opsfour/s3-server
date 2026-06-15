<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchBucketPolicyException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles GetBucketPolicy (GET /{bucket}?policy).
 *
 * Returns the raw JSON bucket policy. The response Content-Type
 * is application/json (not XML like most S3 responses).
 */
final class GetBucketPolicyHandler implements RequestHandler
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
            throw new NoSuchBucketException;
        }

        $policyJson = $this->metadata->getBucketPolicy($bucket);

        if ($policyJson === null) {
            throw new NoSuchBucketPolicyException;
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: $policyJson,
        );
    }
}
