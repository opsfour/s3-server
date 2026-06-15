<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * An invalid argument was provided.
 */
final class InvalidArgumentException extends S3Exception
{
    public function __construct(string $message = 'Invalid Argument.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidArgument';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
