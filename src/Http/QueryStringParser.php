<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

/**
 * Parses raw query strings into key-value pairs with proper URL decoding.
 *
 * First occurrence of a key wins (matching S3 behavior).
 */
final class QueryStringParser
{
    /** @return array<string, string> */
    public static function parse(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        $params = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');

            if ($eqPos === false) {
                $params[rawurldecode($pair)] = '';
            } else {
                $key = rawurldecode(substr($pair, 0, $eqPos));
                $value = rawurldecode(substr($pair, $eqPos + 1));
                if (! array_key_exists($key, $params)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }
}
