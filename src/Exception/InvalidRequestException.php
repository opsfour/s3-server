<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The requested S3 operation is invalid for the resource's current state.
 */
final class InvalidRequestException extends S3Exception
{
    public function __construct(string $message = 'The request is not valid for the current resource state.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidRequest';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
