<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;

/** Shared, secret-free source of AWS readiness, mutation admission and diagnostics. */
final class AwsS3OperationalState
{
    public const string REASON_HEALTHY = 'healthy';
    public const string REASON_PROVIDER = 'provider';
    public const string REASON_CREDENTIALS = 'credentials';
    public const string REASON_CONFIGURATION = 'configuration';
    public const string REASON_CAPACITY = 'capacity';
    public const string REASON_COLLECTOR = 'collector';
    public const string REASON_INTEGRITY = 'integrity';

    public const int STALE_BACKLOG_SECONDS = 900;

    private AwsS3ProviderAdmission $admission;

    private float $lastValidationAt = -INF;

    private bool $collectorHealthy = true;

    private bool $integrityHealthy = true;

    private bool $startupValidated = false;

    /** @var array<string, int|float>|null */
    private ?array $summary = null;

    public function __construct(
        private readonly AwsS3ProviderValidatedBinding $binding,
        private readonly AwsS3ProviderValidator $validator,
        private readonly StorageMutatorInventory $inventory,
        private readonly AwsS3OperationalSummaryReader $metadata,
        private readonly float $revalidationIntervalSeconds = 30.0,
    ) {
        if ($binding->profile === null || $binding->client === null || ! $binding->admission->providerAdmitted) {
            throw new \InvalidArgumentException('AWS operational state requires an admitted exact provider binding.');
        }
        if (! $inventory->isComplete()) {
            throw new \InvalidArgumentException('AWS operational state requires the frozen complete writer inventory.');
        }
        if (! is_finite($revalidationIntervalSeconds) || $revalidationIntervalSeconds < 0.0 || $revalidationIntervalSeconds > 300.0) {
            throw new \InvalidArgumentException('AWS operational revalidation interval must be between 0 and 300 seconds.');
        }
        $this->admission = $binding->admission;
    }

    public function fingerprint(): string
    {
        $profile = $this->profile();
        return hash('sha256', json_encode([
            'provider' => AwsS3ProviderAdmission::PROVIDER_TYPE,
            'bucket' => $profile->bucket,
            'region' => $profile->region,
            'owner' => $profile->expectedOwnerId,
            'data' => $profile->dataPrefix,
            'reserved' => $profile->reservedPrefix,
            'timeouts' => [$profile->connectTimeoutSeconds, $profile->readTimeoutSeconds, $profile->requestTimeoutSeconds],
        ], JSON_THROW_ON_ERROR));
    }

    public function revalidate(bool $force = false): void
    {
        $now = hrtime(true) / 1e9;
        if (! $force && $now - $this->lastValidationAt < $this->revalidationIntervalSeconds) {
            return;
        }
        $this->admission = $this->binding->revalidate($this->validator);
        $this->lastValidationAt = $now;
        $this->collectorHealthy = false;
        $this->summary = null;
        try {
            $this->summary = $this->metadata->getAwsS3OperationalSummary();
            $this->collectorHealthy = true;
        } catch (\Throwable) {
            // Keep admission closed until the next bounded refresh succeeds.
        }
    }

    public function assertStartupReady(): void
    {
        if (! $this->startupValidated) {
            $this->revalidate(true);
            $this->startupValidated = true;
        }
        if (! $this->admission->providerAdmitted || ! $this->inventory->isComplete()) {
            throw new \RuntimeException('AWS provider startup state is not fence-complete.');
        }
    }

    public function assertMutationReady(): void
    {
        $this->revalidate();
        if (! $this->mutationReady()) {
            throw new \RuntimeException('AWS provider mutation admission is closed: ' . $this->reason() . '.');
        }
    }

    public function markCollectorFailure(): void
    {
        $this->collectorHealthy = false;
    }

    public function markCollectorHealthy(): void
    {
        $this->collectorHealthy = true;
    }

    public function markIntegrityConflict(): void
    {
        $this->integrityHealthy = false;
    }

    public function mutationReady(): bool
    {
        return $this->admission->providerAdmitted
            && $this->inventory->isComplete()
            && $this->collectorHealthy
            && $this->integrityHealthy
            && ! $this->capacityExhausted()
            && ! $this->backlogStale();
    }

    public function reason(): string
    {
        if (! $this->admission->configurationValid) {
            return self::REASON_CONFIGURATION;
        }
        if (in_array($this->admission->reason, [
            AwsS3ProviderAdmission::REASON_CLIENT_UNAVAILABLE,
            AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
        ], true)) {
            return self::REASON_CREDENTIALS;
        }
        if (! $this->admission->providerAdmitted) {
            return self::REASON_PROVIDER;
        }
        if (! $this->integrityHealthy) {
            return self::REASON_INTEGRITY;
        }
        if (! $this->collectorHealthy || $this->backlogStale()) {
            return self::REASON_COLLECTOR;
        }
        if ($this->capacityExhausted()) {
            return self::REASON_CAPACITY;
        }
        return self::REASON_HEALTHY;
    }

    /** @return array<string, bool|float|int|string> */
    public function snapshot(bool $force = false): array
    {
        $this->revalidate($force);
        $summary = $this->summary ?? [];
        return [
            'provider_type' => AwsS3ProviderAdmission::PROVIDER_TYPE,
            'fingerprint' => $this->fingerprint(),
            'configuration_valid' => $this->admission->configurationValid,
            'provider_admitted' => $this->admission->providerAdmitted,
            'fence_complete' => $this->inventory->isComplete(),
            'production_qualified' => false,
            'mutation_ready' => $this->mutationReady(),
            'reason' => $this->reason(),
            'open' => (int) ($summary['open'] ?? 0),
            'ambiguous' => (int) ($summary['ambiguous'] ?? 0),
            'seal_reserved' => (int) ($summary['seal_reserved'] ?? 0),
            'sealed' => (int) ($summary['sealed'] ?? 0),
            'multipart' => (int) ($summary['multipart'] ?? 0),
            'part' => (int) ($summary['part'] ?? 0),
            'cleanup' => (int) ($summary['cleanup'] ?? 0),
            'failures' => (int) ($summary['failures'] ?? 0),
            'oldest_age_seconds' => (float) ($summary['oldest_age_seconds'] ?? 0.0),
            'max_owner_open' => (int) ($summary['max_owner_open'] ?? 0),
            'max_owner_sealed' => (int) ($summary['max_owner_sealed'] ?? 0),
            'max_owner_part' => (int) ($summary['max_owner_part'] ?? 0),
        ];
    }

    private function profile(): AwsS3ProviderProfile
    {
        return $this->binding->profile
            ?? throw new \LogicException('AWS operational profile binding disappeared.');
    }

    private function backlogStale(): bool
    {
        return (float) ($this->summary['oldest_age_seconds'] ?? 0.0) >= self::STALE_BACKLOG_SECONDS;
    }

    private function capacityExhausted(): bool
    {
        $summary = $this->summary;
        return $summary !== null && (
            (int) $summary['open'] >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL
            || (int) $summary['seal_reserved'] >= ObjectWriteIntentStore::MAX_SEALS_GLOBAL
            || (int) $summary['part'] >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS
            || (int) $summary['cleanup'] >= StorageCleanupCapacityReader::RECOVERY_LIMIT
        );
    }
}
