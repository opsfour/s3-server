<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Acl;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\AccessControlListNotSupportedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidBucketAclWithObjectOwnershipException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class BucketOwnership
{
    public const string OBJECT_WRITER = 'ObjectWriter';
    public const string BUCKET_OWNER_ENFORCED = 'BucketOwnerEnforced';

    public static function isEnforced(MetadataStore $metadata, string $bucket): bool
    {
        return $metadata->getBucketOwnershipControls($bucket) === self::BUCKET_OWNER_ENFORCED;
    }

    public static function validateMode(string $mode): string
    {
        if ($mode === 'BucketOwnerPreferred') {
            throw new NotImplementedException('BucketOwnerPreferred is not supported.');
        }
        if (!in_array($mode, [self::OBJECT_WRITER, self::BUCKET_OWNER_ENFORCED], true)) {
            throw new InvalidArgumentException('Invalid ObjectOwnership value.');
        }

        return $mode;
    }

    public static function assertPrivateBucketAcl(MetadataStore $metadata, string $bucket, string $ownerId): void
    {
        $grants = $metadata->getAcl('bucket', $bucket);
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
