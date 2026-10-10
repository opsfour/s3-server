<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Read-only, bounded operator inventory of retained encrypted-part intents. */
interface MultipartPartWriteIntentInventoryStore
{
    /**
     * @return list<array{id: int, bucket: string, storage_tier: string, path_sha256: string, status: string, attempts: int, created_at: string, next_attempt_at: float}>
     */
    public function listMultipartPartWriteIntents(string $ownerId, int $afterId, int $limit): array;
}
