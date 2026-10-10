<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/** An S3 Select input or result record is too large. */
final class OverMaxRecordSizeException extends S3Exception
{
    public function __construct()
    {
        parent::__construct('The length of a record in the input or result exceeds the supported limit.');
    }

    public function getErrorCode(): string
    {
        return 'OverMaxRecordSize';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
