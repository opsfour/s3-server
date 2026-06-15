<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified multipart upload does not exist.
 */
final class NoSuchUploadException extends S3Exception
{
    public function __construct(string $message = 'The specified upload does not exist.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchUpload';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
