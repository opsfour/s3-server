<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified key does not exist.
 */
final class NoSuchKeyException extends S3Exception
{
    public function __construct(string $message = 'The specified key does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchKey';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
