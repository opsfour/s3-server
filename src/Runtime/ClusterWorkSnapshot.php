<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** One identifier-free sample for a fixed durable-work class. */
final readonly class ClusterWorkSnapshot
{
    /** @var list<string> */
    public const array ALL = [
        'notification',
        'transition',
        'restore',
        'garbage',
        'multipart_intent',
        'object_intent',
        'lifecycle',
    ];

    public function __construct(
        public string $workClass,
        public ?int $pending,
        public ?float $oldestEligibleAgeSeconds,
        public ?int $activeLeases,
        public ?int $expiredLeases,
        public ?int $retryPressure,
        public int $successfulTakeovers,
        public int $failedTakeovers,
        public int $staleAcknowledgements,
        public int $highestGeneration,
    ) {
        if (! in_array($workClass, self::ALL, true)) {
            throw new \InvalidArgumentException('Cluster work snapshot class is invalid.');
        }
        foreach ([$pending, $activeLeases, $expiredLeases, $retryPressure, $successfulTakeovers, $failedTakeovers, $staleAcknowledgements, $highestGeneration] as $value) {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('Cluster work snapshot counters must be non-negative.');
            }
        }
        if ($oldestEligibleAgeSeconds !== null && (! is_finite($oldestEligibleAgeSeconds) || $oldestEligibleAgeSeconds < 0.0)) {
            throw new \InvalidArgumentException('Cluster work snapshot age must be finite and non-negative.');
        }
    }
}
