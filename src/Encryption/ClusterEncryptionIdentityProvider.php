<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

interface ClusterEncryptionIdentityProvider
{
    public function clusterEncryptionProviderIdentity(): string;
}
