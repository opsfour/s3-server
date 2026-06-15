<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The server side encryption configuration was not found.
 */
final class NoSuchEncryptionConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The server side encryption configuration was not found.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'ServerSideEncryptionConfigurationNotFoundError';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
