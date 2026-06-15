<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The AWS access key ID you provided does not exist in our records.
 */
final class InvalidAccessKeyIdException extends S3Exception
{
    public function __construct(string $message = 'The AWS access key ID you provided does not exist in our records.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidAccessKeyId';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
