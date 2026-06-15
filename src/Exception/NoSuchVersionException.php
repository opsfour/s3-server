<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The version ID specified in the request does not match an existing version.
 */
final class NoSuchVersionException extends S3Exception
{
    public function __construct(string $message = 'The version ID specified in the request does not match an existing version.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchVersion';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
