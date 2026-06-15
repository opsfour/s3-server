<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The TagSet does not exist.
 */
final class NoSuchTagSetException extends S3Exception
{
    public function __construct(string $message = 'The TagSet does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchTagSet';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
