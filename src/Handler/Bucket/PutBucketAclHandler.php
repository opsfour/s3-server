<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutBucketAcl (PUT /{bucket}?acl).
 *
 * Supports both canned ACLs via the x-amz-acl header and
 * explicit ACL XML in the request body.
 *
 * Canned ACLs:
 * - private: owner gets FULL_CONTROL
 * - public-read: owner FULL_CONTROL + AllUsers READ
 * - public-read-write: owner FULL_CONTROL + AllUsers READ + AllUsers WRITE
 * - authenticated-read: owner FULL_CONTROL + AuthenticatedUsers READ
 */
final class PutBucketAclHandler implements RequestHandler
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

        $grants = AclGrantResolver::fromHeaders($request, $ownerId, 'bucket');
        if ($grants === null) {
            // Parse XML body.
            $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
            $parsed = XmlRequestParser::parseAccessControlPolicy($body);
            $grants = AclGrantResolver::validateGrants($parsed['grants']);
        }

        $this->metadata->transaction(function () use ($bucketInfo, $ownerId, $bucket, $grants): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null && $pab['blockPublicAcls'] && AclGrantResolver::isPublic($grants)) {
                throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
            }
            $this->metadata->putAcl('bucket', $bucket, $ownerId, $grants);
        });

        return new Response(status: 200);
    }
}
