<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The bucket you tried to delete is not empty.
 */
final class BucketNotEmptyException extends S3Exception
{
    public function __construct(string $message = 'The bucket you tried to delete is not empty.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'BucketNotEmpty';
    }

    public function getHttpStatus(): int
    {
        return 409;
    }
}
