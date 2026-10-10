<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;

/** A keyset marker scoped to one account and one set of ListBuckets filters. */
final class BucketContinuationToken
{
    public static function encode(string $ownerId, ?string $prefix, ?string $region, string $after): string
    {
        $payload = json_encode([
            'v' => 1,
            'scope' => self::scope($ownerId, $prefix, $region),
            'after' => $after,
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function decode(string $token, string $ownerId, ?string $prefix, ?string $region): string
    {
        if ($token === '' || strlen($token) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new InvalidArgumentException('Invalid continuation-token.');
        }

        $json = base64_decode(strtr($token, '-_', '+/'), true);
        if ($json === false) {
            throw new InvalidArgumentException('Invalid continuation-token.');
        }

        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid continuation-token.');
        }

        if (
            ! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ($payload['scope'] ?? null) !== self::scope($ownerId, $prefix, $region)
            || ! is_string($payload['after'] ?? null)
            || strlen($payload['after']) < 3
            || strlen($payload['after']) > 63
            || self::encode($ownerId, $prefix, $region, $payload['after']) !== $token
        ) {
            throw new InvalidArgumentException('Invalid continuation-token.');
        }

        return $payload['after'];
    }

    private static function scope(string $ownerId, ?string $prefix, ?string $region): string
    {
        return hash('sha256', json_encode([$ownerId, $prefix, $region], JSON_THROW_ON_ERROR));
    }
}
