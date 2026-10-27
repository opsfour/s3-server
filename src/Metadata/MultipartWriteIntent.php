<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Immutable record of every path one multipart completion may write. */
final readonly class MultipartWriteIntent
{
    public function __construct(
        public string $attemptId,
        public string $uploadId,
        public string $bucket,
        public string $bucketGeneration,
        public string $ownerId,
        public string $storageTier,
        public string $assemblyPath,
        public string $finalPath,
        public string $partDigest,
        public ?string $sseMode,
        public float $leaseUntil,
        public float $nextAttemptAt,
        public string $state = 'active',
        public int $claimGeneration = 0,
        public ?string $dispatchId = null,
    ) {}

    /**
     * @param list<array{partNumber: int, etag: string, size: int, storagePath: string, checksumAlgorithm?: ?string, checksumValue?: ?string}> $parts
     */
    public static function digestParts(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
