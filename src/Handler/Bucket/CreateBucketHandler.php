<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
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
        $objectOwnership = BucketOwnership::validateMode(
            $request->getHeader('x-amz-object-ownership') ?? BucketOwnership::OBJECT_WRITER,
        );
        $objectOwnershipHeader = $request->getHeader('x-amz-object-ownership');
        if ($objectOwnership === BucketOwnership::BUCKET_OWNER_ENFORCED) {
            foreach ($aclGrants as $grant) {
                if ($grant['granteeType'] !== 'CanonicalUser'
                    || $grant['granteeId'] !== $ownerId
                    || $grant['permission'] !== 'FULL_CONTROL') {
                    throw new \OpsFour\S3Server\Exception\InvalidBucketAclWithObjectOwnershipException();
                }
            }
        }
        $objectLockHeader = $request->getHeader('x-amz-bucket-object-lock-enabled');
        if ($objectLockHeader !== null && ! in_array($objectLockHeader, ['true', 'false'], true)) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'x-amz-bucket-object-lock-enabled must be true or false.',
            );
        }
        $objectLockEnabled = $objectLockHeader === 'true';

        // 3. Reject known name conflicts and quota violations before touching
        // storage. The checks run again at commit because another request can
        // create the bucket while the backend operation is in progress.
        $this->metadata->transaction(function () use ($ownerId, $bucket, $region, $objectLockHeader, $objectOwnershipHeader): void {
            $this->metadata->lockOwnerForUpdate($ownerId);
            $existingOwner = $this->metadata->getBucketOwner($bucket);
            if ($existingOwner !== null && $existingOwner !== $ownerId) {
                throw new BucketAlreadyExistsException();
            }
            if ($existingOwner === $ownerId) {
                $this->assertExistingBucketSettings($bucket, $region, $objectLockHeader, $objectOwnershipHeader);
            }
            if ($existingOwner === null) {
                $this->quotas?->assertCanCreateBucket($ownerId);
            }
        });

        // 4. Provision the idempotent backend namespace before publishing the
        // bucket in metadata. A failed first provision then stays invisible to
        // HeadBucket and object requests; a retry can safely run this again.
        $this->storage->createBucket($bucket);

        // 5. Publish metadata only after storage succeeded. Keep the owner
        // lock for the final name/quota check and all initial configuration.
        $this->metadata->transaction(function () use ($request, $ownerId, $bucket, $region, $aclGrants, $objectLockEnabled, $objectOwnership, $objectLockHeader, $objectOwnershipHeader): void {
            $this->metadata->lockOwnerForUpdate($ownerId);
            (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            $existingOwner = $this->metadata->getBucketOwner($bucket);
            if ($existingOwner !== null) {
                if ($existingOwner === $ownerId) {
                    $this->assertExistingBucketSettings($bucket, $region, $objectLockHeader, $objectOwnershipHeader);
                    return;
                }
                throw new BucketAlreadyExistsException();
            }

            $this->quotas?->assertCanCreateBucket($ownerId);
            $this->metadata->createBucket($ownerId, $bucket, $region);
            $this->metadata->putAcl('bucket', $bucket, $ownerId, $aclGrants);
            $this->metadata->putBucketOwnershipControls($bucket, $objectOwnership);
            if ($objectLockEnabled) {
                $this->metadata->setBucketVersioning($bucket, 'Enabled');
                $this->metadata->putObjectLockConfig($bucket, [
                    'objectLockEnabled' => 'Enabled',
                ]);
            }
        });

        // 6. Return 200 with Location header.
        return new Response(
            status: 200,
            headers: ['Location' => '/' . $bucket],
        );
    }

    private function assertExistingBucketSettings(
        string $bucket,
        string $region,
        ?string $objectLockHeader,
        ?string $objectOwnershipHeader,
    ): void {
        if ($this->metadata->getBucket($bucket)?->region !== $region) {
            throw new BucketAlreadyOwnedByYouException('The existing bucket has a different region.');
        }

        if ($objectLockHeader !== null
            && ($this->metadata->getObjectLockConfig($bucket) !== null) !== ($objectLockHeader === 'true')) {
            throw new BucketAlreadyOwnedByYouException('The existing bucket has different Object Lock settings.');
        }

        if ($objectOwnershipHeader !== null
            && ($this->metadata->getBucketOwnershipControls($bucket) ?? BucketOwnership::OBJECT_WRITER) !== $objectOwnershipHeader) {
            throw new BucketAlreadyOwnedByYouException('The existing bucket has different Object Ownership settings.');
        }
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
