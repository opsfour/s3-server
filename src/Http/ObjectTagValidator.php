<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;

final class ObjectTagValidator
{
    /**
     * @param list<array{key: string, value: string}> $tags
     * @return list<array{key: string, value: string}>
     */
    public static function validate(array $tags, int $maximum): array
    {
        if (count($tags) > $maximum) {
            throw new InvalidArgumentException("Tag count cannot be greater than {$maximum}.");
        }

        $seen = [];
        foreach ($tags as $tag) {
            if (preg_match('//u', $tag['key']) !== 1 || preg_match('//u', $tag['value']) !== 1) {
                throw new InvalidArgumentException('Tag keys and values must be valid UTF-8.');
            }
            // S3 counts tag limits in UTF-16 character positions. A BMP
            // character occupies one position even when UTF-8 uses multiple
            // bytes; a supplementary character occupies two positions.
            if ($tag['key'] === '' || self::utf16Length($tag['key']) > 128 || self::utf16Length($tag['value']) > 256) {
                throw new InvalidArgumentException('Tag key or value exceeds the S3 limits.');
            }
            if (isset($seen[$tag['key']])) {
                throw new InvalidArgumentException("Duplicate tag key: {$tag['key']}");
            }
            $seen[$tag['key']] = true;
        }

        return $tags;
    }

    private static function utf16Length(string $value): int
    {
        // validate() already rejected malformed UTF-8. Count code points,
        // then add one position for each supplementary code point.
        $characters = preg_match_all('/./us', $value);
        $supplementary = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($characters === false || $supplementary === false) {
            throw new InvalidArgumentException('Tag keys and values must be valid UTF-8.');
        }

        return $characters + $supplementary;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    public static function parseHeader(string $header): array
    {
        $tags = [];
        foreach (explode('&', $header) as $pair) {
            if (! str_contains($pair, '=')) {
                throw new InvalidArgumentException('Tagging header must contain key=value pairs.');
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $key) === 1 || preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
                throw new InvalidArgumentException('Tagging header contains invalid percent encoding.');
            }
            $tags[] = [
                'key' => urldecode($key),
                'value' => urldecode($value),
            ];
        }

        return self::validate($tags, 10);
    }
}
