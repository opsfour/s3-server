<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * Server access logging cannot target a bucket in another region.
 */
final class CrossLocationLoggingProhibitedException extends S3Exception
{
    public function __construct(string $message = 'Cross-location logging not allowed. Buckets in one geographic location cannot log information to a bucket in another location.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'CrossLocationLoggingProhibited';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
