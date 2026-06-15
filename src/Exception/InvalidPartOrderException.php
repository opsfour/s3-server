<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The list of parts was not in ascending order.
 */
final class InvalidPartOrderException extends S3Exception
{
    public function __construct(string $message = 'The list of parts was not in ascending order. The parts list must be specified in order by part number.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'InvalidPartOrder';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
