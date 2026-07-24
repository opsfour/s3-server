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
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Policy\PolicyDocumentValidator;
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
        $ownerId = (string) $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Read raw JSON body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 131_072);

        if (trim($body) === '') {
            throw new MalformedXmlException('Request body is empty.');
        }

        PolicyDocumentValidator::validateBucketPolicy($body);

        $this->metadata->transaction(function () use ($bucketInfo, $ownerId, $bucket, $body): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null && $pab['blockPublicPolicy'] && PolicyEvaluator::isPublicPolicy($body)) {
                throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
            }
            $this->metadata->putBucketPolicy($bucket, $body);
        });

        return new Response(status: 204);
    }
}
