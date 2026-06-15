<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The request signature we calculated does not match the signature you provided.
 */
final class SignatureDoesNotMatchException extends S3Exception
{
    public function __construct(string $message = 'The request signature we calculated does not match the signature you provided.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'SignatureDoesNotMatch';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
