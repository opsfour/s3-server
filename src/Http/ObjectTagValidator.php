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
            if ($tag['key'] === '' || strlen($tag['key']) > 128 || strlen($tag['value']) > 256) {
                throw new InvalidArgumentException('Tag key or value exceeds the S3 limits.');
            }
            if (isset($seen[$tag['key']])) {
                throw new InvalidArgumentException("Duplicate tag key: {$tag['key']}");
            }
            $seen[$tag['key']] = true;
        }

        return $tags;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    public static function parseHeader(string $header): array
    {
        $tags = [];
        foreach (explode('&', $header) as $pair) {
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
