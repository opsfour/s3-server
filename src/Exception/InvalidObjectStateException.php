<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class InvalidObjectStateException extends S3Exception
{
    public function __construct(string $message = 'The operation is not valid for the current state of the object.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function getErrorCode(): string
    {
        return 'InvalidObjectState';
    }

    public function getHttpStatus(): int
    {
        return 403;
    }
}
