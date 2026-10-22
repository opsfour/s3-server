<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Xml\XmlText;

final class ListingQuery
{
    /** @param array<string, string> $query */
    public static function limit(array $query, string $name): int
    {
        return isset($query[$name]) ? min(self::nonNegativeInteger($query[$name], $name), 1000) : 1000;
    }

    /** @param array<string, string> $query */
    public static function marker(array $query, string $name): int
    {
        return isset($query[$name]) ? self::nonNegativeInteger($query[$name], $name) : 0;
    }

    /** @param array<string, string> $query */
    public static function encodingType(array $query): ?string
    {
        $encodingType = $query['encoding-type'] ?? null;
        if ($encodingType !== null && $encodingType !== 'url') {
            throw new InvalidArgumentException('encoding-type must be url.');
        }

        return $encodingType;
    }

    /** @param array<string, string> $query */
    public static function boolean(array $query, string $name, bool $default = false): bool
    {
        if (! array_key_exists($name, $query)) {
            return $default;
        }

        return match (strtolower($query[$name])) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException("{$name} must be true or false."),
        };
    }

    /** @param array<string, string> $query */
    public static function xmlText(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;
        if ($value !== null && ! XmlText::isValid($value)) {
            throw new InvalidArgumentException("{$name} contains characters that are invalid in XML 1.0.");
        }

        return $value;
    }

    private static function nonNegativeInteger(string $value, string $name): int
    {
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException("{$name} must be a non-negative decimal integer.");
        }

        $digits = ltrim($value, '0');
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidArgumentException("{$name} exceeds the supported integer range.");
        }

        return (int) $value;
    }
}
