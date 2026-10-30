<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Sync\LocalMutex;
use OpsFour\S3Server\S3ServerConfig;

/** Owns one DB-time membership row and performs uncached live-role checks. */
final class ClusterRoleController
{
    private bool $registered = false;

    private bool $draining = false;

    private bool $drainPublished = false;

    private ?float $lastSuccessfulHeartbeatAt = null;

    private readonly LocalMutex $membershipMutex;

    public function __construct(
        private readonly ClusterMembershipStore $store,
        private readonly S3ServerConfig $config,
        private readonly float $ttlSeconds = 15.0,
    ) {
        if (! $config->clusterEnabled() || $config->clusterId === null || $config->clusterNodeId === null
            || $config->clusterIncarnationId === null) {
            throw new \LogicException('Cluster membership requires enabled cluster configuration.');
        }
        if (! is_finite($ttlSeconds) || $ttlSeconds <= 0.0) {
            throw new \InvalidArgumentException('Cluster membership TTL must be finite and positive.');
        }
        $this->membershipMutex = new LocalMutex();
    }

    public function registerReady(): void
    {
        $lock = $this->membershipMutex->acquire();
        try {
            if ($this->draining) {
                throw new \RuntimeException('Cluster membership cannot become ready while the node is draining.');
            }
            if (! $this->publishReadyLocked()) {
                throw new \RuntimeException('Cluster API startup requires live reconcile role coverage.');
            }
        } finally {
            $lock->release();
        }
    }

    /** Re-register after an admission recovery, or renew the current row. */
    public function maintainReady(): bool
    {
        $lock = $this->membershipMutex->acquire();
        try {
            if ($this->draining) {
                return $this->maintainNotReadyLocked();
            }
            return $this->registered ? $this->renewLocked() : $this->publishReadyLocked();
        } catch (\Throwable) {
            $this->withdrawLocked();

            return false;
        } finally {
            $lock->release();
        }
    }

    public function assertBoundToConfig(S3ServerConfig $config): void
    {
        if (! $config->clusterEnabled()
            || $config->clusterId === null
            || $config->clusterNodeId === null
            || $config->clusterIncarnationId === null
            || ! hash_equals($this->clusterId(), $config->clusterId)
            || ! hash_equals($this->nodeId(), $config->clusterNodeId)
            || ! hash_equals($this->incarnationId(), $config->clusterIncarnationId)
            || $this->config->clusterRoles !== $config->clusterRoles
            || $this->config->clusterRequiredRoles !== $config->clusterRequiredRoles) {
            throw new \LogicException('Cluster role controller is not bound to this server configuration.');
        }
    }

    public function assertStore(ClusterMembershipStore $store): void
    {
        if ($this->store !== $store) {
            throw new \LogicException('Cluster role controller must use this server\'s exact PostgreSQL membership store.');
        }
    }

    public function hasAssignedRole(string $role): bool
    {
        return $this->config->hasClusterRole($role);
    }

    public function canClaim(string $role): bool
    {
        if (! $this->config->hasClusterRole($role)) {
            return false;
        }

        $lock = $this->membershipMutex->acquire();
        try {
            if ($this->draining) {
                return false;
            }

            return $this->renewLocked();
        } catch (\Throwable) {
            $this->registered = false;

            return false;
        } finally {
            $lock->release();
        }
    }

    public function heartbeat(): bool
    {
        $lock = $this->membershipMutex->acquire();
        try {
            if ($this->draining) {
                return $this->maintainNotReadyLocked();
            }
            return $this->renewLocked();
        } catch (\Throwable) {
            $this->registered = false;

            return false;
        } finally {
            $lock->release();
        }
    }

