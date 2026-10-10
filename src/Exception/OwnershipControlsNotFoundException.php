<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

final class OwnershipControlsNotFoundException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The bucket ownership controls were not found.');
    }

    public function getErrorCode(): string
    {
        return 'OwnershipControlsNotFoundError';
    }

    public function getHttpStatus(): int
    {
        return 404;
    }
}
