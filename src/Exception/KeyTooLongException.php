<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class KeyTooLongException extends S3Exception
{
    public function __construct(string $message = 'Object key must be at most 1024 bytes.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'KeyTooLongError';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
