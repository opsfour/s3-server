<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

interface ClusterEncryptionIdentity
{
    /** Stable non-secret identity proving equivalent decrypt/encrypt capability. */
    public function clusterEncryptionIdentity(): string;
}
