<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\ObjectInfo;

/**
 * Paginates version rows and common prefixes as one ordered S3 listing.
 */
final class DelimitedVersionListing
{
    private const int CHUNK_SIZE = 512;

    /**
     * @param callable(array<string, mixed>|null, int, ?string): list<array<string, mixed>> $fetchRows
     * @param callable(array<string, mixed>): ObjectInfo $toObjectInfo
     * @return array{versions: list<ObjectInfo>, deleteMarkers: list<ObjectInfo>, entries: list<ObjectInfo>, isTruncated: bool, nextKeyMarker: ?string, nextVersionIdMarker: ?string, commonPrefixes: list<string>}
     */
    public static function page(
        callable $fetchRows,
        callable $toObjectInfo,
        string $prefix,
        string $delimiter,
        int $maxKeys,
        ?string $keyMarker,
    ): array {
        $versions = [];
        $deleteMarkers = [];
        $entries = [];
        $commonPrefixes = [];
        $activePrefix = null;
        $lastIncludedRow = null;
        $lastIncludedPrefix = null;
        $cursor = null;
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
                $cursor = [
                    'key' => $key,
                    'isLatest' => (int) $row['is_latest'],
                    'createdAt' => (string) $row['created_at'],
                    'id' => (int) $row['id'],
                ];
                $suffix = substr($key, $prefixLength);
                $delimiterPosition = strpos($suffix, $delimiter);
                $commonPrefix = $delimiterPosition === false
                    ? null
                    : $prefix . substr($suffix, 0, $delimiterPosition + strlen($delimiter));

                if ($commonPrefix !== null && $commonPrefix === $activePrefix) {
                    continue;
                }
                $activePrefix = null;

                if ($commonPrefix !== null && $keyMarker !== null && strcmp($commonPrefix, $keyMarker) <= 0) {
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
                    $lastIncludedPrefix = $commonPrefix;
                    $lastIncludedRow = null;
                } else {
                    $info = $toObjectInfo($row);
                    $entries[] = $info;
                    if ((bool) $row['is_delete_marker']) {
                        $deleteMarkers[] = $info;
                    } else {
                        $versions[] = $info;
                    }
                    $lastIncludedPrefix = null;
                    $lastIncludedRow = $row;
                }

                $entryCount++;
            }

            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }

            if ($activePrefix !== null) {
                $afterPrefix = $activePrefix;
                $cursor = null;
            }
        }

        return [
            'versions' => $versions,
            'deleteMarkers' => $deleteMarkers,
            'entries' => $entries,
            'isTruncated' => $isTruncated,
            'nextKeyMarker' => $isTruncated ? ($lastIncludedPrefix ?? ($lastIncludedRow !== null ? (string) $lastIncludedRow['key_name'] : null)) : null,
            'nextVersionIdMarker' => $isTruncated && $lastIncludedPrefix === null && $lastIncludedRow !== null && $lastIncludedRow['version_id'] !== null
                ? (string) $lastIncludedRow['version_id']
                : null,
            'commonPrefixes' => $commonPrefixes,
        ];
    }
}
