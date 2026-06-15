<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Dto;

/**
 * Data transfer object for ListObjectsV2 / ListObjects response data.
 */
final readonly class ListObjectsResult
{
    /**
     * @param  string  $name  The bucket name.
     * @param  string  $prefix  The prefix filter applied.
     * @param  string|null  $delimiter  The delimiter used for grouping.
     * @param  int  $maxKeys  The maximum number of keys requested.
     * @param  bool  $isTruncated  Whether the result set was truncated.
     * @param  int  $keyCount  The number of keys returned.
     * @param  list<ObjectInfo>  $objects  The list of object metadata entries.
     * @param  list<string>  $commonPrefixes  The list of common prefix entries (virtual directories).
     * @param  string|null  $startAfter  The startAfter parameter (V2 only).
     * @param  string|null  $continuationToken  The continuation token used for this request.
     * @param  string|null  $nextContinuationToken  The token for fetching the next page.
     * @param  string|null  $marker  The marker parameter (V1 only).
     * @param  string|null  $nextMarker  The marker for the next page (V1 only).
     * @param  string  $encodingType  The encoding type (url or empty).
     */
    public function __construct(
        public string $name,
        public string $prefix = '',
        public ?string $delimiter = null,
        public int $maxKeys = 1000,
        public bool $isTruncated = false,
        public int $keyCount = 0,
        public array $objects = [],
        public array $commonPrefixes = [],
        public ?string $startAfter = null,
        public ?string $continuationToken = null,
        public ?string $nextContinuationToken = null,
        public ?string $marker = null,
        public ?string $nextMarker = null,
        public string $encodingType = '',
    ) {}
}
