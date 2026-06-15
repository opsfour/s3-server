<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * A configured server-side quota would be exceeded by the request.
 */
final class QuotaExceededException extends S3Exception
{
    public function __construct(string $message = 'The request would exceed a configured quota.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'QuotaExceeded';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
