<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * Your proposed upload exceeds the maximum allowed object size.
 */
final class EntityTooLargeException extends S3Exception
{
    public function __construct(string $message = 'Your proposed upload exceeds the maximum allowed object size.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'EntityTooLarge';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
