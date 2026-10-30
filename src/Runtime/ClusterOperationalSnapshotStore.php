<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** PostgreSQL-only, uncached operational state used by cluster health and metrics. */
interface ClusterOperationalSnapshotStore
{
    public function clusterMembershipSnapshot(
        string $clusterId,
        string $nodeId,
        string $incarnationId,
    ): ClusterMembershipSnapshot;

    public function clusterOperationalSnapshot(
        string $clusterId,
        string $nodeId,
        string $incarnationId,
    ): ClusterOperationalSnapshot;
}
