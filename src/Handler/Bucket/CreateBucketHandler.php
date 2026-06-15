<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\BucketAlreadyOwnedByYouException;
use OpsFour\S3Server\Exception\InvalidBucketNameException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles CreateBucket (PUT /{bucket}).
 *
 * Creates a new S3 bucket with optional LocationConstraint from
 * the CreateBucketConfiguration XML body. Validates bucket naming
 * rules per the S3 specification, creates the metadata record, and
 * provisions the storage backend.
 */
final class CreateBucketHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly S3ServerConfig $config,
        private readonly ?QuotaManager $quotas = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // 1. Validate bucket name against S3 naming rules.
        self::validateBucketName($bucket, $this->config->strictBucketNaming);

        // 2. Parse optional CreateBucketConfiguration XML body.
        $region = $this->config->region;
        $body = $request->getBody()->buffer();

        if ($body !== '') {
            $parsed = XmlRequestParser::parseCreateBucketConfiguration($body);
            if ($parsed['locationConstraint'] !== '') {
                $region = $parsed['locationConstraint'];
            }
        }

        // 3. Check if bucket already exists.
        $existingOwner = $this->metadata->getBucketOwner($bucket);
        if ($existingOwner !== null) {
            if ($existingOwner === $ownerId) {
                // AWS S3: same-owner recreation returns 200 (idempotent).
                return new Response(status: 200, headers: ['Location' => '/' . $bucket]);
            }
            throw new BucketAlreadyExistsException;
        }

        $this->quotas?->assertCanCreateBucket($ownerId);

        // 4. Create in metadata store.
        $this->metadata->createBucket($ownerId, $bucket, $region);

        // 5. Create in storage backend.
        try {
            $this->storage->createBucket($bucket);
        } catch (\Throwable $e) {
            // Rollback metadata on storage failure to avoid orphaned records.
            try { $this->metadata->deleteBucket($ownerId, $bucket); } catch (\Throwable) {}
            throw $e;
        }

        // 6. Apply canned ACL if x-amz-acl header is present.
        $cannedAcl = $request->getHeader('x-amz-acl');
        if ($cannedAcl !== null && $cannedAcl !== '' && $cannedAcl !== 'private') {
            $grants = self::expandCannedAcl($cannedAcl, $ownerId);
            $this->metadata->putAcl('bucket', $bucket, $ownerId, $grants);
        }

        // 7. Return 200 with Location header.
        return new Response(
            status: 200,
            headers: ['Location' => '/'.$bucket],
        );
    }

    private const string ALL_USERS_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';
    private const string AUTH_USERS_URI = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    /**
     * Expand a canned ACL name into grants.
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function expandCannedAcl(string $cannedAcl, string $ownerId): array
    {
        $ownerGrant = ['granteeType' => 'CanonicalUser', 'granteeId' => $ownerId, 'permission' => 'FULL_CONTROL'];

        return match ($cannedAcl) {
            'private' => [$ownerGrant],
            'public-read' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'READ'],
            ],
            'public-read-write' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'READ'],
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'WRITE'],
            ],
            'authenticated-read' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::AUTH_USERS_URI, 'permission' => 'READ'],
            ],
            default => [$ownerGrant],
        };
    }

    /**
     * Validate a bucket name against S3 naming rules.
     *
     * S3 bucket naming rules:
     * - Must be between 3 and 63 characters long.
     * - Can contain only lowercase letters, numbers, hyphens, and dots.
     * - Must start and end with a letter or number.
     * - Must not contain consecutive dots.
     * - Must not be formatted as an IP address (e.g., 192.168.5.4).
     * - Strict mode (default): no dots allowed (AWS recommendation since 2018).
     *
     * @throws InvalidBucketNameException If the bucket name is invalid.
     */
    private static function validateBucketName(string $name, bool $strict): void
    {
        $length = strlen($name);

        // Length check: 3-63 characters.
        if ($length < 3 || $length > 63) {
            throw new InvalidBucketNameException(
                'Bucket name must be between 3 and 63 characters long.',
            );
        }

        // Must start with a lowercase letter or number.
        if (! preg_match('/^[a-z0-9]/', $name)) {
            throw new InvalidBucketNameException(
                'Bucket name must start with a lowercase letter or number.',
            );
        }

        // Must end with a lowercase letter or number.
        if (! preg_match('/[a-z0-9]$/', $name)) {
            throw new InvalidBucketNameException(
                'Bucket name must end with a lowercase letter or number.',
            );
        }

        if ($strict) {
            // Strict mode: only lowercase letters, numbers, and hyphens (no dots).
            if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $name)) {
                throw new InvalidBucketNameException(
                    'Bucket name can only contain lowercase letters, numbers, and hyphens.',
                );
            }

            // No consecutive hyphens.
            if (str_contains($name, '--')) {
                throw new InvalidBucketNameException(
                    'Bucket name must not contain consecutive hyphens.',
                );
            }
        } else {
            // Relaxed mode: lowercase letters, numbers, hyphens, and dots.
            if (! preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $name)) {
                throw new InvalidBucketNameException(
                    'Bucket name can only contain lowercase letters, numbers, hyphens, and dots.',
                );
            }

            // No consecutive dots.
            if (str_contains($name, '..')) {
                throw new InvalidBucketNameException(
                    'Bucket name must not contain consecutive dots.',
                );
            }

            // No dot adjacent to hyphen (e.g., "my-.bucket" or "my.-bucket").
            if (preg_match('/\.\-|\-\./', $name)) {
                throw new InvalidBucketNameException(
                    'Bucket name must not contain a dot adjacent to a hyphen.',
                );
            }
        }

        // Must not be formatted as an IP address.
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $name)) {
            throw new InvalidBucketNameException(
                'Bucket name must not be formatted as an IP address.',
            );
        }

        // Must not start with "xn--" (internationalized domain name prefix).
        if (str_starts_with($name, 'xn--')) {
            throw new InvalidBucketNameException(
                'Bucket name must not start with the "xn--" prefix.',
            );
        }

        // Must not end with "-s3alias" or "--ol-s3".
        if (str_ends_with($name, '-s3alias') || str_ends_with($name, '--ol-s3')) {
            throw new InvalidBucketNameException(
                'Bucket name must not end with "-s3alias" or "--ol-s3".',
            );
        }
    }
}
