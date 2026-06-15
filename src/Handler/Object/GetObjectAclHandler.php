<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetObjectAcl (GET /{bucket}/{key}?acl).
 *
 * Returns the ACL for the specified object. If no explicit ACL
 * has been set, returns a default ACL granting FULL_CONTROL to
 * the object owner.
 */
final class GetObjectAclHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        // Verify the object exists.
        if (! $this->metadata->objectExists($bucket, $key)) {
            throw new NoSuchKeyException;
        }

        $resourceName = $bucket.'/'.$key;
        $grants = $this->metadata->getAcl('object', $resourceName);

        // Default: owner gets FULL_CONTROL if no ACL stored.
        if ($grants === []) {
            $grants = [
                [
                    'granteeType' => 'CanonicalUser',
                    'granteeId' => $ownerId,
                    'permission' => 'FULL_CONTROL',
                ],
            ];
        }

        $credential = $request->getAttribute('credential');
        $displayName = ($credential !== null && $credential->ownerId === $ownerId)
            ? $credential->displayName
            : '';

        $displayNameMap = ($credential !== null && $credential->ownerId !== '')
            ? [$credential->ownerId => $credential->displayName]
            : [];
        $xml = XmlResponseBuilder::aclResult($ownerId, $displayName, $grants, $displayNameMap);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
