<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/**
 * Paginates in-progress uploads and common prefixes as one ordered S3 listing.
 */
final class DelimitedMultipartListing
{
    private const int CHUNK_SIZE = 512;

    /**
     * @param callable(array{key: string, createdAt: string, uploadId: string}|null, int, ?string): list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata?: string, created_at: string, _listing_created_at?: string}> $fetchRows
     * @return array{uploads: list<array{upload_id: string, bucket: string, key_name: string, owner_id: string, content_type: string, user_metadata?: string, created_at: string}>, isTruncated: bool, nextKeyMarker: ?string, nextUploadIdMarker: ?string, commonPrefixes: list<string>}
     */
    public static function page(callable $fetchRows, string $prefix, string $delimiter, int $maxUploads, ?string $keyMarker): array
    {
        $uploads = [];
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
                $key = $row['key_name'];
                $cursor = [
                    'key' => $key,
                    'createdAt' => $row['_listing_created_at'] ?? $row['created_at'],
                    'uploadId' => $row['upload_id'],
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

                if ($entryCount >= $maxUploads) {
                    $isTruncated = true;
                    break 2;
                }

                if ($commonPrefix !== null) {
                    $commonPrefixes[] = $commonPrefix;
                    $activePrefix = $commonPrefix;
                    $lastIncludedPrefix = $commonPrefix;
                    $lastIncludedRow = null;
                } else {
                    unset($row['_listing_created_at']);
                    $uploads[] = $row;
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
            'uploads' => $uploads,
            'isTruncated' => $isTruncated,
            'nextKeyMarker' => $isTruncated ? ($lastIncludedPrefix ?? ($lastIncludedRow['key_name'] ?? null)) : null,
            'nextUploadIdMarker' => $isTruncated && $lastIncludedPrefix === null && $lastIncludedRow !== null ? $lastIncludedRow['upload_id'] : null,
            'commonPrefixes' => $commonPrefixes,
        ];
    }
}
