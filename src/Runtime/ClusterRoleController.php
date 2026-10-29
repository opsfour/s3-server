<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Sync\LocalMutex;
use OpsFour\S3Server\S3ServerConfig;

/** Owns one DB-time membership row and performs uncached live-role checks. */
final class ClusterRoleController
{
    private bool $registered = false;

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

    /** @return array{membership: string, required_roles: list<string>, live_roles: list<string>, missing_roles: list<string>} */
    public function diagnostics(): array
    {
        $lock = $this->membershipMutex->acquire();
        try {
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
            ];
        } catch (\Throwable) {
            return [
                'membership' => 'unavailable',
                'required_roles' => $this->config->clusterRequiredRoles,
                'live_roles' => [],
                'missing_roles' => $this->config->clusterRequiredRoles,
            ];
        } finally {
            $lock->release();
        }
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

    private function publishReadyLocked(): bool
    {
        $this->store->registerClusterMember(
            $this->clusterId(),
            $this->nodeId(),
            $this->incarnationId(),
            $this->config->clusterRoles,
            true,
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
