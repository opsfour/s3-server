<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified bucket is not valid.
 */
final class InvalidBucketNameException extends S3Exception
{
    public function __construct(string $message = 'The specified bucket is not valid.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidBucketName';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
