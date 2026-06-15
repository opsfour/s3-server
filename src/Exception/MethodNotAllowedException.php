<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified method is not allowed against this resource.
 */
final class MethodNotAllowedException extends S3Exception
{
    public function __construct(string $message = 'The specified method is not allowed against this resource.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'MethodNotAllowed';
    }

    public function getHttpStatus(): int
    {
        return 405;
    }
}
