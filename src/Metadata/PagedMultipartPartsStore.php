<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional bounded ListParts query; MetadataStore::getParts() remains unchanged. */
interface PagedMultipartPartsStore
{
    /**
     * Return at most $limit parts with part_number > $afterPartNumber in ascending order.
     * The caller supplies 1..1001 rows, including one lookahead row for truncation.
     *
     * @return list<array{part_number: int, etag: string, size: int, storage_path: string, checksum_algorithm?: ?string, checksum_value?: ?string, created_at: string}>
     */
    public function listPartsPage(string $uploadId, int $afterPartNumber, int $limit): array;
}
