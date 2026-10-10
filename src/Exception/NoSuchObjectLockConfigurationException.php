<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The object version has no retention or legal-hold configuration.
 */
final class NoSuchObjectLockConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The specified object does not have an Object Lock configuration.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchObjectLockConfiguration';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
