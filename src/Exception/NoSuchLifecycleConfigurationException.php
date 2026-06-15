<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The lifecycle configuration does not exist for this bucket.
 */
final class NoSuchLifecycleConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The lifecycle configuration does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchLifecycleConfiguration';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
