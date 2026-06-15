<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The bucket policy does not exist.
 */
final class NoSuchBucketPolicyException extends S3Exception
{
    public function __construct(string $message = 'The bucket policy does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchBucketPolicy';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
