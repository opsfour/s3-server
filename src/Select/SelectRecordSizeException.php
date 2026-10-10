<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/** A Select input or result record exceeds the supported byte limit. */
final class SelectRecordSizeException extends \RuntimeException
{
    public const int MAX_RECORD_BYTES = 1_048_576;

    public static function assertLength(int $bytes): void
    {
        if ($bytes > self::MAX_RECORD_BYTES) {
            throw new self('S3 Select record exceeds the 1 MiB byte limit.');
        }
    }
}
