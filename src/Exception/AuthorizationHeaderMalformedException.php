<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The authorization header is malformed.
 */
final class AuthorizationHeaderMalformedException extends S3Exception
{
    public function __construct(string $message = 'The authorization header is malformed.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'AuthorizationHeaderMalformed';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
