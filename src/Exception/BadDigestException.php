<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * The Content-MD5 or checksum you specified did not match what we received.
 */
final class BadDigestException extends S3Exception
{
    public function __construct(string $message = 'The Content-MD5 or checksum value you specified did not match what we received.')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return 'BadDigest';
    }

    public function getHttpStatus(): int
    {
        return 400;
    }
}
