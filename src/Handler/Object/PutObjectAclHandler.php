<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectAcl (PUT /{bucket}/{key}?acl).
 *
 * Supports both canned ACLs via the x-amz-acl header and
 * explicit ACL XML in the request body.
 */
final class PutObjectAclHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = (string) $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $grants = AclGrantResolver::fromHeaders($request, $ownerId, 'object', $bucketInfo->ownerId);
        if ($grants === null) {
            // Parse XML body.
            $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
            $parsed = XmlRequestParser::parseAccessControlPolicy($body);
            $grants = AclGrantResolver::validateGrants($parsed['grants']);
        }

        $versionId = $this->metadata->transaction(function () use ($bucketInfo, $ownerId, $request, $bucket, $key, $grants): ?string {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $pab = $this->metadata->getPublicAccessBlock($bucket);
            if ($pab !== null && $pab['blockPublicAcls'] && AclGrantResolver::isPublic($grants)) {
                throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
            }

            $object = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
            $resourceName = ObjectVersionResolver::aclResourceName($bucket, $key, $object->versionId);
            $this->metadata->putAcl('object', $bucket . '/' . $key, $ownerId, []);
            $this->metadata->putAcl('object', $resourceName, $ownerId, $grants);

            return $object->versionId;
        });

        return new Response(
            status: 200,
            headers: array_filter(['x-amz-version-id' => $versionId]),
        );
    }
}
