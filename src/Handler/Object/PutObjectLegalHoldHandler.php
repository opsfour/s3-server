<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\QueryStringParser;
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

        // Parse versionId from query params.
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $versionId = $queryParams['versionId'] ?? null;

        // Verify the object (or specific version) exists.
        $objectInfo = ($versionId !== null)
            ? $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId)
            : $this->metadata->getObjectMetadata($bucket, $key);
        if ($objectInfo === null) {
            throw new NoSuchKeyException();
        }

        // Parse the XML body.
        $body = $request->getBody()->buffer();
        $legalHold = XmlRequestParser::parseLegalHold($body);

        $this->metadata->putObjectLegalHold($bucket, $key, $legalHold['status'], $versionId);

        return new Response(status: 200);
    }
}
