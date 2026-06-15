<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The CORS configuration does not exist for this bucket.
 */
final class NoSuchCORSConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The CORS configuration does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchCORSConfiguration';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
