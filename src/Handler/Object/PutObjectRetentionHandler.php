<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\Iso8601Timestamp;
use OpsFour\S3Server\Http\ObjectVersionResolver;
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

        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $retention = XmlRequestParser::parseRetention($body);

        $newDate = Iso8601Timestamp::parse($retention['retainUntilDate']);
        if ($newDate === null) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid RetainUntilDate format.',
            );
        }
        if ($newDate <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'RetainUntilDate must be in the future.',
            );
        }

        $this->metadata->transaction(function () use ($bucketInfo, $request, $bucket, $key, $retention, $newDate): void {
            $this->metadata->lockOwnerForUpdate($bucketInfo->ownerId);
            $objectInfo = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
            $versionId = $objectInfo->versionId;

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
            if ($existing !== null && $existing['mode'] === 'GOVERNANCE') {
                $existingDate = new \DateTimeImmutable($existing['retainUntilDate']);
                $weakensRetention = $retention['mode'] !== 'GOVERNANCE' || $newDate < $existingDate;
                $bypassRequested = strtolower(
                    $request->getHeader('x-amz-bypass-governance-retention') ?? '',
                ) === 'true';
                $canBypass = $request->getAttribute('s3.canBypassGovernanceRetention') === true;
                if ($weakensRetention && (! $bypassRequested || ! $canBypass)) {
                    throw new AccessDeniedException(
                        'Shortening or changing GOVERNANCE retention requires s3:BypassGovernanceRetention.',
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
        });

        return new Response(status: 200);
    }
}
