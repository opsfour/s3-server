<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\Iso8601Timestamp;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectRetentionDeletionStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
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
        $ownerId = (string) $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Object Lock must be enabled on the bucket.
        $lockConfig = $this->metadata->getObjectLockConfig($bucket);
        if ($lockConfig === null) {
            throw new InvalidRequestException(
                'Bucket is missing Object Lock Configuration',
            );
        }
        if (($lockConfig['objectLockEnabled'] ?? null) !== 'Enabled') {
            throw new InvalidRequestException('Stored Object Lock configuration is not enabled.');
        }

        // Parse the XML body.
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $retention = XmlRequestParser::parseRetention($body);

        $newDate = null;
        if ($retention !== null) {
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
        }

        $this->metadata->transaction(function () use ($bucketInfo, $ownerId, $request, $bucket, $key, $retention, $newDate): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            $objectInfo = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
            $versionId = $objectInfo->versionId;

            // Enforce COMPLIANCE immutability: can only extend, never shorten or change mode.
            $existing = $this->metadata->getObjectRetention($bucket, $key, $versionId);
            $existingDate = $existing !== null
                ? Iso8601Timestamp::parse($existing['retainUntilDate'])
                : null;
            if ($existing !== null && ($existingDate === null
                || ! in_array($existing['mode'], ['GOVERNANCE', 'COMPLIANCE'], true))) {
                throw new AccessDeniedException('Stored retention configuration is invalid and cannot be replaced safely.');
            }
            $bypassRequested = strtolower(
                $request->getHeader('x-amz-bypass-governance-retention') ?? '',
            ) === 'true';
            $canBypass = $request->getAttribute('s3.canBypassGovernanceRetention') === true;
            $retentionActive = $existing !== null
                && $existingDate > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($retention === null) {
                if ($existing === null) {
                    return;
                }
                if ($retentionActive && $existing['mode'] === 'COMPLIANCE') {
                    throw new AccessDeniedException(
                        'Active COMPLIANCE retention cannot be removed.',
                    );
                }
                if ($retentionActive && (! $bypassRequested || ! $canBypass)) {
                    throw new AccessDeniedException(
                        'Removing retention requires an authorized GOVERNANCE retention bypass.',
                    );
                }
                if (! $this->metadata instanceof ObjectRetentionDeletionStore) {
                    throw new \OpsFour\S3Server\Exception\NotImplementedException(
                        'Object retention removal is unavailable for this metadata backend.',
                    );
                }
                $this->metadata->deleteObjectRetention($bucket, $key, $versionId);

                return;
            }
            if ($retentionActive && $existing['mode'] === 'COMPLIANCE') {
                if ($retention['mode'] !== 'COMPLIANCE' || $newDate < $existingDate) {
                    throw new AccessDeniedException(
                        'Object protected by COMPLIANCE retention. Can only extend retention period.',
                    );
                }
            }
            if ($retentionActive && $existing['mode'] === 'GOVERNANCE') {
                // Moving GOVERNANCE to COMPLIANCE at the same or a later
                // date strengthens the lock and needs no bypass permission.
                $weakensRetention = $newDate < $existingDate;
                if ($weakensRetention && (! $bypassRequested || ! $canBypass)) {
                    throw new AccessDeniedException(
                        'Shortening GOVERNANCE retention requires s3:BypassGovernanceRetention.',
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
