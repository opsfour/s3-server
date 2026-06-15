<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

use OpsFour\S3Server\Metadata\MetadataStore;

final readonly class MetadataQuotaProvider implements QuotaProvider
{
    public function __construct(
        private MetadataStore $metadata,
        private QuotaProvider $fallback,
    ) {}

    public function quotaForOwner(string $ownerId): QuotaConfig
    {
        return $this->metadata->getAccountQuota($ownerId)
            ?? $this->fallback->quotaForOwner($ownerId);
    }

    public function hasQuotaForOwner(string $ownerId): bool
    {
        return $this->metadata->getAccountQuota($ownerId) !== null
            || $this->fallback->hasQuotaForOwner($ownerId);
    }
}
