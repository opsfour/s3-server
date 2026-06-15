<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The requested range is not satisfiable.
 */
final class InvalidRangeException extends S3Exception
{
    public function __construct(string $message = 'The requested range is not satisfiable.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidRange';
    }

    public function getHttpStatus(): int
    {
        return 416;
    }
}
