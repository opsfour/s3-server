<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * Your proposed upload is smaller than the minimum allowed object size.
 */
final class EntityTooSmallException extends S3Exception
{
    public function __construct(string $message = 'Your proposed upload is smaller than the minimum allowed object size.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'EntityTooSmall';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
