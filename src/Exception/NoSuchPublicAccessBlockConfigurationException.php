<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class NoSuchPublicAccessBlockConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The public access block configuration was not found.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchPublicAccessBlockConfiguration';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
