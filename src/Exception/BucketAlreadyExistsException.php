<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The requested bucket name is not available (owned by another account).
 */
final class BucketAlreadyExistsException extends S3Exception
{
    public function __construct(string $message = 'The requested bucket name is not available. The bucket namespace is shared by all users of the system.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'BucketAlreadyExists';
    }

    public function getHttpStatus(): int
    {
        return 409;
    }
}
