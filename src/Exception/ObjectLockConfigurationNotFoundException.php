<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * Object Lock configuration has not been enabled for this bucket.
 */
final class ObjectLockConfigurationNotFoundException extends S3Exception
{
    public function __construct(string $message = 'Object Lock configuration does not exist for this bucket.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'ObjectLockConfigurationNotFoundError';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
