<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The specified method is not allowed against this resource.
 */
final class MethodNotAllowedException extends S3Exception
{
    /** @param list<string> $allowedMethods */
    public function __construct(
        string $message = 'The specified method is not allowed against this resource.',
        array $allowedMethods = [],
    ) {
        parent::__construct($message);
        if ($allowedMethods !== []) {
            $this->setExtraHeaders(['Allow' => implode(', ', $allowedMethods)]);
        }
    }

    public function getErrorCode(): string
    {
        return 'MethodNotAllowed';
    }

    public function getHttpStatus(): int
    {
        return 405;
    }
}
