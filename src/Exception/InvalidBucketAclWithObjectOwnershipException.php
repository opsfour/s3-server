<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class InvalidBucketAclWithObjectOwnershipException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('BucketOwnerEnforced requires a private bucket ACL.');
    }

    public function getErrorCode(): string
    {
        return 'InvalidBucketAclWithObjectOwnership';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
