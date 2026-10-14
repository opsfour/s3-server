<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The configured server access logging target bucket is not valid.
 */
final class InvalidTargetBucketForLoggingException extends S3Exception
{
    public function __construct(string $message = 'The target bucket for logging does not exist, is not owned by you, or does not have the appropriate grants for the LogDelivery group.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidTargetBucketForLogging';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
