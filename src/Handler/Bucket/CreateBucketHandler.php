<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
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
        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 65_536);

        if ($body !== '') {
            $parsed = XmlRequestParser::parseCreateBucketConfiguration($body);
            if ($parsed['locationConstraint'] !== '') {
                $region = $parsed['locationConstraint'];
            }
        }
        $aclGrants = AclGrantResolver::fromHeaders($request, $ownerId, 'bucket')
            ?? AclGrantResolver::privateAcl($ownerId);
        $objectLockEnabled = strtolower(
            $request->getHeader('x-amz-bucket-object-lock-enabled') ?? 'false',
        ) === 'true';

        // 3. Serialize same-account quota checks and metadata creation.
        $alreadyOwned = false;
        $this->metadata->transaction(function () use ($ownerId, $bucket, $region, $aclGrants, $objectLockEnabled, &$alreadyOwned): void {
            $this->metadata->lockOwnerForUpdate($ownerId);

            $existingOwner = $this->metadata->getBucketOwner($bucket);
            if ($existingOwner !== null) {
                if ($existingOwner === $ownerId) {
                    $alreadyOwned = true;

                    return;
                }
                throw new BucketAlreadyExistsException();
            }

            $this->quotas?->assertCanCreateBucket($ownerId);
            $this->metadata->createBucket($ownerId, $bucket, $region);
            $this->metadata->putAcl('bucket', $bucket, $ownerId, $aclGrants);
            if ($objectLockEnabled) {
                $this->metadata->setBucketVersioning($bucket, 'Enabled');
                $this->metadata->putObjectLockConfig($bucket, [
                    'objectLockEnabled' => 'Enabled',
                ]);
            }
        });

        if ($alreadyOwned) {
            // A previous attempt may have committed metadata before storage
            // provisioning failed. Re-run the idempotent backend operation so a
            // retry repairs that partial state.
            $this->storage->createBucket($bucket);

            return new Response(status: 200, headers: ['Location' => '/' . $bucket]);
        }

        // 4. Create in storage backend.
        try {
            $this->storage->createBucket($bucket);
        } catch (\Throwable $e) {
            // Rollback metadata on storage failure to avoid orphaned records.
            try {
                $this->metadata->deleteBucket($ownerId, $bucket);
            } catch (\Throwable) {
            }
            throw $e;
        }

        // 5. Return 200 with Location header.
        return new Response(
            status: 200,
            headers: ['Location' => '/' . $bucket],
        );
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
     * - Strict mode (default): reject AWS-reserved prefixes and suffixes.
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

        if (! preg_match('/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $name)) {
            throw new InvalidBucketNameException(
                'Bucket name can only contain lowercase letters, numbers, hyphens, and dots.',
            );
        }

        if (str_contains($name, '..')) {
            throw new InvalidBucketNameException(
                'Bucket name must not contain consecutive dots.',
            );
        }

        // Must not be formatted as an IP address.
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $name)) {
            throw new InvalidBucketNameException(
                'Bucket name must not be formatted as an IP address.',
            );
        }

        if ($strict) {
            foreach (['xn--', 'sthree-', 'amzn-s3-demo-'] as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    throw new InvalidBucketNameException(
                        "Bucket name must not start with the reserved \"{$prefix}\" prefix.",
                    );
                }
            }

            foreach (['-s3alias', '--ol-s3', '.mrap', '--x-s3', '--table-s3', '-an'] as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    throw new InvalidBucketNameException(
                        "Bucket name must not end with the reserved \"{$suffix}\" suffix.",
                    );
                }
            }
        }
    }
}
