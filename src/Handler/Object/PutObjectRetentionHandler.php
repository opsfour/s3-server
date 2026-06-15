<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectRetention (PUT /{bucket}/{key}?retention).
 *
 * Parses the Retention XML body and stores the retention
 * configuration for the specified object version.
 */
final class PutObjectRetentionHandler implements RequestHandler
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
        $retention = XmlRequestParser::parseRetention($body);

        // Validate that RetainUntilDate is a parseable date.
        try {
            $newDate = new \DateTimeImmutable($retention['retainUntilDate']);
        } catch (\Exception) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid RetainUntilDate format.',
            );
        }

        // Enforce COMPLIANCE immutability: can only extend, never shorten or change mode.
        $existing = $this->metadata->getObjectRetention($bucket, $key, $versionId);
        if ($existing !== null && $existing['mode'] === 'COMPLIANCE') {
            $existingDate = new \DateTimeImmutable($existing['retainUntilDate']);
            if ($retention['mode'] !== 'COMPLIANCE' || $newDate < $existingDate) {
                throw new AccessDeniedException(
                    'Object protected by COMPLIANCE retention. Can only extend retention period.',
                );
            }
        }

        $this->metadata->putObjectRetention(
            $bucket,
            $key,
            $retention['mode'],
            $retention['retainUntilDate'],
            $versionId,
        );

        return new Response(status: 200);
    }
}
