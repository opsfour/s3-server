<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectLockConfiguration (PUT /{bucket}?object-lock).
 *
 * Parses the ObjectLockConfiguration XML body and stores
 * the configuration for the bucket.
 */
final class PutObjectLockConfigHandler implements RequestHandler
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
        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $config = XmlRequestParser::parseObjectLockConfiguration($body);

        $this->metadata->transaction(function () use ($bucketInfo, $bucket, $ownerId, $config): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            if ($this->metadata->getBucketVersioning($bucket) !== 'Enabled') {
                throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                    'Object Lock requires bucket versioning to be enabled.',
                );
            }
            $this->metadata->putObjectLockConfig($bucket, $config);
        });

        return new Response(status: 200);
    }
}
