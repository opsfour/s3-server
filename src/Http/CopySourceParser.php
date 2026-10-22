<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Exception\InvalidArgumentException;

/** Parses the URL-encoded x-amz-copy-source header for both copy operations. */
final class CopySourceParser
{
    /** @return array{0: string, 1: string, 2: string|null} */
    public static function parse(string $copySource): array
    {
        $versionId = null;
        $queryStart = strpos($copySource, '?');
        if ($queryStart !== false) {
            $versionId = QueryStringParser::parse(substr($copySource, $queryStart + 1))['versionId'] ?? null;
            $copySource = substr($copySource, 0, $queryStart);
        }

        // Decode before removing the leading separator so an encoded '/' has
        // the same meaning as a literal '/' in either copy operation.
        $path = ltrim(rawurldecode($copySource), '/');
        $separator = strpos($path, '/');
        if ($separator === false) {
            throw new InvalidArgumentException('Invalid x-amz-copy-source: must be in the format /bucket/key.');
        }

        $bucket = substr($path, 0, $separator);
        $key = substr($path, $separator + 1);
        if ($bucket === '' || $key === '') {
            throw new InvalidArgumentException('Invalid x-amz-copy-source: bucket and key must not be empty.');
        }
        ObjectKeyValidator::assertValid($key);

        return [$bucket, $key, $versionId];
    }
}
