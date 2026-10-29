<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Explicit qualification capability; implementation class alone is never admission evidence. */
interface ClusterSafeStorageBackend
{
    /** Stable, non-secret provider, namespace, and fence identity. */
    public function clusterStorageIdentity(): string;
}
