<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The requested functionality is not implemented.
 */
final class NotImplementedException extends S3Exception
{
    public function __construct(string $message = 'A header you provided implies functionality that is not implemented.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NotImplemented';
    }

    public function getHttpStatus(): int
    {
        return 501;
    }
}
