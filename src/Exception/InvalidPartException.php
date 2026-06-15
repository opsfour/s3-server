<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * One or more of the specified parts could not be found.
 */
final class InvalidPartException extends S3Exception
{
    public function __construct(string $message = 'One or more of the specified parts could not be found. The part might not have been uploaded, or the specified entity tag might not have matched the part\'s entity tag.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidPart';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
