<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** One physical write attempt. Sealed rows permanently reserve their physical path. */
final readonly class ObjectWriteIntent
{
    /** Existing rows cannot prove that no worker was already dispatched. */
    public const string LEGACY_UNKNOWN_DISPATCH = '00000000000000000000000000000000';

    public function __construct(
        public string $attemptId,
        public string $bucket,
        public string $bucketGeneration,
        public string $ownerId,
        public string $storageTier,
        public string $storagePath,
        public string $purpose,
        public string $state = 'active',
        public float $leaseUntil = 0.0,
        public int $claimGeneration = 0,
        public ?string $dispatchId = null,
        public ?int $expectedSize = null,
        public ?string $expectedSha256 = null,
        public bool $sealReserved = false,
    ) {}
}
