<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/** The server cannot accept more temporary upload data right now. */
final class SlowDownException extends S3Exception
{
    public function __construct(string $message = 'Please reduce your request rate.')
    {
        parent::__construct($message);
        $this->setExtraHeaders(['Retry-After' => '1']);
    }

    public function getErrorCode(): string
    {
        return 'SlowDown';
    }

    public function getHttpStatus(): int
    {
        return 503;
    }
}
