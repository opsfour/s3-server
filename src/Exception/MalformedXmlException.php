<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The XML you provided was not well-formed or did not validate.
 */
final class MalformedXmlException extends S3Exception
{
    public function __construct(string $message = 'The XML you provided was not well-formed or did not validate against our published schema.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'MalformedXML';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
