<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * A conflicting conditional operation is currently in progress.
 */
final class OperationAbortedException extends S3Exception
{
    public function __construct(string $message = 'A conflicting conditional operation is currently in progress against this resource. Please try again.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'OperationAborted';
    }

    public function getHttpStatus(): int
    {
        return 409;
    }
}
