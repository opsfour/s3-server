<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * At least one of the preconditions you specified did not hold.
 */
final class PreconditionFailedException extends S3Exception
{
    public function __construct(string $message = 'At least one of the preconditions you specified did not hold.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'PreconditionFailed';
    }

    public function getHttpStatus(): int
    {
        return 412;
    }
}
