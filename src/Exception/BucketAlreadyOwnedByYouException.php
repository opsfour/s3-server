<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class BucketAlreadyOwnedByYouException extends S3Exception
{
    public function __construct(string $message = 'Your previous request to create the named bucket succeeded and you already own it.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'BucketAlreadyOwnedByYou';
    }

    public function getHttpStatus(): int
    {
        return 409;
    }
}
