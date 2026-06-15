<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

final readonly class ConfigQuotaProvider implements QuotaProvider
{
    public function __construct(
        private QuotaConfig $quota,
    ) {}

    public function quotaForOwner(string $ownerId): QuotaConfig
    {
        return $this->quota;
    }

    public function hasQuotaForOwner(string $ownerId): bool
    {
        return $this->quota->enabled();
    }
}
