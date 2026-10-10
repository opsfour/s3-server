<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;

/**
 * Paginates objects and common prefixes as one ordered S3 listing.
 */
final class DelimitedObjectListing
{
    private const int CHUNK_SIZE = 512;

    /**
     * @param callable(?string, int, ?string): list<array<string, mixed>> $fetchRows
     * @param callable(array<string, mixed>): ObjectInfo $toObjectInfo
     */
    public static function page(
        callable $fetchRows,
        callable $toObjectInfo,
        string $bucket,
        string $prefix,
        string $delimiter,
        int $maxKeys,
        ?string $startAfter,
        ?string $continuationToken,
        ?string $effectiveStartAfter,
    ): ListObjectsResult {
        $objects = [];
        $commonPrefixes = [];
        $activePrefix = null;
        $lastIncludedEntry = null;
        $cursor = $effectiveStartAfter;
        $afterPrefix = null;
        $entryCount = 0;
        $isTruncated = false;
        $prefixLength = strlen($prefix);

        while (true) {
            $rows = $fetchRows($cursor, self::CHUNK_SIZE, $afterPrefix);
            $afterPrefix = null;
            $activePrefix = null;
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $key = (string) $row['key_name'];
                $cursor = $key;
                $suffix = substr($key, $prefixLength);
                $delimiterPosition = strpos($suffix, $delimiter);
                $commonPrefix = $delimiterPosition === false
                    ? null
                    : $prefix . substr($suffix, 0, $delimiterPosition + strlen($delimiter));

                if ($commonPrefix !== null && $commonPrefix === $activePrefix) {
                    continue;
                }
                $activePrefix = null;

                // S3 omits a CommonPrefix that does not sort after StartAfter.
                if ($commonPrefix !== null && $effectiveStartAfter !== null && strcmp($commonPrefix, $effectiveStartAfter) <= 0) {
                    $activePrefix = $commonPrefix;
                    continue;
                }

                if ($entryCount >= $maxKeys) {
                    $isTruncated = true;
                    break 2;
                }

                if ($commonPrefix !== null) {
                    $commonPrefixes[] = $commonPrefix;
                    $activePrefix = $commonPrefix;
                    $lastIncludedEntry = $commonPrefix;
                } else {
                    $objects[] = $toObjectInfo($row);
                    $lastIncludedEntry = $key;
                }

                $entryCount++;
            }

            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }

            if ($activePrefix !== null) {
                // The chunk ended inside one CommonPrefix. Ask the backend to
                // seek directly to the first key outside its range.
                $afterPrefix = $activePrefix;
                $cursor = null;
            }
        }

        return new ListObjectsResult(
            name: $bucket,
            prefix: $prefix,
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            isTruncated: $isTruncated,
            keyCount: $entryCount,
            objects: $objects,
            commonPrefixes: $commonPrefixes,
            startAfter: $startAfter,
            continuationToken: $continuationToken,
            nextContinuationToken: $isTruncated && $lastIncludedEntry !== null ? base64_encode($lastIncludedEntry) : null,
        );
    }
}
