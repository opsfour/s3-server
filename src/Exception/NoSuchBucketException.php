<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified bucket does not exist.
 */
final class NoSuchBucketException extends S3Exception
{
    public function __construct(string $message = 'The specified bucket does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchBucket';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
