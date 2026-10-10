<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class RestoreAlreadyInProgressException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('Object restore is already in progress.');
    }

    public function getErrorCode(): string
    {
        return 'RestoreAlreadyInProgress';
    }

    public function getHttpStatus(): int
    {
        return 409;
    }
}
