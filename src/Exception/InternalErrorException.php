<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * We encountered an internal error. Please try again.
 */
final class InternalErrorException extends S3Exception
{
    public function __construct(string $message = 'We encountered an internal error. Please try again.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function getErrorCode(): string
    {
        return 'InternalError';
    }

    public function getHttpStatus(): int
    {
        return 500;
    }
}
