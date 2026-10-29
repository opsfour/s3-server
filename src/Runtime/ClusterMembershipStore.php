<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

interface ClusterMembershipStore
{
    /** @param list<string> $roles */
    public function registerClusterMember(
        string $clusterId,
        string $nodeId,
        string $incarnationId,
        array $roles,
        bool $ready,
        float $ttlSeconds,
    ): void;

    public function heartbeatClusterMember(
        string $clusterId,
        string $nodeId,
        string $incarnationId,
        bool $ready,
        float $ttlSeconds,
    ): bool;

    public function withdrawClusterMember(string $clusterId, string $nodeId, string $incarnationId): void;

    public function isClusterMemberLive(string $clusterId, string $nodeId, string $incarnationId): bool;

    /** @return list<string> */
    public function liveClusterRoles(string $clusterId): array;
}
