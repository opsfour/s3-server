<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
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
            throw new NoSuchBucketException();
        }

        $object = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
        $resourceName = ObjectVersionResolver::aclResourceName($bucket, $key, $object->versionId);
        $grants = $this->metadata->getAcl('object', $resourceName);
        if ($grants === [] && ($object->versionId === null || $object->versionId === 'null')) {
            $grants = $this->metadata->getAcl('object', $bucket . '/' . $key);
        }

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
            headers: array_filter([
                'Content-Type' => 'application/xml',
                'x-amz-version-id' => $object->versionId,
            ]),
            body: $xml,
        );
    }
}
