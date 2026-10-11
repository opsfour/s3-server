<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;

final class ObjectContinuationToken
{
    public static function decode(string $token): string
    {
        $key = base64_decode($token, true);
        if ($key === false
            || $key === ''
            || strlen($key) > 1024
            || preg_match('//u', $key) !== 1
            || base64_encode($key) !== $token) {
            throw new InvalidArgumentException('Invalid continuation-token.');
        }

        return $key;
    }
}
