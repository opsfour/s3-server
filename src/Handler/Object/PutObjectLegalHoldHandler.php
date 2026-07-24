<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectLegalHold (PUT /{bucket}/{key}?legal-hold).
 *
 * Parses the LegalHold XML body and stores the legal hold
 * status for the specified object version.
 */
final class PutObjectLegalHoldHandler implements RequestHandler
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

        // Object Lock must be enabled on the bucket.
        $lockConfig = $this->metadata->getObjectLockConfig($bucket);
        if ($lockConfig === null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Bucket is missing Object Lock Configuration',
            );
        }

        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $legalHold = XmlRequestParser::parseLegalHold($body);

        $this->metadata->transaction(function () use ($bucketInfo, $request, $bucket, $key, $legalHold): void {
            $this->metadata->lockOwnerForUpdate($bucketInfo->ownerId);
            $objectInfo = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
            $this->metadata->putObjectLegalHold(
                $bucket,
                $key,
                $legalHold['status'],
                $objectInfo->versionId,
            );
        });

        return new Response(status: 200);
    }
}
