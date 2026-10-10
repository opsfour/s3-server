<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class AccessControlListNotSupportedException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The bucket does not allow ACLs.');
    }

    public function getErrorCode(): string
    {
        return 'AccessControlListNotSupported';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
