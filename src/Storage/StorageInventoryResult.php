<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

final readonly class StorageInventoryResult
{
    /** @param list<array{storage_tier: string, path_sha256: string, path?: string, size_bytes: ?int, format: ?string}> $candidates */
    public function __construct(
        public int $physicalFiles,
        public int $metadataReferences,
        public int $orphanCandidates,
        public array $candidates,
        /** @var array{storage_tier: string, path_sha256: string}|null */
        public ?array $nextCandidate = null,
    ) {}
}
