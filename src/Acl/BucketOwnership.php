<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Acl;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\AccessControlListNotSupportedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidBucketAclWithObjectOwnershipException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class BucketOwnership
{
    public const string OBJECT_WRITER = 'ObjectWriter';
    public const string BUCKET_OWNER_PREFERRED = 'BucketOwnerPreferred';
    public const string BUCKET_OWNER_ENFORCED = 'BucketOwnerEnforced';

    public static function isEnforced(MetadataStore $metadata, string $bucket): bool
    {
        return $metadata->getBucketOwnershipControls($bucket) === self::BUCKET_OWNER_ENFORCED;
    }

    public static function validateMode(string $mode): string
    {
        if (!in_array($mode, [self::OBJECT_WRITER, self::BUCKET_OWNER_PREFERRED, self::BUCKET_OWNER_ENFORCED], true)) {
            throw new InvalidArgumentException('Invalid ObjectOwnership value.');
        }

        return $mode;
    }

    public static function effectiveMode(MetadataStore $metadata, string $bucket): string
    {
        return self::validateMode(
            $metadata->getBucketOwnershipControls($bucket) ?? self::OBJECT_WRITER,
        );
    }

    public static function resolveObjectWrite(
        Request $request,
        string $ownershipMode,
        string $writerOwnerId,
        string $bucketOwnerId,
    ): ObjectWriteOwnershipDecision {
        $ownershipMode = self::validateMode($ownershipMode);
        $requestedAcl = AclGrantResolver::fromHeaders(
            $request,
            $writerOwnerId,
            'object',
            $bucketOwnerId,
        ) ?? AclGrantResolver::privateAcl($writerOwnerId);

        if ($ownershipMode === self::BUCKET_OWNER_ENFORCED) {
            self::assertWriteAclAllowed($request);

            return new ObjectWriteOwnershipDecision(
                $bucketOwnerId,
                AclGrantResolver::privateAcl($bucketOwnerId),
                $bucketOwnerId,
                $ownershipMode,
            );
        }

        if ($ownershipMode === self::BUCKET_OWNER_PREFERRED
            && $request->getHeader('x-amz-acl') === 'bucket-owner-full-control') {
            return new ObjectWriteOwnershipDecision(
                $bucketOwnerId,
                AclGrantResolver::privateAcl($bucketOwnerId),
                $bucketOwnerId,
                $ownershipMode,
            );
        }

        return new ObjectWriteOwnershipDecision(
            $writerOwnerId,
            $requestedAcl,
            $writerOwnerId,
            $ownershipMode,
        );
    }

    public static function resolveDeleteMarkerOwner(
        string $ownershipMode,
        string $bucketOwnerId,
        string $requesterOwnerId,
        bool $lifecycle = false,
    ): string {
        $ownershipMode = self::validateMode($ownershipMode);

        if ($lifecycle || $requesterOwnerId === '' || $ownershipMode === self::BUCKET_OWNER_ENFORCED) {
            return $bucketOwnerId;
        }

        return $requesterOwnerId;
    }

    public static function assertObjectWriteDecisionCurrent(
        MetadataStore $metadata,
        string $bucket,
        Request $request,
        string $writerOwnerId,
        string $bucketOwnerId,
        ObjectWriteOwnershipDecision $decision,
    ): void {
        $currentMode = self::effectiveMode($metadata, $bucket);
        if ($currentMode !== $decision->ownershipMode) {
            throw new OperationAbortedException(
                'Bucket Object Ownership changed while the object write was being committed.',
            );
        }

        $current = self::resolveObjectWrite(
            $request,
            $currentMode,
            $writerOwnerId,
            $bucketOwnerId,
        );
        if (! $decision->equals($current)) {
            throw new OperationAbortedException(
                'The destination ownership decision changed while the object write was being committed.',
            );
        }
    }

    public static function assertPrivateBucketAcl(MetadataStore $metadata, string $bucket, string $ownerId): void
    {
        $grants = AclGrantResolver::validateStoredGrants($metadata->getAcl('bucket', $bucket));
        if (count($grants) > 1) {
            throw new InvalidBucketAclWithObjectOwnershipException();
        }
        foreach ($grants as $grant) {
            if ($grant['granteeType'] !== 'CanonicalUser'
                || $grant['granteeId'] !== $ownerId
                || $grant['permission'] !== 'FULL_CONTROL') {
                throw new InvalidBucketAclWithObjectOwnershipException();
            }
        }
    }

    public static function assertWriteAclAllowed(Request $request): void
    {
        $acl = $request->getHeader('x-amz-acl');
        if ($acl !== null && $acl !== 'bucket-owner-full-control') {
            throw new AccessControlListNotSupportedException();
        }
        foreach ($request->getHeaders() as $name => $_values) {
            if (str_starts_with(strtolower($name), 'x-amz-grant-')) {
                throw new AccessControlListNotSupportedException();
            }
        }
    }
}
