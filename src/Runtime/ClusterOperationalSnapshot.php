<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** One database-time observation shared by cluster health and metrics. */
final readonly class ClusterOperationalSnapshot
{
    /**
     * @param array<string, array{ready: int, draining: int}> $roleCounts
     * @param array<string, ClusterWorkSnapshot> $work
     */
    public function __construct(
        public float $observedAt,
        public string $membership,
        public ?float $lastHeartbeatAt,
        public ?float $heartbeatAgeSeconds,
        public array $roleCounts,
        public array $work,
    ) {
        if (! is_finite($observedAt) || $observedAt <= 0.0) {
            throw new \InvalidArgumentException('Cluster observation time is invalid.');
        }
        if (! in_array($membership, ['current', 'stale'], true)) {
            throw new \InvalidArgumentException('Cluster membership state is invalid.');
        }
        if (($lastHeartbeatAt === null) !== ($heartbeatAgeSeconds === null)
            || ($lastHeartbeatAt !== null && (! is_finite($lastHeartbeatAt) || $lastHeartbeatAt <= 0.0))
            || ($heartbeatAgeSeconds !== null && (! is_finite($heartbeatAgeSeconds) || $heartbeatAgeSeconds < 0.0))) {
            throw new \InvalidArgumentException('Cluster membership heartbeat is invalid.');
        }
        if (array_keys($roleCounts) !== ClusterRoleSet::ALL) {
            throw new \InvalidArgumentException('Cluster role counts must use the canonical role vocabulary.');
        }
        foreach ($roleCounts as $counts) {
            if (! is_array($counts)
                || count($counts) !== 2
                || ! array_key_exists('ready', $counts)
                || ! array_key_exists('draining', $counts)
                || ! is_int($counts['ready'])
                || ! is_int($counts['draining'])
                || $counts['ready'] < 0
                || $counts['draining'] < 0) {
                throw new \InvalidArgumentException('Cluster role counts must contain exact non-negative integer ready and draining fields.');
            }
        }
        if (array_keys($work) !== ClusterWorkSnapshot::ALL) {
            throw new \InvalidArgumentException('Cluster work snapshot must contain every durable-work class.');
        }
        foreach ($work as $workClass => $snapshot) {
            if ($snapshot->workClass !== $workClass) {
                throw new \InvalidArgumentException('Cluster work snapshot keys must match their durable-work class.');
            }
        }
    }
}
