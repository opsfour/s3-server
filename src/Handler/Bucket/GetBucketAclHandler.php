<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetBucketAcl (GET /{bucket}?acl).
 *
 * Returns the ACL for the specified bucket. If no explicit ACL
 * has been set, returns a default ACL granting FULL_CONTROL to
 * the bucket owner.
 */
final class GetBucketAclHandler implements RequestHandler
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

        $grants = $this->metadata->getAcl('bucket', $bucket);

        // Default: owner gets FULL_CONTROL if no ACL stored.
        $bucketOwner = $bucketInfo->ownerId;
        if ($grants === []) {
            $grants = [
                [
                    'granteeType' => 'CanonicalUser',
                    'granteeId' => $bucketOwner,
                    'permission' => 'FULL_CONTROL',
                ],
            ];
        }

        // Resolve display name from credential if available.
        $credential = $request->getAttribute('credential');
        $displayName = ($credential !== null && $credential->ownerId === $bucketOwner)
            ? $credential->displayName
            : '';

        $displayNameMap = ($credential !== null && $credential->ownerId !== '')
            ? [$credential->ownerId => $credential->displayName]
            : [];
        $xml = XmlResponseBuilder::aclResult($bucketOwner, $displayName, $grants, $displayNameMap);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