    public function hasLiveRole(string $role): bool
    {
        $lock = $this->membershipMutex->acquire();
        try {
            if (! $this->registered || ! $this->isCurrentMemberLive()) {
                return false;
            }

            return in_array($role, $this->store->liveClusterRoles($this->clusterId()), true);
        } catch (\Throwable) {
            return false;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function diagnostics(): array
    {
        $lock = $this->membershipMutex->acquire();
        try {
            if ($this->store instanceof ClusterOperationalSnapshotStore) {
                $snapshot = $this->store->clusterMembershipSnapshot(
                    $this->clusterId(),
                    $this->nodeId(),
                    $this->incarnationId(),
                );
                $live = [];
                foreach ($snapshot->roleCounts as $role => $counts) {
                    if ($counts['ready'] > 0) {
                        $live[] = $role;
                    }
                }
                $missing = array_values(array_diff($this->config->clusterRequiredRoles, $live));
                $this->lastSuccessfulHeartbeatAt = $snapshot->lastHeartbeatAt;

                return [
                    'membership' => $snapshot->membership,
                    'last_heartbeat_at' => $snapshot->lastHeartbeatAt,
                    'heartbeat_age_seconds' => $snapshot->heartbeatAgeSeconds,
                    'required_roles' => $this->config->clusterRequiredRoles,
                    'live_roles' => $live,
                    'missing_roles' => $missing,
                    'role_counts' => $snapshot->roleCounts,
                    'observed_at' => $snapshot->observedAt,
                    'collection' => 'current',
                ];
            }
            if (! $this->registered || ! $this->isCurrentMemberLive()) {
                throw new \RuntimeException('Membership is not current.');
            }
            $live = $this->store->liveClusterRoles($this->clusterId());
            $missing = array_values(array_diff($this->config->clusterRequiredRoles, $live));

            return [
                'membership' => 'current',
                'required_roles' => $this->config->clusterRequiredRoles,
                'live_roles' => $live,
                'missing_roles' => $missing,
                'collection' => 'current',
            ];
        } catch (\Throwable) {
            return [
                'membership' => 'unavailable',
                'last_heartbeat_at' => $this->lastSuccessfulHeartbeatAt,
                'heartbeat_age_seconds' => null,
                'required_roles' => $this->config->clusterRequiredRoles,
                'live_roles' => [],
                'missing_roles' => $this->config->clusterRequiredRoles,
                'collection' => 'unavailable',
            ];
        } finally {
            $lock->release();
        }
    }

    public function operationalSnapshot(): ClusterOperationalSnapshot
    {
        if (! $this->store instanceof ClusterOperationalSnapshotStore) {
            throw new \LogicException('Cluster operational snapshots require PostgreSQL telemetry.');
        }

        return $this->store->clusterOperationalSnapshot(
            $this->clusterId(),
            $this->nodeId(),
            $this->incarnationId(),
        );
    }

    /** @return array{cluster_id: string, node_id: string, incarnation_id: string, roles: list<string>} */
    public function healthIdentity(): array
    {
        return [
            'cluster_id' => $this->clusterId(),
            'node_id' => $this->nodeId(),
            'incarnation_id' => $this->incarnationId(),
            'roles' => $this->config->clusterRoles,
        ];
    }

    /** @return list<string> */
    public function requiredRoles(): array
    {
        return $this->config->clusterRequiredRoles;
    }

    public function locallyReady(): bool
    {
        return $this->registered && ! $this->draining;
    }

    public function nodeState(): string
    {
        if ($this->draining) {
            return 'draining';
        }

        return $this->registered ? 'ready' : 'stopped';
    }

    public function withdraw(): void
    {
        $lock = $this->membershipMutex->acquire();
        try {
            $this->withdrawLocked();
        } finally {
            $lock->release();
        }
    }

    /** Publish ready=false while retaining the row until final withdrawal. */
    public function beginDrain(): void
    {
        $lock = $this->membershipMutex->acquire();
        try {
            $this->draining = true;
            if ($this->drainPublished) {
                return;
            }
            if (! $this->registered) {
                $this->drainPublished = true;
                return;
            }
            try {
                $published = $this->store->heartbeatClusterMember(
                    $this->clusterId(),
                    $this->nodeId(),
                    $this->incarnationId(),
                    false,
                    true,
                    $this->ttlSeconds,
                );
            } catch (\Throwable) {
                $this->registered = false;

                return;
            }
            if (! $published) {
                $this->registered = false;
            }
            $this->drainPublished = true;
        } finally {
            $lock->release();
        }
    }

    private function publishReadyLocked(): bool
    {
        $this->store->registerClusterMember(
            $this->clusterId(),
            $this->nodeId(),
            $this->incarnationId(),
            $this->config->clusterRoles,
            true,
            false,
            $this->ttlSeconds,
        );
        $this->registered = true;

        if ($this->config->hasClusterRole(ClusterRoleSet::API)
            && ! in_array(ClusterRoleSet::RECONCILE, $this->store->liveClusterRoles($this->clusterId()), true)) {
            $this->withdrawLocked();

            return false;
        }

        return true;
    }

    private function renewLocked(): bool
    {
        if (! $this->registered) {
            return false;
        }
        $renewed = $this->store->heartbeatClusterMember(
            $this->clusterId(),
            $this->nodeId(),
            $this->incarnationId(),
            true,
            false,
            $this->ttlSeconds,
        );
        if (! $renewed) {
            $this->registered = false;
        }

        return $renewed;
    }

    private function maintainNotReadyLocked(): bool
    {
        if (! $this->registered) {
            return false;
        }
        $renewed = $this->store->heartbeatClusterMember(
            $this->clusterId(),
            $this->nodeId(),
            $this->incarnationId(),
            false,
            true,
            $this->ttlSeconds,
        );
        if (! $renewed) {
            $this->registered = false;
        }

        return $renewed;
    }

    private function isCurrentMemberLive(): bool
    {
        return $this->store->isClusterMemberLive($this->clusterId(), $this->nodeId(), $this->incarnationId());
    }

    private function withdrawLocked(): void
    {
        $this->registered = false;
        try {
            $this->store->withdrawClusterMember($this->clusterId(), $this->nodeId(), $this->incarnationId());
        } catch (\Throwable) {
            // Expiry remains authoritative when withdrawal cannot reach PostgreSQL.
        }
    }

    private function clusterId(): string
    {
        return $this->config->clusterId ?? throw new \LogicException('Cluster ID is missing.');
    }

    private function nodeId(): string
    {
        return $this->config->clusterNodeId ?? throw new \LogicException('Cluster node ID is missing.');
    }

    private function incarnationId(): string
    {
        return $this->config->clusterIncarnationId ?? throw new \LogicException('Cluster incarnation ID is missing.');
    }
}
