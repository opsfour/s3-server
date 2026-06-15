<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The resource has not been modified since the last request.
 */
final class NotModifiedException extends S3Exception
{
    public function __construct(string $message = 'Not Modified')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NotModified';
    }

    public function getHttpStatus(): int
    {
        return 304;
    }
}
