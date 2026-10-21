<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The object is locked and cannot be modified or deleted.
 */
final class ObjectLockedException extends S3Exception
{
    public function __construct(string $message = 'Access Denied')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'AccessDenied';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
