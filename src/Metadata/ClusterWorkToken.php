<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Exact PostgreSQL ownership proof for one durable cluster work item. */
final readonly class ClusterWorkToken
{
    public const string LIFECYCLE = 'lifecycle';
    public const string NOTIFICATION = 'notification';
    public const string TIER_TRANSITION = 'tier_transition';
    public const string RESTORE = 'restore';
    public const string STORAGE_GARBAGE = 'storage_garbage';
    public const string MULTIPART_INTENT = 'multipart_intent';
    public const string OBJECT_INTENT = 'object_intent';

    public function __construct(
        public string $kind,
        public string $workId,
        public string $workerId,
        public int $generation,
        public float $heartbeatAt,
        public float $leaseExpiresAt,
        public bool $takeover = false,
    ) {
        if (! in_array($kind, self::kinds(), true)) {
            throw new \InvalidArgumentException('Cluster work token kind is invalid.');
        }
        if ($workId === '' || strlen($workId) > 512) {
            throw new \InvalidArgumentException('Cluster work token ID is invalid.');
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,191}\z/D', $workerId) !== 1) {
            throw new \InvalidArgumentException('Cluster worker identity is invalid.');
        }
        if ($generation < 1 || ! is_finite($heartbeatAt) || ! is_finite($leaseExpiresAt)
            || $heartbeatAt <= 0.0 || $leaseExpiresAt <= $heartbeatAt) {
            throw new \InvalidArgumentException('Cluster work token lease is invalid.');
        }
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return [
            self::LIFECYCLE,
            self::NOTIFICATION,
            self::TIER_TRANSITION,
            self::RESTORE,
            self::STORAGE_GARBAGE,
            self::MULTIPART_INTENT,
            self::OBJECT_INTENT,
        ];
    }
}
