<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** One uncached database-time membership observation for health. */
final readonly class ClusterMembershipSnapshot
{
    /** @param array<string, array{ready: int, draining: int}> $roleCounts */
    public function __construct(
        public float $observedAt,
        public string $membership,
        public ?float $lastHeartbeatAt,
        public ?float $heartbeatAgeSeconds,
        public array $roleCounts,
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
    }
}
