<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified bucket does not have a website configuration.
 */
final class NoSuchWebsiteConfigurationException extends S3Exception
{
    public function __construct(string $message = 'The specified bucket does not have a website configuration.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'NoSuchWebsiteConfiguration';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
