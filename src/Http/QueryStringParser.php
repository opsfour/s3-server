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
    /**
     * SigV4 sorts query pairs before signing, while S3 handlers consume the
     * first value. Different values for one decoded name would let the same
     * signature authorize different requests by changing only pair order.
     */
    /** @param list<string> $orderInsensitiveNames */
    public static function hasConflictingValues(string $queryString, array $orderInsensitiveNames = []): bool
    {
        if ($queryString === '') {
            return false;
        }

        $values = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            $key = rawurldecode($eqPos === false ? $pair : substr($pair, 0, $eqPos));
            $value = $eqPos === false ? '' : rawurldecode(substr($pair, $eqPos + 1));

            if (array_key_exists($key, $values)) {
                if ($values[$key] !== $value && ! in_array($key, $orderInsensitiveNames, true)) {
                    return true;
                }
            } else {
                $values[$key] = $value;
            }
        }

        return false;
    }

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
                $key = rawurldecode($pair);
                if (! array_key_exists($key, $params)) {
                    $params[$key] = '';
                }
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
