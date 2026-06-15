<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

interface QuotaProvider
{
    public function quotaForOwner(string $ownerId): QuotaConfig;

    public function hasQuotaForOwner(string $ownerId): bool;
}
