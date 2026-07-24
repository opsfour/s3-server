<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The supplied IAM policy document is structurally invalid.
 */
final class MalformedPolicyException extends S3Exception
{
    public function __construct(string $message = 'Policy has invalid resource.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'MalformedPolicy';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
