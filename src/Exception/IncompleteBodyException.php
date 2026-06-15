<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class IncompleteBodyException extends S3Exception
{
    public function __construct(string $message = 'The request body terminated unexpectedly.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'IncompleteBody';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
